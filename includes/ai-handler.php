<?php
/**
 * WordPress AI Client integration
 *
 * Handles provider-independent speech text generation through WordPress Core.
 *
 * @package KnabbelWP
 * @since   0.0.1
 */

declare(strict_types=1);

namespace KnabbelWP;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate speech text through the WordPress AI Client.
 *
 * @since 0.1.0
 * @param string $content The source content.
 * @return string|null The generated content or null on failure.
 */
function ai_generate_content( string $content ): ?string {
	$options = get_option( 'knabbel_settings' );

	// phpcs:ignore Generic.Files.LineLength.TooLong -- Prompt text should remain on a single line for clarity.
	$default_instruction = "Transformeer naar natuurlijke radiospreektekst met:\n- Korte, heldere zinnen (max 15 woorden)\n- Spreektaal en radiofrases\n- Logische volgorde voor luisteraars\n- Duidelijke overgangen tussen punten\n- Actieve zinsbouw\n- Getallen uitgeschreven waar natuurlijk";

	$speech_prompt = $options['speech_prompt'] ?? '';
	$instruction   = is_string( $speech_prompt ) && '' !== trim( $speech_prompt )
		? $speech_prompt
		: $default_instruction;

	$messages   = ai_get_few_shot_history( (int) ( $options['few_shot_count'] ?? 5 ) );
	$messages[] = new UserMessage( array( new MessagePart( ai_format_article_prompt( $content ) ) ) );

	$max_retries = 3;
	$attempt     = 0;

	while ( $attempt < $max_retries ) {
		$result = ai_generate_content_once( $messages, $instruction );

		if ( null !== $result ) {
			return $result;
		}

		++$attempt;
		if ( $attempt < $max_retries ) {
			$delay = (int) pow( 2, $attempt ); // 2, 4 seconds
			log( 'info', 'AiHandler', "Retry attempt {$attempt}/{$max_retries} after {$delay}s delay" );
			sleep( $delay );
		}
	}

	log( 'error', 'AiHandler', "All {$max_retries} retry attempts failed" );
	return null;
}

/**
 * Make a single generation request through the WordPress AI Client.
 *
 * @since 0.1.0
 * @param list<Message> $messages    The few-shot history and article prompt.
 * @param string        $instruction The system instruction.
 * @return string|null The generated content or null on failure.
 */
function ai_generate_content_once( array $messages, string $instruction ): ?string {
	$result = wp_ai_client_prompt( $messages )
		->using_system_instruction( $instruction )
		->using_max_tokens( 1000 )
		->using_temperature( 0.7 )
		->generate_text();
	if ( is_wp_error( $result ) ) {
		log(
			'error',
			'AiHandler',
			'WordPress AI Client request failed',
			array(
				'error'      => $result->get_error_message(),
				'error_code' => $result->get_error_code(),
			)
		);
		return null;
	}

	$result = trim( $result );
	if ( '' === $result ) {
		log( 'error', 'AiHandler', 'WordPress AI Client returned empty speech text' );
		return null;
	}

	log( 'info', 'AiHandler', 'Speech text generated successfully', array( 'content_length' => strlen( $result ) ) );
	return $result;
}

/**
 * Wrap article text in the delimited frame shared by the live prompt and few-shot examples.
 *
 * Few-shot examples only steer the model when their framing matches the live prompt exactly.
 *
 * @since 0.4.0
 * @param string $article The article text.
 * @return string The framed prompt.
 */
function ai_format_article_prompt( string $article ): string {
	return "Artikel:\n\"\"\"\n{$article}\n\"\"\"";
}

/**
 * Build few-shot conversation history for speech generation.
 *
 * Loads cached editor-corrected examples as native AI Client messages. The
 * count is applied here so a lowered setting takes effect immediately, before
 * the nightly sync rebuilds the cache.
 *
 * @since 0.3.0
 * @param int $few_shot_count Maximum number of examples to include.
 * @return list<Message> Alternating user and model example messages.
 */
function ai_get_few_shot_history( int $few_shot_count ): array {
	if ( $few_shot_count <= 0 ) {
		return array();
	}

	$examples = get_option( 'knabbel_few_shot_examples', array() );
	if ( ! is_array( $examples ) ) {
		return array();
	}

	$history = array();
	foreach ( array_slice( $examples, 0, $few_shot_count ) as $example ) {
		$input  = $example['input'] ?? null;
		$output = $example['output'] ?? null;
		if ( ! is_string( $input ) || '' === trim( $input ) || ! is_string( $output ) || '' === trim( $output ) ) {
			continue;
		}

		$history[] = new UserMessage( array( new MessagePart( ai_format_article_prompt( $input ) ) ) );
		$history[] = new ModelMessage( array( new MessagePart( $output ) ) );
	}

	return $history;
}
