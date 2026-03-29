<?php
/**
 * Few-shot example cache for AI prompt improvement
 *
 * Syncs editor-corrected stories from the Babbel API with their original WordPress
 * source content to build few-shot examples for OpenAI speech text generation.
 *
 * @package KnabbelWP
 * @since   0.3.0
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Action Scheduler hook for nightly few-shot sync.
 *
 * @since 0.3.0
 */
function few_shot_register_hook(): void {
	add_action( 'knabbel_sync_few_shot_examples', __NAMESPACE__ . '\\sync_few_shot_examples' );
}

/**
 * Schedule the nightly few-shot example sync.
 *
 * Uses Action Scheduler for reliable recurring execution.
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
 * Unschedule the few-shot sync and clean up cached data.
 *
 * @since 0.3.0
 */
function few_shot_unschedule_sync(): void {
	\as_unschedule_all_actions( 'knabbel_sync_few_shot_examples', array(), 'zw-knabbel-wp' );
	delete_option( 'knabbel_few_shot_examples' );
}

/**
 * Sync few-shot examples from Babbel API.
 *
 * Fetches recent editor-reviewed stories, matches them with their original
 * WordPress posts, calculates edit-intensity scores, and caches the best
 * examples for use in OpenAI prompts.
 *
 * @since 0.3.0
 */
function sync_few_shot_examples(): void {
	$options   = get_option( 'knabbel_settings' );
	$max_count = (int) ( $options['few_shot_count'] ?? 5 );

	if ( $max_count <= 0 ) {
		log( 'info', 'FewShotCache', 'Few-shot examples disabled (count set to 0)' );
		delete_option( 'knabbel_few_shot_examples' );
		return;
	}

	$stories = babbel_fetch_recent_stories( 20 );

	if ( is_wp_error( $stories ) ) {
		log(
			'error',
			'FewShotCache',
			'Failed to fetch stories for few-shot cache',
			array( 'error' => $stories->get_error_message() )
		);
		return;
	}

	if ( empty( $stories ) ) {
		log( 'info', 'FewShotCache', 'No eligible stories found for few-shot examples' );
		return;
	}

	$candidates = build_few_shot_candidates( $stories );

	if ( empty( $candidates ) ) {
		log( 'info', 'FewShotCache', 'No valid few-shot candidates after matching and scoring' );
		return;
	}

	$selected = select_diverse_examples( $candidates, $max_count );

	// Sort by edit-score ascending so the strongest example is last (closest to the actual prompt).
	usort(
		$selected,
		static function ( array $a, array $b ): int {
			return $a['edit_score'] <=> $b['edit_score'];
		}
	);

	// Store only the data needed for prompts.
	$examples = array_map(
		static function ( array $item ): array {
			return array(
				'input'      => $item['input'],
				'output'     => $item['output'],
				'edit_score' => $item['edit_score'],
				'word_count' => $item['word_count'],
			);
		},
		$selected
	);

	update_option( 'knabbel_few_shot_examples', $examples, false );

	log(
		'info',
		'FewShotCache',
		'Few-shot examples cache updated',
		array(
			'examples_cached'  => count( $examples ),
			'candidates_found' => count( $candidates ),
			'edit_score_range' => count( $examples ) > 0
				? round( $examples[0]['edit_score'], 1 ) . '%-' . round( end( $examples )['edit_score'], 1 ) . '%'
				: 'n/a',
		)
	);
}

/**
 * Build few-shot candidates by matching Babbel stories with WordPress posts.
 *
 * @since 0.3.0
 * @param array<int, array<string, mixed>> $stories Stories from the Babbel API.
 * @return array<int, array{input: string, output: string, edit_score: float, word_count: int}> Candidate examples with scores.
 */
