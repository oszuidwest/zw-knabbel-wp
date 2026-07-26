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
	$max_retries = 3;
	$attempt     = 0;

	while ( $attempt < $max_retries ) {
		$result = ai_generate_content_once( $content );

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
 * @param string $content The source content.
 * @return string|null The generated content or null on failure.
 */
function ai_generate_content_once( string $content ): ?string {
	$options = get_option( 'knabbel_settings', array() );
	if ( ! is_array( $options ) ) {
		$options = array();
	}

	// phpcs:ignore Generic.Files.LineLength.TooLong -- Prompt text should remain on a single line for clarity.
	$default_instruction = "Transformeer naar natuurlijke radiospreektekst met:\n- Korte, heldere zinnen (max 15 woorden)\n- Spreektaal en radiofrases\n- Logische volgorde voor luisteraars\n- Duidelijke overgangen tussen punten\n- Actieve zinsbouw\n- Getallen uitgeschreven waar natuurlijk";
	$instruction         = isset( $options['speech_prompt'] ) && is_string( $options['speech_prompt'] ) && '' !== trim( $options['speech_prompt'] )
		? $options['speech_prompt']
		: $default_instruction;

	$result = wp_ai_client_prompt( "Artikel:\n\"\"\"\n" . $content . "\n\"\"\"" )
		->with_history( ...ai_get_few_shot_history() )
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
 * Build few-shot conversation history for speech generation.
 *
 * Loads cached editor-corrected examples as native AI Client messages.
 *
 * @since 0.3.0
 * @return list<Message> Alternating user and model example messages.
 */
function ai_get_few_shot_history(): array {
	// Respect the setting immediately, even before the nightly sync clears the cache.
	$settings = get_option( 'knabbel_settings', array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	$few_shot_count = (int) ( $settings['few_shot_count'] ?? 5 );
	if ( $few_shot_count <= 0 ) {
		return array();
	}

	$examples = get_option( 'knabbel_few_shot_examples', array() );

	if ( empty( $examples ) || ! is_array( $examples ) ) {
		return array();
	}

	$history = array();
	foreach ( $examples as $example ) {
		if (
			! is_array( $example )
			|| ! isset( $example['input'], $example['output'] )
			|| ! is_string( $example['input'] )
			|| ! is_string( $example['output'] )
			|| '' === trim( $example['input'] )
			|| '' === trim( $example['output'] )
		) {
			continue;
		}

		$history[] = new UserMessage( array( new MessagePart( "Artikel:\n\"\"\"\n" . $example['input'] . "\n\"\"\"" ) ) );
		$history[] = new ModelMessage( array( new MessagePart( $example['output'] ) ) );
	}

	return $history;
}
