<?php
/**
 * Few-shot example caching and selection for AI prompt improvement.
 *
 * @package KnabbelWP
 * @since   0.3.0
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KNABBEL_FEW_SHOT_MAX_AGE_MONTHS = 3;
const KNABBEL_FEW_SHOT_COUNT          = 8;

/**
 * Registers the nightly synchronization hook.
 *
 * @since 0.3.0
 */
function few_shot_register_hook(): void {
	add_action( 'knabbel_sync_few_shot_examples', __NAMESPACE__ . '\\sync_few_shot_examples' );
}

/**
 * Schedules the nightly example synchronization.
 *
 * @since 0.3.0
 */
function few_shot_schedule_sync(): void {
	if ( false === \as_has_scheduled_action( 'knabbel_sync_few_shot_examples', array(), 'zw-knabbel-wp' ) ) {
		\as_schedule_recurring_action(
			strtotime( 'tomorrow 03:00' ),
			DAY_IN_SECONDS,
			'knabbel_sync_few_shot_examples',
			array(),
			'zw-knabbel-wp'
		);
	}
}

/**
 * Cancels synchronization and clears cached examples.
 *
 * @since 0.3.0
 */
function few_shot_unschedule_sync(): void {
	// Hook-only takes the bulk-cancel path; passing args or a group forces an exact args match.
	\as_unschedule_all_actions( 'knabbel_sync_few_shot_examples' );
	delete_option( 'knabbel_few_shot_examples' );
}

/**
 * Refreshes the recent few-shot candidate pool from Babbel.
 *
 * Final selection happens for each AI request so the current WordPress post can
 * be excluded without reducing the configured example count.
 *
 * @since 0.3.0
 */
function sync_few_shot_examples(): void {
	$cutoff  = (int) strtotime( sprintf( '-%d months', KNABBEL_FEW_SHOT_MAX_AGE_MONTHS ) );
	$stories = babbel_fetch_recent_stories( gmdate( DATE_ATOM, $cutoff ) );

	if ( is_wp_error( $stories ) ) {
		log(
			'error',
			'FewShotCache',
			'Failed to fetch stories for few-shot cache',
			array( 'error' => $stories->get_error_message() )
		);
		return;
	}

	list( $accepted, $edited ) = partition_few_shot_candidates( build_few_shot_candidates( $stories, $cutoff ) );

	// Keep one spare per pool: per-post exclusion removes at most one entry,
	// so a full selection stays possible even when one pool must supply it alone.
	$accepted_pool = select_diverse_examples( $accepted, KNABBEL_FEW_SHOT_COUNT + 1 );
	$edited_pool   = select_diverse_examples( $edited, KNABBEL_FEW_SHOT_COUNT + 1 );
	$pool          = array_merge( $accepted_pool, $edited_pool );

	if ( empty( $pool ) ) {
		log( 'info', 'FewShotCache', 'No valid recent few-shot candidates found' );
		delete_option( 'knabbel_few_shot_examples' );
		return;
	}

	// Store the candidate pool without autoloading it. Selection is post-specific.
	update_option( 'knabbel_few_shot_examples', $pool, false );

	log(
		'info',
		'FewShotCache',
		'Few-shot candidate cache updated',
		array(
			'accepted_cached' => count( $accepted_pool ),
			'edited_cached'   => count( $edited_pool ),
		)
	);
}

/**
 * Matches recent Babbel stories with their WordPress sources.
 *
 * @since 0.3.0
 * @param array<int, array<string, mixed>> $stories                Stories from the Babbel API.
 * @param int                              $created_after_timestamp Oldest allowed creation timestamp.
 * @return array<int, array<string, mixed>> Candidate examples.
 *
 * @phpstan-return list<FewShotCandidate>
 */
