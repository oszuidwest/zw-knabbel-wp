<?php
/**
 * WordPress AI Client integration for speech-text generation.
 *
 * Handles provider-independent speech text generation through WordPress Core.
 *
 * @package KnabbelWP
 * @since   0.5.0
 */

declare(strict_types=1);

namespace KnabbelWP;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore Generic.Files.LineLength.TooLong -- Keeping the prompt literal intact makes it easy to review and reuse.
const AI_DEFAULT_INSTRUCTION = "Transformeer naar natuurlijke radiospreektekst met:\n- Korte, heldere zinnen (max 15 woorden)\n- Spreektaal en radiofrases\n- Logische volgorde voor luisteraars\n- Duidelijke overgangen tussen punten\n- Actieve zinsbouw\n- Getallen uitgeschreven waar natuurlijk";
const AI_ARTICLE_PROMPT      = "Artikel:\n\"\"\"\n%s\n\"\"\"";
const AI_EXAMPLE_PROMPT      = '%s — leer van toon, nieuwsselectie en formulering. Neem geen feiten over. '
	. 'De lengte en het zinsaantal kunnen afwijken van de instructie voor het nieuwe artikel.';

/**
 * Returns configured text-generation models.
 *
 * Model keys are "provider/model" setting values as understood by ai_parse_model_setting().
 *
 * @since 0.6.0
 * @return array<string, array{label: string, models: array<string, string>}>
 */
function ai_get_available_models(): array {
	if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
		return array();
	}

	try {
		$requirements = new ModelRequirements( array( CapabilityEnum::textGeneration() ), array() );
		$models       = array();

		foreach ( AiClient::defaultRegistry()->findModelsMetadataForSupport( $requirements ) as $provider_models_metadata ) {
			$provider        = $provider_models_metadata->getProvider();
			$provider_models = array();

			foreach ( $provider_models_metadata->getModels() as $model ) {
				$provider_models[ $provider->getId() . '/' . $model->getId() ] = $model->getName();
			}

			if ( array() !== $provider_models ) {
				$models[ $provider->getId() ] = array(
					'label'  => $provider->getName(),
					'models' => $provider_models,
				);
			}
		}

		return $models;
	} catch ( \Throwable $e ) {
		log( 'error', 'AiHandler', 'Could not list AI models', array( 'error' => $e->getMessage() ) );
		return array();
	}
}

/**
 * Parses a provider and model preference.
 *
 * @since 0.6.0
 * @param mixed $value The stored setting value.
 * @return array{0: string, 1: string}|null Provider and model ID, or null when unset or malformed.
 */
function ai_parse_model_setting( mixed $value ): ?array {
	if ( ! is_string( $value ) ) {
		return null;
	}

	$parts = explode( '/', $value, 2 );
	return 2 === count( $parts ) && ! in_array( '', $parts, true ) ? $parts : null;
}

/**
 * Generates speech text through the WordPress AI Client.
 *
 * @since 0.5.0
 * @since 0.7.0 Added the `$current_post_id` parameter and curated example mix.
 * @param string $content         The source content.
 * @param int    $current_post_id Current post ID to exclude from examples, 0 for none.
 * @return string|null The generated content or null on failure.
 */
function ai_generate_content( string $content, int $current_post_id ): ?string {
	$options       = get_plugin_settings();
	$speech_prompt = (string) $options['speech_prompt'];
	$instruction   = '' !== trim( $speech_prompt ) ? $speech_prompt : AI_DEFAULT_INSTRUCTION;

	$messages = array();
	foreach ( get_few_shot_examples( $current_post_id ) as $example ) {
		$example_label = 'accepted' === $example['provenance']
			? 'VOORBEELD DAT DE REDACTIE DIRECT HEEFT GEACCEPTEERD'
			: 'VOORBEELD DAT DE REDACTIE HEEFT AANGEPAST';
		$messages[]    = new UserMessage(
			array( new MessagePart( sprintf( AI_EXAMPLE_PROMPT, $example_label ) . "\n\n" . sprintf( AI_ARTICLE_PROMPT, $example['input'] ) ) )
		);
		$messages[]    = new ModelMessage( array( new MessagePart( $example['output'] ) ) );
	}
	$messages[] = new UserMessage( array( new MessagePart( sprintf( AI_ARTICLE_PROMPT, $content ) ) ) );

	$model_preference = ai_parse_model_setting( $options['ai_model'] );

	$max_attempts = 3;
	for ( $attempt = 1; $attempt <= $max_attempts; ++$attempt ) {
		$prompt = wp_ai_client_prompt( $messages )
			->using_system_instruction( $instruction )
			->using_max_tokens( 1000 )
			->using_temperature( 0.7 );
		if ( null !== $model_preference ) {
			$prompt = $prompt->using_model_preference( $model_preference );
		}

		if ( 1 === $attempt && ! $prompt->is_supported_for_text_generation() ) {
			log( 'error', 'AiHandler', 'WordPress AI Client does not support text generation for this prompt' );
			return null;
		}

		$result = $prompt->generate_text();

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
