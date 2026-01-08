<?php
/**
 * OpenAI API integration
 *
 * Handles content generation using OpenAI API for titles and speech text.
 *
 * @package KnabbelWP
 * @since   0.0.1
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get OpenAI API credentials and settings from plugin options.
 *
 * @since 0.1.0
 * @return array{api_key: string|null, model: string, api_base_url: string} Array with api_key, model, and api_base_url.
 *
 * @phpstan-return OpenAISettings
 */
function openai_get_settings(): array {
	$options = get_option( 'knabbel_settings' );
	return array(
		'api_key'      => $options['openai_api_key'] ?? null,
		'model'        => (string) ( $options['openai_model'] ?? 'gpt-4.1-mini' ),
		'api_base_url' => 'https://api.openai.com/v1/chat/completions',
	);
}

/**
 * Generate content using OpenAI.
 *
 * @since 0.1.0
 * @param string $content The source content.
 * @param string $type    The type of content to generate ('title' or 'speech').
 * @return string|null The generated content or null on failure.
 */
function openai_generate_content( string $content, string $type = 'title' ): ?string {
	$options = get_option( 'knabbel_settings' );

	$prompts = array(
		// phpcs:ignore Generic.Files.LineLength.TooLong -- Prompt text should remain on single line for clarity.
		'title'  => 'Creëer een pakkende radiotitel (max 60 karakters) die:\n- Direct de kernboodschap weergeeft\n- Nieuwswaardig en luisteraantrekkelijk is\n- Geschikt voor gesproken presentatie\n- Actief geformuleerd is',
		// phpcs:ignore Generic.Files.LineLength.TooLong -- Prompt text should remain on single line for clarity.
		'speech' => 'Transformeer naar natuurlijke radiospreektekst met:\n- Korte, heldere zinnen (max 15 woorden)\n- Spreektaal en radiofrases\n- Logische volgorde voor luisteraars\n- Duidelijke overgangen tussen punten\n- Actieve zinsbouw\n- Getallen uitgeschreven waar natuurlijk',
	);

	$prompt = $options[ $type . '_prompt' ] ?? $prompts[ $type ];

	$messages = array(
		array(
			'role'    => 'system',
			'content' => $prompt,
		),
		array(
			'role'    => 'user',
			'content' => $content,
		),
	);

	return openai_make_request_with_retry( $messages );
}

/**
 * Make a request to the OpenAI API with exponential backoff retry.
 *
 * @since 0.1.0
 * @param array<int, array{role: string, content: string}> $messages    The messages to send to the API.
 * @param int                                              $max_retries Maximum retry attempts (default 3).
 * @return string|null The generated content or null on failure.
 *
 * @phpstan-param array<int, ChatMessage> $messages
 */
function openai_make_request_with_retry( array $messages, int $max_retries = 3 ): ?string {
	$attempt = 0;

	while ( $attempt < $max_retries ) {
		$result = openai_make_request_single( $messages );

		if ( null !== $result ) {
			return $result;
		}

		++$attempt;
		if ( $attempt < $max_retries ) {
			$delay = (int) pow( 2, $attempt ); // 2, 4 seconds
			log( 'info', 'OpenAiHandler', "Retry attempt {$attempt}/{$max_retries} after {$delay}s delay" );
			sleep( $delay );
		}
	}

	log( 'error', 'OpenAiHandler', "All {$max_retries} retry attempts failed" );
	return null;
}

/**
 * Make a single request to the OpenAI API.
 *
 * @since 0.1.0
 * @param array<int, array{role: string, content: string}> $messages The messages to send to the API.
 * @return string|null The generated content or null on failure.
 *
 * @phpstan-param array<int, ChatMessage> $messages
 */
function openai_make_request_single( array $messages ): ?string {
	$settings = openai_get_settings();

	if ( empty( $settings['api_key'] ) ) {
		log(
			'error',
			'OpenAiHandler',
			'OpenAI API key not configured',
			array(
				'model' => $settings['model'],
			)
		);
		return null;
	}

	// OpenAI API parameters.
	$data = array(
		'model'       => $settings['model'],
		'messages'    => $messages,
		'max_tokens'  => 1000,
		'temperature' => 0.7,
	);

	$args = array(
		'headers' => array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $settings['api_key'],
		),
		'body'    => wp_json_encode( $data ),
		'timeout' => 30,
	);
	try {
		log(
			'info',
			'OpenAiHandler',
			'Making OpenAI API request',
			array(
				'model'          => $settings['model'],
				'url'            => $settings['api_base_url'],
				'messages_count' => count( $messages ),
				'request_data'   => $data,
			)
		);

		$response = wp_remote_post( $settings['api_base_url'], $args );
		if ( is_wp_error( $response ) ) {
			log(
				'error',
				'OpenAiHandler',
				'OpenAI API request failed',
				array(
					'model'          => $settings['model'],
					'error'          => $response->get_error_message(),
					'messages_count' => count( $messages ),
				)
			);
			return null;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );

		log(
			'info',
			'OpenAiHandler',
			'OpenAI API response received',
			array(
				'model'         => $settings['model'],
				'response_code' => $response_code,
				'body_length'   => strlen( $body ),
				'body_preview'  => substr( $body, 0, 200 ),
			)
		);

		$decoded = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );

		if ( isset( $decoded['error'] ) ) {
			log(
				'error',
				'OpenAiHandler',
				'OpenAI API returned error',
				array(
					'model'          => $settings['model'],
					'response_code'  => $response_code,
					'api_error'      => $decoded['error']['message'] ?? 'unknown',
					'error_type'     => $decoded['error']['type'] ?? 'unknown',
					'error_code'     => $decoded['error']['code'] ?? 'unknown',
					'full_error'     => $decoded['error'],
					'messages_count' => count( $messages ),
				)
			);
			return null;
		}

		if ( ! isset( $decoded['choices'][0]['message']['content'] ) ) {
			log(
				'error',
				'OpenAiHandler',
				'Unexpected OpenAI API response format',
				array(
					'model'          => $settings['model'],
					'response_keys'  => array_keys( $decoded ),
					'has_choices'    => isset( $decoded['choices'] ),
					'messages_count' => count( $messages ),
				)
			);
			return null;
		}

		$content = trim( (string) $decoded['choices'][0]['message']['content'] );

		// Log successful generation with model details.
		$log_context = array(
			'model'          => $settings['model'],
			'content_length' => strlen( $content ),
			'messages_count' => count( $messages ),
		);

		log( 'info', 'OpenAiHandler', 'OpenAI content generated successfully', $log_context );

		return $content;
	} catch ( \JsonException $e ) {
		log(
			'error',
			'OpenAiHandler',
			'OpenAI JSON processing error',
			array(
				'model'         => $settings['model'],
				'json_error'    => $e->getMessage(),
				'response_body' => substr( $body, 0, 500 ),
			)
		);
		return null;
	}
}