function build_few_shot_candidates( array $stories ): array {
	$candidates = array();

	foreach ( $stories as $story ) {
		$babbel_text = trim( (string) ( $story['text'] ?? '' ) );

		if ( empty( $babbel_text ) ) {
			continue;
		}

		// Get the WordPress post ID from story metadata.
		$wp_post_id = (int) ( $story['metadata']['wordpress_id'] ?? 0 );

		if ( ! $wp_post_id ) {
			continue;
		}

		$post = get_post( $wp_post_id );

		if ( ! $post || empty( $post->post_content ) ) {
			continue;
		}

		// Get the original AI-generated speech text from post meta.
		$state               = get_story_state( $wp_post_id );
		$ai_generated_speech = trim( (string) ( $state['generated_speech_text'] ?? '' ) );

		if ( empty( $ai_generated_speech ) ) {
			continue;
		}

		// Calculate edit-intensity score.
		$edit_score = calculate_edit_score( $ai_generated_speech, $babbel_text );

		// Skip unmodified stories (score 0% = editor didn't change anything).
		if ( $edit_score < 1.0 ) {
			continue;
		}

		$input      = wp_strip_all_tags( $post->post_content );
		$word_count = str_word_count( $input );

		if ( $word_count < 10 ) {
			continue;
		}

		$candidates[] = array(
			'input'      => $input,
			'output'     => $babbel_text,
			'edit_score' => $edit_score,
			'word_count' => $word_count,
		);
	}

	return $candidates;
}

/**
 * Calculate edit-intensity score between AI-generated and editor-corrected text.
 *
 * Uses similar_text() to measure the percentage of difference.
 * A score of 0% means identical, 100% means completely rewritten.
 *
 * @since 0.3.0
 * @param string $ai_text     The original AI-generated text.
 * @param string $editor_text The editor-corrected text from Babbel.
 * @return float Percentage of difference (0.0 to 100.0).
 */
function calculate_edit_score( string $ai_text, string $editor_text ): float {
	if ( $ai_text === $editor_text ) {
		return 0.0;
	}

	$similarity_percent = 0.0;
	similar_text( $ai_text, $editor_text, $similarity_percent );

	// Invert: similar_text returns similarity %, we want difference %.
	return 100.0 - $similarity_percent;
}

/**
 * Select diverse examples ensuring a mix of short and long articles.
 *
 * Splits candidates into short and long halves by word count, then picks
 * the highest-scoring examples from each half alternately.
 *
 * @since 0.3.0
 * @param array<int, array{input: string, output: string, edit_score: float, word_count: int}> $candidates All valid candidates.
 * @param int                                                                                  $max_count  Maximum examples to select.
 * @return array<int, array{input: string, output: string, edit_score: float, word_count: int}> Selected examples.
 */
function select_diverse_examples( array $candidates, int $max_count ): array {
	if ( count( $candidates ) <= $max_count ) {
		return $candidates;
	}

	// Sort by word count to split into short/long halves.
	usort(
		$candidates,
		static function ( array $a, array $b ): int {
			return $a['word_count'] <=> $b['word_count'];
		}
	);

	$midpoint = (int) ceil( count( $candidates ) / 2 );
	$short    = array_slice( $candidates, 0, $midpoint );
	$long     = array_slice( $candidates, $midpoint );

	// Sort each half by edit-score descending (best first).
	$sort_by_score = static function ( array $a, array $b ): int {
		return $b['edit_score'] <=> $a['edit_score'];
	};

	usort( $short, $sort_by_score );
	usort( $long, $sort_by_score );

	// Alternate picks from short and long, starting with short.
	$selected    = array();
	$s           = 0;
	$l           = 0;
	$short_count = count( $short );
	$long_count  = count( $long );

	$selected_count = 0;

	while ( $selected_count < $max_count ) {
		if ( $s < $short_count ) {
			$selected[] = $short[ $s ];
			++$s;
			++$selected_count;
		}

		if ( $selected_count >= $max_count ) {
			break;
		}

		if ( $l < $long_count ) {
			$selected[] = $long[ $l ];
			++$l;
			++$selected_count;
		}

		// Safety: break if both pools are exhausted.
		if ( $s >= $short_count && $l >= $long_count ) {
			break;
		}
	}

	return $selected;
}
