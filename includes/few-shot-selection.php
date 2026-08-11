<?php
/**
 * Pure few-shot selection and scoring logic.
 *
 * Deliberately free of WordPress dependencies so tests/php/few-shot-selection.php
 * can execute it standalone; this file defines constants and functions only.
 *
 * @package KnabbelWP
 * @since   0.7.0
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) {
	exit;
}

const KNABBEL_FEW_SHOT_COUNT = 8;
// Share of directly accepted examples in the mix; 8 examples yield 3 accepted and 5 edited.
const KNABBEL_FEW_SHOT_ACCEPTED_RATIO = 0.4;
// Edit scores below this percentage count as directly accepted.
const KNABBEL_FEW_SHOT_ACCEPTED_MAX_SCORE = 1.0;

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
	$tokens = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	return false === $tokens ? 0 : count(
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
	$text      = trim( $text );
	$sentences = '' === $text ? array() : preg_split( '/(?<=[.!?])\s+(?=(?:["\'“‘]?\p{Lu}|\d))/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	return is_array( $sentences ) ? count( $sentences ) : 1;
}

/**
 * Measures normalized change from AI output to Babbel text.
 *
 * Uses byte-level distance, sufficient for provenance and coarse ranking.
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
 * Rejects incomplete values left by older cache formats.
 *
 * @since 0.7.0
 * @param array<string, mixed> $candidate Cached candidate.
 * @return array<string, mixed>|null Normalized candidate or null when invalid.
 *
 * @phpstan-return FewShotCandidate|null
 */
function normalize_few_shot_candidate( array $candidate ): ?array {
	$input      = $candidate['input'] ?? null;
	$output     = $candidate['output'] ?? null;
	$post_id    = isset( $candidate['post_id'] ) ? (int) $candidate['post_id'] : 0;
	$provenance = $candidate['provenance'] ?? null;

	if ( ! is_string( $input ) || '' === trim( $input )
		|| ! is_string( $output ) || '' === trim( $output )
		|| $post_id <= 0
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

	$remaining = $candidates;
	if ( count( $remaining ) <= $max_count ) {
		return $candidates;
	}

	$order    = array_flip( array_column( $remaining, 'post_id' ) );
	$selected = array();
	foreach ( array( 'output_word_count', 'edit_score', 'original_word_count', 'sentence_count' ) as $field ) {
		foreach ( array( 1, -1 ) as $direction ) {
			usort(
				$remaining,
				static function ( array $left, array $right ) use ( $direction, $field, $order ): int {
					$result = $left[ $field ] <=> $right[ $field ];
					return 0 === $result ? $order[ $left['post_id'] ] <=> $order[ $right['post_id'] ] : $direction * $result;
				}
			);
			$selected[] = array_shift( $remaining );
			if ( count( $selected ) === $max_count ) {
				return $selected;
			}
		}
	}

	usort( $remaining, static fn ( array $left, array $right ): int => $order[ $left['post_id'] ] <=> $order[ $right['post_id'] ] );
	return array_merge( $selected, array_slice( $remaining, 0, $max_count - count( $selected ) ) );
}

/**
 * Selects roughly 40% directly accepted and 60% editor-adjusted examples.
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

	$accepted = array();
	$edited   = array();
	foreach ( $candidates as $candidate ) {
		if ( 'accepted' === $candidate['provenance'] ) {
			$accepted[] = $candidate;
		} elseif ( $candidate['edit_score'] >= KNABBEL_FEW_SHOT_ACCEPTED_MAX_SCORE ) {
			$edited[] = $candidate;
		}
	}

	$accepted_target = min( count( $accepted ), max( 1, (int) round( $max_count * KNABBEL_FEW_SHOT_ACCEPTED_RATIO ) ) );
	$edited_target   = min( count( $edited ), $max_count - $accepted_target );
	$accepted_target = min( count( $accepted ), $max_count - $edited_target );
	$selected        = array_merge(
		select_diverse_examples( $accepted, $accepted_target ),
		select_diverse_examples( $edited, $edited_target )
	);

	usort(
		$selected,
		static fn ( array $left, array $right ): int => $left['output_word_count'] <=> $right['output_word_count']
	);

	return $selected;
}
