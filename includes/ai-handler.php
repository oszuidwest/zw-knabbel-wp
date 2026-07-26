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

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore Generic.Files.LineLength.TooLong -- Keeping the prompt literal intact makes it easy to review and reuse.
const AI_DEFAULT_INSTRUCTION = "Transformeer naar natuurlijke radiospreektekst met:\n- Korte, heldere zinnen (max 15 woorden)\n- Spreektaal en radiofrases\n- Logische volgorde voor luisteraars\n- Duidelijke overgangen tussen punten\n- Actieve zinsbouw\n- Getallen uitgeschreven waar natuurlijk";
const AI_ARTICLE_PROMPT      = "Artikel:\n\"\"\"\n%s\n\"\"\"";

/**
 * Generate speech text through the WordPress AI Client.
 *
 * @since 0.1.0
 * @param string $content The source content.
 * @return string|null The generated content or null on failure.
 */
function ai_generate_content( string $content ): ?string {
	$options       = (array) get_option( 'knabbel_settings', array() );
	$speech_prompt = $options['speech_prompt'] ?? '';
	$instruction   = is_string( $speech_prompt ) && '' !== trim( $speech_prompt )
		? $speech_prompt
		: AI_DEFAULT_INSTRUCTION;

	$messages       = array();
	$few_shot_count = (int) ( $options['few_shot_count'] ?? 5 );
	$examples       = get_option( 'knabbel_few_shot_examples', array() );
	if ( $few_shot_count > 0 && is_array( $examples ) ) {
		foreach ( array_slice( $examples, 0, $few_shot_count ) as $example ) {
			$input  = $example['input'] ?? null;
			$output = $example['output'] ?? null;
			if ( ! is_string( $input ) || '' === trim( $input ) || ! is_string( $output ) || '' === trim( $output ) ) {
				continue;
			}

			$messages[] = new UserMessage( array( new MessagePart( sprintf( AI_ARTICLE_PROMPT, $input ) ) ) );
			$messages[] = new ModelMessage( array( new MessagePart( $output ) ) );
		}
	}
	$messages[] = new UserMessage( array( new MessagePart( sprintf( AI_ARTICLE_PROMPT, $content ) ) ) );

	$max_attempts = 3;
	for ( $attempt = 1; $attempt <= $max_attempts; ++$attempt ) {
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
		} else {
			$result = trim( $result );
			if ( '' !== $result ) {
				log( 'info', 'AiHandler', 'Speech text generated successfully', array( 'content_length' => strlen( $result ) ) );
				return $result;
			}
			log( 'error', 'AiHandler', 'WordPress AI Client returned empty speech text' );
		}

		if ( $attempt < $max_attempts ) {
			$delay = 2 ** $attempt; // 2, 4 seconds.
			log( 'info', 'AiHandler', "Retry attempt {$attempt}/{$max_attempts} after {$delay}s delay" );
			sleep( $delay );
		}
	}

	log( 'error', 'AiHandler', "All {$max_attempts} retry attempts failed" );
	return null;
}
