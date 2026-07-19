<?php
/**
 * Plugin Name: Knabbel E2E OpenAI Stub
 * Description: Deterministic OpenAI transport for the isolated E2E environment.
 *
 * @package KnabbelWP
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'pre_http_request',
	static function ( false|array|WP_Error $preempt, array $parsed_args, string $url ): false|array|WP_Error {
		if ( ! str_starts_with( $url, 'https://api.openai.com/' ) ) {
			return $preempt;
		}

		$call_count = (int) get_option( 'knabbel_e2e_openai_call_count', 0 );
		update_option( 'knabbel_e2e_openai_call_count', $call_count + 1, false );

		if ( 'error' === get_option( 'knabbel_e2e_openai_mode', 'success' ) ) {
			return new WP_Error( 'knabbel_e2e_openai_error', 'Deterministic OpenAI failure' );
		}

		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'choices' => array(
						array(
							'message' => array(
								'content' => 'Deterministische E2E-radiospreektekst.',
							),
						),
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