function build_few_shot_candidates( array $stories, int $created_after_timestamp ): array {
	$candidates    = array();
	$seen_post_ids = array();

	// Warm the post cache in one query instead of one query per get_post() call below.
	$post_ids = array_filter( array_map( static fn ( array $story ): int => (int) ( $story['metadata']['wordpress_id'] ?? 0 ), $stories ) );
	if ( array() !== $post_ids ) {
		_prime_post_caches( array_values( $post_ids ), false, false );
	}

	foreach ( $stories as $story ) {
		$created_at = $story['created_at'] ?? null;
		$created    = is_string( $created_at ) ? strtotime( $created_at ) : false;
		if ( false === $created || $created < $created_after_timestamp ) {
			continue;
		}

		$output          = normalize_few_shot_text( (string) ( $story['text'] ?? '' ) );
		$wp_post_id      = (int) ( $story['metadata']['wordpress_id'] ?? 0 );
		$original_speech = normalize_few_shot_text( (string) ( $story['metadata']['original_speech_text'] ?? '' ) );

		if ( '' === $output || $wp_post_id <= 0 || '' === $original_speech || isset( $seen_post_ids[ $wp_post_id ] ) ) {
			continue;
		}

		$output_word_count = few_shot_count_words( $output );
		$sentence_count    = few_shot_count_sentences( $output );
		if ( $output_word_count < 20 || $sentence_count < 2 ) {
			continue;
		}

		$post = get_post( $wp_post_id );
		if ( ! $post || '' === trim( $post->post_content ) ) {
			continue;
		}

		$input = normalize_few_shot_text( wp_strip_all_tags( $post->post_content ) );
		if ( '' === $input ) {
			continue;
		}

		$edit_score                   = calculate_edit_score( $original_speech, $output );
		$candidates[]                 = array(
			'post_id'             => $wp_post_id,
			'input'               => $input,
			'output'              => $output,
			'edit_score'          => $edit_score,
			'original_word_count' => few_shot_count_words( $original_speech ),
			'output_word_count'   => $output_word_count,
			'sentence_count'      => $sentence_count,
			'provenance'          => 0.0 === $edit_score ? 'accepted' : 'edited',
		);
		$seen_post_ids[ $wp_post_id ] = true;
	}

	return $candidates;
}

/**
 * Normalizes whitespace for metrics and comparison.
 *
 * @since 0.7.0
 * @param string $text Text to normalize.
 * @return string Normalized text.
 */
function normalize_few_shot_text( string $text ): string {
	$normalized = preg_replace( '/\s+/u', ' ', trim( $text ) );
	return is_string( $normalized ) ? $normalized : trim( $text );
}

/**
 * Counts whitespace-separated words containing a letter or number.
 *
 * @since 0.7.0
 * @param string $text Text to count.
 * @return int Word count.
 */
function few_shot_count_words( string $text ): int {
	$tokens = preg_split( '/\s+/u', normalize_few_shot_text( $text ), -1, PREG_SPLIT_NO_EMPTY );
	if ( false === $tokens ) {
		return 0;
	}

	return count(
		array_filter(
			$tokens,
			static fn ( string $token ): bool => 1 === preg_match( '/[\p{L}\p{N}]/u', $token )
		)
	);
}

/**
 * Counts sentence-like units using the same boundary as the reference script.
 *
 * @since 0.7.0
 * @param string $text Text to inspect.
 * @return int Sentence count.
 */
function few_shot_count_sentences( string $text ): int {
	$normalized = normalize_few_shot_text( $text );
	if ( '' === $normalized ) {
		return 0;
	}

	$sentences = preg_split( '/(?<=[.!?])\s+(?=(?:["\'“‘]?\p{Lu}|\d))/u', $normalized, -1, PREG_SPLIT_NO_EMPTY );
	return is_array( $sentences ) ? count( $sentences ) : 1;
}

/**
 * Measures normalized change from AI output to Babbel text.
 *
 * Distance and length share the same unit (bytes of the normalized,
 * lowercased UTF-8 strings), so the score is always within 0-100%.
 *
 * @since 0.3.0
 * @param string $ai_text     Original AI-generated text.
 * @param string $editor_text Text stored in Babbel.
 * @return float Percentage of difference (0.0 to 100.0).
 */
