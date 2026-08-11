<?php
/**
 * Fast regression tests for few-shot selection.
 *
 * @package KnabbelWP
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require_once dirname( __DIR__, 2 ) . '/includes/few-shot-selection.php';

( static function (): void {
	$ensure = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	};

	$candidates = array();
	for ( $index = 1; $index <= 14; ++$index ) {
		$accepted     = $index <= 6;
		$candidates[] = array(
			'post_id'             => $index,
			'input'               => "Bronartikel {$index}",
			'output'              => "Radiotekst {$index}. Tweede zin voor voorbeeld {$index}.",
			'edit_score'          => $accepted ? 0.0 : (float) ( $index * 3 ),
			'original_word_count' => 25 + ( $index * 5 ),
			'output_word_count'   => 20 + ( $index * 3 ),
			'sentence_count'      => 2 + ( $index % 5 ),
			'provenance'          => $accepted ? 'accepted' : 'edited',
		);
	}

	$selected       = KnabbelWP\select_example_mix( $candidates, 8 );
	$accepted_count = count(
		array_filter(
			$selected,
			static fn ( array $candidate ): bool => 'accepted' === $candidate['provenance']
		)
	);

	$ensure( 8 === count( $selected ), 'Selection must return eight examples.' );
	$ensure( 3 === $accepted_count, 'Eight examples must contain three directly accepted texts.' );
	$ensure( 5 === count( $selected ) - $accepted_count, 'Eight examples must contain five edited texts.' );
	$ensure(
		KnabbelWP\select_example_mix( $candidates, 8 ) === $selected,
		'Selection must be deterministic.'
	);
	$ensure(
		0.0 === KnabbelWP\calculate_edit_score( " Zelfde\ntekst ", 'zelfde tekst' ),
		'Whitespace and case must not turn an accepted text into an edited text.'
	);
	$ensure(
		0.0 < KnabbelWP\calculate_edit_score( 'Eerste tekst.', 'Volledig andere tekst.' ),
		'Changed text must receive a positive edit score.'
	);
	$ensure(
		100.0 === KnabbelWP\calculate_edit_score( 'e', 'é' ),
		'A fully replaced character must score 100.'
	);
	$ensure(
		count( KnabbelWP\select_example_mix( array_slice( $candidates, 0, 5 ), 8 ) ) === 5,
		'Selection must return all valid examples when fewer than eight are available.'
	);
	$weak_edited               = $candidates[6];
	$weak_edited['post_id']    = 99;
	$weak_edited['edit_score'] = 0.5;
	$ensure(
		2 === count( KnabbelWP\select_example_mix( array( $candidates[0], $candidates[6], $weak_edited ), 8 ) ),
		'Fallback selection must not reintroduce edited examples below the minimum edit score.'
	);
	$ensure(
		array() === KnabbelWP\select_example_mix( $candidates, 0 ),
		'A zero example count must select nothing.'
	);
	$ensure(
		null === KnabbelWP\normalize_few_shot_candidate(
			array(
				'post_id' => 99,
				'input'   => 'Bron zonder score',
				'output'  => 'Uitvoer zonder score. Deze cachewaarde is niet bruikbaar.',
			)
		),
		'A cached candidate without an edit score must be rejected.'
	);
	$ensure(
		null === KnabbelWP\normalize_few_shot_candidate(
			array(
				'input'      => 'Bron zonder WordPress-bericht',
				'output'     => 'Uitvoer zonder WordPress-bericht. Deze cachewaarde is niet veilig uit te sluiten.',
				'edit_score' => 12.0,
			)
		),
		'A cached candidate without a WordPress post ID must be rejected.'
	);
	$ensure( 6 === KnabbelWP\few_shot_count_words( 'Er is 3,8 miljoen euro beschikbaar.' ), 'Decimal numbers must count as one word.' );
	$ensure(
		2 === KnabbelWP\few_shot_count_sentences( 'Het evenement begint vandaag. 365 kinderen doen mee.' ),
		'A sentence starting with a number must be detected.'
	);

	echo "Few-shot selection tests passed.\n";
} )();
