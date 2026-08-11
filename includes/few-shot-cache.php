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
	$created_after = new \DateTimeImmutable(
		sprintf( '-%d months', KNABBEL_FEW_SHOT_MAX_AGE_MONTHS ),
		new \DateTimeZone( 'UTC' )
	);
	$stories       = babbel_fetch_recent_stories( BABBEL_RECENT_STORY_LIMIT, $created_after->format( DATE_ATOM ) );

	if ( is_wp_error( $stories ) ) {
		log(
			'error',
			'FewShotCache',
			'Failed to fetch stories for few-shot cache',
			array( 'error' => $stories->get_error_message() )
		);
		return;
	}

	$candidates = build_few_shot_candidates( $stories, $created_after->getTimestamp() );

	if ( empty( $candidates ) ) {
		log( 'info', 'FewShotCache', 'No valid recent few-shot candidates found' );
		delete_option( 'knabbel_few_shot_examples' );
		return;
	}

	// Store the candidate pool without autoloading it. Selection is post-specific.
	update_option( 'knabbel_few_shot_examples', $candidates, false );

	$accepted_count = count(
		array_filter(
			$candidates,
			static fn ( array $candidate ): bool => 'accepted' === $candidate['provenance']
		)
	);

	log(
		'info',
		'FewShotCache',
		'Few-shot candidate cache updated',
		array(
			'candidates_found' => count( $candidates ),
			'accepted_found'   => $accepted_count,
			'edited_found'     => count( $candidates ) - $accepted_count,
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
 * Measures normalized character-level change from AI output to Babbel text.
 *
 * @since 0.3.0
 * @param string $ai_text     Original AI-generated text.
 * @param string $editor_text Text stored in Babbel.
 * @return float Percentage of difference (0.0 to 100.0).
 */
function calculate_edit_score( string $ai_text, string $editor_text ): float {
	$left_text  = normalize_few_shot_text( $ai_text );
	$right_text = normalize_few_shot_text( $editor_text );
	$left_text  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $left_text, 'UTF-8' ) : strtolower( $left_text );
	$right_text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $right_text, 'UTF-8' ) : strtolower( $right_text );
	if ( $left_text === $right_text ) {
		return 0.0;
	}

	$left  = preg_split( '//u', $left_text, -1, PREG_SPLIT_NO_EMPTY );
	$right = preg_split( '//u', $right_text, -1, PREG_SPLIT_NO_EMPTY );
	$left  = is_array( $left ) ? $left : str_split( $left_text );
	$right = is_array( $right ) ? $right : str_split( $right_text );

	$distance = few_shot_levenshtein_distance( $left, $right );
	return min( 100.0, ( $distance / max( count( $left ), count( $right ) ) ) * 100 );
}

/**
 * Calculates a character-level Levenshtein distance.
 *
 * Common article text uses fewer than 256 distinct characters. Mapping those
 * characters to bytes lets PHP's native implementation perform the expensive
 * loop in C while preserving Unicode character semantics. Text pairs with a
 * larger character set fall back to a byte-level approximation; the caller
 * caps the resulting score at 100%.
 *
 * @since 0.7.0
 * @param list<string> $left  Left-side characters.
 * @param list<string> $right Right-side characters.
 * @return int Edit distance.
 */
function few_shot_levenshtein_distance( array $left, array $right ): int {
	$symbols     = array();
	$next_symbol = 0;
	$encode      = static function ( array $characters ) use ( &$symbols, &$next_symbol ): ?string {
		$encoded = '';
		foreach ( $characters as $character ) {
			if ( ! isset( $symbols[ $character ] ) ) {
				if ( $next_symbol >= 256 ) {
					return null;
				}
				$symbols[ $character ] = chr( $next_symbol );
				++$next_symbol;
			}
			$encoded .= $symbols[ $character ];
		}
		return $encoded;
	};

	$encoded_left  = $encode( $left );
	$encoded_right = null !== $encoded_left ? $encode( $right ) : null;
	if ( null !== $encoded_left && null !== $encoded_right ) {
		return levenshtein( $encoded_left, $encoded_right );
	}

	return levenshtein( implode( '', $left ), implode( '', $right ) );
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
	$input      = isset( $candidate['input'] ) && is_string( $candidate['input'] ) ? normalize_few_shot_text( $candidate['input'] ) : '';
	$output     = isset( $candidate['output'] ) && is_string( $candidate['output'] ) ? normalize_few_shot_text( $candidate['output'] ) : '';
	$post_id    = isset( $candidate['post_id'] ) ? (int) $candidate['post_id'] : 0;
	$provenance = $candidate['provenance'] ?? null;

	if ( '' === $input || '' === $output || $post_id <= 0
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
 * @param bool                             $descending Whether to sort high to low.
 * @return array<int, array<string, mixed>> Sorted candidates.
 *
 * @phpstan-param list<FewShotCandidate> $candidates
 * @phpstan-return list<FewShotCandidate>
 */
function sort_few_shot_candidates( array $candidates, string $field, bool $descending = false ): array {
	usort(
		$candidates,
		static function ( array $left, array $right ) use ( $field, $descending ): int {
			$result = $left[ $field ] <=> $right[ $field ];
			return $descending ? -$result : $result;
		}
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

	$rankings = array(
		sort_few_shot_candidates( $candidates, 'output_word_count' ),
		sort_few_shot_candidates( $candidates, 'output_word_count', true ),
		sort_few_shot_candidates( $candidates, 'edit_score' ),
		sort_few_shot_candidates( $candidates, 'edit_score', true ),
		sort_few_shot_candidates( $candidates, 'original_word_count' ),
		sort_few_shot_candidates( $candidates, 'original_word_count', true ),
		sort_few_shot_candidates( $candidates, 'sentence_count' ),
		sort_few_shot_candidates( $candidates, 'sentence_count', true ),
	);
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

	$accepted = array_values(
		array_filter(
			$candidates,
			static fn ( array $candidate ): bool => 'accepted' === $candidate['provenance']
		)
	);
	$edited   = array_values(
		array_filter(
			$candidates,
			static fn ( array $candidate ): bool => 'edited' === $candidate['provenance'] && $candidate['edit_score'] >= 1.0
		)
	);

	$accepted_target = min( count( $accepted ), max( 1, (int) round( $max_count * 0.4 ) ) );
	$selected        = array_merge(
		select_diverse_examples( $accepted, $accepted_target ),
		select_diverse_examples( $edited, $max_count - $accepted_target )
	);

	if ( count( $selected ) < $max_count ) {
		$selected_ids = array_fill_keys( array_column( $selected, 'post_id' ), true );
		$remaining    = array_values(
			array_filter(
				$candidates,
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
		if ( null === $normalized || ( $excluded_post_id > 0 && $normalized['post_id'] === $excluded_post_id ) ) {
			continue;
		}
		$candidates[] = $normalized;
	}

	return select_example_mix( $candidates, KNABBEL_FEW_SHOT_COUNT );
}