function calculate_edit_score( string $ai_text, string $editor_text ): float {
	$left  = normalize_few_shot_text( $ai_text );
	$right = normalize_few_shot_text( $editor_text );
	$left  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $left, 'UTF-8' ) : strtolower( $left );
	$right = function_exists( 'mb_strtolower' ) ? mb_strtolower( $right, 'UTF-8' ) : strtolower( $right );
	if ( $left === $right ) {
		return 0.0;
	}

	return ( levenshtein( $left, $right ) / max( strlen( $left ), strlen( $right ) ) ) * 100;
}

/**
 * Validates one cached candidate against the shape the nightly sync writes.
 *
 * Entries from older releases lack a post ID and are rejected; the next
 * nightly sync replaces them with fully-shaped candidates.
 *
 * @since 0.7.0
 * @param array<string, mixed> $candidate Cached candidate.
 * @return array<string, mixed>|null Normalized candidate or null when invalid.
 *
 * @phpstan-return FewShotCandidate|null
 */
function normalize_few_shot_candidate( array $candidate ): ?array {
	$input      = $candidate['input'] ?? '';
	$output     = $candidate['output'] ?? '';
	$post_id    = isset( $candidate['post_id'] ) ? (int) $candidate['post_id'] : 0;
	$provenance = $candidate['provenance'] ?? null;

	if ( ! is_string( $input ) || '' === trim( $input ) || ! is_string( $output ) || '' === trim( $output ) || $post_id <= 0
		|| ! isset( $candidate['edit_score'] ) || ! is_numeric( $candidate['edit_score'] )
		|| ! isset( $candidate['original_word_count'], $candidate['output_word_count'], $candidate['sentence_count'] )
		|| ! in_array( $provenance, array( 'accepted', 'edited' ), true )
	) {
		return null;
	}

	return array(
		'post_id'             => $post_id,
		'input'               => $input,
		'output'              => $output,
		'edit_score'          => (float) $candidate['edit_score'],
		'original_word_count' => (int) $candidate['original_word_count'],
		'output_word_count'   => (int) $candidate['output_word_count'],
		'sentence_count'      => (int) $candidate['sentence_count'],
		'provenance'          => $provenance,
	);
}

/**
 * Sorts candidates by one numeric diversity dimension.
 *
 * @since 0.7.0
 * @param array<int, array<string, mixed>> $candidates Candidate examples.
 * @param string                           $field      Numeric field.
 * @return array<int, array<string, mixed>> Sorted candidates.
 *
 * @phpstan-param list<FewShotCandidate> $candidates
 * @phpstan-return list<FewShotCandidate>
 */
function sort_few_shot_candidates( array $candidates, string $field ): array {
	usort(
		$candidates,
		static fn ( array $left, array $right ): int => $left[ $field ] <=> $right[ $field ]
	);

	return $candidates;
}

/**
 * Selects examples spanning output length, edit score, source length and sentences.
 *
 * @since 0.3.0
 * @param array<int, array<string, mixed>> $candidates Candidate examples.
 * @param int                              $max_count  Maximum examples to select.
 * @return array<int, array<string, mixed>> Selected examples.
 *
 * @phpstan-param list<FewShotCandidate> $candidates
 * @phpstan-return list<FewShotCandidate>
 */
function select_diverse_examples( array $candidates, int $max_count ): array {
	if ( $max_count <= 0 ) {
		return array();
	}

	if ( count( $candidates ) <= $max_count ) {
		return $candidates;
	}

	$rankings = array();
	foreach ( array( 'output_word_count', 'edit_score', 'original_word_count', 'sentence_count' ) as $field ) {
		$ascending  = sort_few_shot_candidates( $candidates, $field );
		$rankings[] = $ascending;
		$rankings[] = array_reverse( $ascending );
	}
	$selected = array();
	$seen     = array();

	foreach ( $rankings as $ranking ) {
		foreach ( $ranking as $candidate ) {
			if ( isset( $seen[ $candidate['post_id'] ] ) ) {
				continue;
			}

			$selected[]                    = $candidate;
			$seen[ $candidate['post_id'] ] = true;
			break;
		}

		if ( count( $selected ) >= $max_count ) {
			break;
		}
	}

	foreach ( $candidates as $candidate ) {
		if ( count( $selected ) >= $max_count ) {
			break;
		}

		if ( ! isset( $seen[ $candidate['post_id'] ] ) ) {
			$selected[]                    = $candidate;
			$seen[ $candidate['post_id'] ] = true;
		}
	}

	return $selected;
}

