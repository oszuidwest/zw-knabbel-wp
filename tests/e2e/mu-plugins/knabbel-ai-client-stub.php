<?php
/**
 * Plugin Name: Knabbel E2E AI Client Stub
 * Description: Deterministic transport for the WordPress AI Client's OpenAI provider.
 *
 * @package KnabbelWP
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wp_get_environment_type' ) || 'local' !== wp_get_environment_type() ) {
	return;
}

/**
 * Build a deterministic HTTP API success response with a JSON body.
 *
 * @param array<string, mixed> $body Response body to JSON-encode.
 * @return array<string, mixed> WordPress HTTP API response array.
 */
function knabbel_e2e_json_response( array $body ): array {
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( $body ),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

add_filter(
	'pre_http_request',
	static function ( false|array|WP_Error $preempt, array $parsed_args, string $url ): false|array|WP_Error {
		if ( 'https://api.openai.com/v1/models' === $url ) {
			return knabbel_e2e_json_response(
				array(
					'data' => array(
						array( 'id' => 'gpt-4.1-mini' ),
					),
				)
			);
		}

		if ( 'https://api.openai.com/v1/responses' !== $url ) {
			return $preempt;
		}

		$headers = $parsed_args['headers'] ?? array();
		if ( ! is_array( $headers ) || 'Bearer e2e-openai-key' !== ( array_change_key_case( $headers, CASE_LOWER )['authorization'] ?? '' ) ) {
			return new WP_Error( 'knabbel_e2e_ai_auth_error', 'The Connector credential did not reach the AI provider' );
		}

		$call_count = (int) get_option( 'knabbel_e2e_ai_call_count', 0 );
		update_option( 'knabbel_e2e_ai_call_count', $call_count + 1, false );

		if ( 'error' === get_option( 'knabbel_e2e_ai_mode', 'success' ) ) {
			return new WP_Error( 'knabbel_e2e_ai_error', 'Deterministic AI provider failure' );
		}

		$request_body = json_decode( (string) ( $parsed_args['body'] ?? '' ), true );
		if ( is_array( $request_body ) ) {
			update_option( 'knabbel_e2e_ai_last_request', $request_body, false );
		}

		return knabbel_e2e_json_response(
			array(
				'id'     => 'resp_knabbel_e2e',
				'status' => 'completed',
				'output' => array(
					array(
						'type'    => 'message',
						'role'    => 'assistant',
						'status'  => 'completed',
						'content' => array(
							array(
								'type' => 'output_text',
								// Keep in sync with suite.php and editor-flow.spec.ts.
								'text' => 'Deterministische E2E-radiospreektekst.',
							),
						),
					),
				),
				'usage'  => array(
					'input_tokens'  => 10,
					'output_tokens' => 5,
					'total_tokens'  => 15,
				),
			)
		);
	},
	10,
	3
);