/**
 * Splits candidates into directly accepted and meaningfully edited pools.
 *
 * Edited candidates under a 1% edit score are dropped: after whitespace and
 * case normalization such texts teach nothing an accepted example does not.
 *
 * @since 0.7.0
 * @param array<int, array<string, mixed>> $candidates Candidate examples.
 * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} Accepted and edited pools.
 *
 * @phpstan-param list<FewShotCandidate> $candidates
 * @phpstan-return array{0: list<FewShotCandidate>, 1: list<FewShotCandidate>}
 */
function partition_few_shot_candidates( array $candidates ): array {
	$accepted = array();
	$edited   = array();

	foreach ( $candidates as $candidate ) {
		if ( 'accepted' === $candidate['provenance'] ) {
			$accepted[] = $candidate;
		} elseif ( $candidate['edit_score'] >= 1.0 ) {
			$edited[] = $candidate;
		}
	}

	return array( $accepted, $edited );
}

/**
 * Selects roughly 40% directly accepted and 60% editor-adjusted examples.
 *
 * Eight examples yield the intended three accepted and five edited examples
 * when both pools contain enough candidates.
 *
 * @since 0.7.0
 * @param array<int, array<string, mixed>> $candidates Candidate examples.
 * @param int                              $max_count  Maximum examples to select.
 * @return array<int, array<string, mixed>> Selected examples.
 *
 * @phpstan-param list<FewShotCandidate> $candidates
 * @phpstan-return list<FewShotCandidate>
 */
function select_example_mix( array $candidates, int $max_count ): array {
	if ( $max_count <= 0 ) {
		return array();
	}

	list( $accepted, $edited ) = partition_few_shot_candidates( $candidates );

	$accepted_target = min( count( $accepted ), max( 1, (int) round( $max_count * 0.4 ) ) );
	$selected        = array_merge(
		select_diverse_examples( $accepted, $accepted_target ),
		select_diverse_examples( $edited, $max_count - $accepted_target )
	);

	if ( count( $selected ) < $max_count ) {
		$selected_ids = array_fill_keys( array_column( $selected, 'post_id' ), true );
		$remaining    = array_values(
			array_filter(
				array_merge( $accepted, $edited ),
				static fn ( array $candidate ): bool => ! isset( $selected_ids[ $candidate['post_id'] ] )
			)
		);
		$selected     = array_merge( $selected, select_diverse_examples( $remaining, $max_count - count( $selected ) ) );
	}

	usort(
		$selected,
		static fn ( array $left, array $right ): int => $left['output_word_count'] <=> $right['output_word_count']
	);

	return $selected;
}

/**
 * Returns the per-post example mix from the cached candidate pool.
 *
 * @since 0.7.0
 * @param int $excluded_post_id Current WordPress post ID, if any.
 * @return array<int, array<string, mixed>> Selected examples.
 *
 * @phpstan-return list<FewShotCandidate>
 */
function get_few_shot_examples( int $excluded_post_id = 0 ): array {
	$cached = get_option( 'knabbel_few_shot_examples', array() );
	if ( ! is_array( $cached ) ) {
		return array();
	}

	$candidates = array();
	foreach ( $cached as $candidate ) {
		if ( ! is_array( $candidate ) ) {
			continue;
		}

		$normalized = normalize_few_shot_candidate( $candidate );
		if ( null === $normalized || $normalized['post_id'] === $excluded_post_id ) {
			continue;
		}
		$candidates[] = $normalized;
	}

	return select_example_mix( $candidates, KNABBEL_FEW_SHOT_COUNT );
}
