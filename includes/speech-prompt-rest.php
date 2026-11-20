<?php
/**
 * REST routes to read and replace the speech prompt.
 *
 * An outside job signs in with an application password, reads the prompt and
 * replaces it only when nobody changed it in the meantime.
 *
 * @package KnabbelWP
 * @since   1.1.0
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SPEECH_PROMPT_CAPABILITY = 'knabbel_manage_speech_prompt';
const SPEECH_PROMPT_BOT_ROLE   = 'knabbel_prompt_bot';
const SPEECH_PROMPT_MAX_LENGTH = 20000;

/**
 * Adds the prompt bot role when it is missing.
 *
 * Runs on every init because activation hooks do not fire on plugin updates.
 *
 * @since 1.1.0
 */
function speech_prompt_register_role(): void {
	if ( null !== get_role( SPEECH_PROMPT_BOT_ROLE ) ) {
		return;
	}

	add_role(
		SPEECH_PROMPT_BOT_ROLE,
		'Knabbel prompt bot',
		array(
			'read'                   => true,
			SPEECH_PROMPT_CAPABILITY => true,
		)
	);
}

/**
 * Registers the speech prompt routes.
 *
 * @since 1.1.0
 */
function speech_prompt_register_routes(): void {
	register_rest_route(
		'knabbel/v1',
		'/speech-prompt',
		array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => __NAMESPACE__ . '\\speech_prompt_rest_get',
				'permission_callback' => __NAMESPACE__ . '\\speech_prompt_rest_permission',
			),
			array(
				'methods'             => 'PUT',
				'callback'            => __NAMESPACE__ . '\\speech_prompt_rest_put',
				'permission_callback' => __NAMESPACE__ . '\\speech_prompt_rest_permission',
				'args'                => array(
					'prompt'          => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 1,
						'maxLength' => SPEECH_PROMPT_MAX_LENGTH,
						// A blank prompt would silently switch generation back to the default prompt.
						'pattern'   => '\S',
					),
					'expected_sha256' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[0-9a-f]{64}$',
					),
				),
			),
		)
	);
}

/**
 * Checks whether the current user may read and replace the speech prompt.
 *
 * @since 1.1.0
 * @return bool True for the prompt bot and for users who manage options.
 */
function speech_prompt_rest_permission(): bool {
	return current_user_can( SPEECH_PROMPT_CAPABILITY ) || current_user_can( 'manage_options' );
}

/**
 * Returns the stored custom speech prompt from a settings value.
 *
 * @since 1.1.0
 * @param mixed $settings The stored knabbel_settings value.
 * @return string The custom prompt, or an empty string when none is stored.
 */
function speech_prompt_from_settings( mixed $settings ): string {
	return is_array( $settings ) && isset( $settings['speech_prompt'] ) && is_string( $settings['speech_prompt'] ) ? $settings['speech_prompt'] : '';
}

/**
 * Returns the stored speech prompt, its hash and the default prompt.
 *
 * @since 1.1.0
 * @return \WP_REST_Response The prompt, its SHA-256 and the default prompt.
 */
function speech_prompt_rest_get(): \WP_REST_Response {
	$prompt = speech_prompt_from_settings( get_option( 'knabbel_settings' ) );

	return new \WP_REST_Response(
		array(
			'prompt'         => $prompt,
			'sha256'         => hash( 'sha256', $prompt ),
			'default_prompt' => AI_DEFAULT_INSTRUCTION,
		)
	);
}

/**
 * Replaces the speech prompt when the stored one is still the expected one.
 *
 * Writes the option row with a compare-and-swap, so neither a hand edit of the
 * prompt nor a change to another setting is lost to a concurrent request. The
 * prompt is stored as given, without the settings form sanitizer.
 *
 * @since 1.1.0
 * @param \WP_REST_Request $request The request.
 * @return \WP_REST_Response|\WP_Error The new prompt and its SHA-256, or an error.
 *
 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
 */
function speech_prompt_rest_put( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	global $wpdb;

	$prompt          = (string) $request->get_param( 'prompt' );
	$expected_sha256 = (string) $request->get_param( 'expected_sha256' );
	$option_name     = 'knabbel_settings';

	for ( $attempt = 0; $attempt < 3; ++$attempt ) {
		// A direct read is required so the serialized value can be the compare-and-swap token below.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored_value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$option_name
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$settings       = null === $stored_value ? default_settings() : maybe_unserialize( $stored_value );
		$current_sha256 = hash( 'sha256', speech_prompt_from_settings( $settings ) );
		if ( ! hash_equals( $current_sha256, $expected_sha256 ) ) {
			log( 'warning', 'SpeechPrompt', 'Speech prompt not replaced: it changed since it was read', array( 'sha256' => $current_sha256 ) );
			return new \WP_Error(
				'knabbel_speech_prompt_conflict',
				__( 'The speech prompt changed since it was read.', 'zw-knabbel-wp' ),
				array(
					'status' => 409,
					'sha256' => $current_sha256,
				)
			);
		}

		$settings                  = is_array( $settings ) ? $settings : array();
		$settings['speech_prompt'] = $prompt;
		$new_value                 = maybe_serialize( $settings );

		if ( null === $stored_value ) {
			$written = add_option( $option_name, $settings, '', true );
		} elseif ( $new_value === $stored_value ) {
			$written = true;
		} else {
			// Only update the row if no other request changed it after our read.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$written = 1 === $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = CAST(%s AS BINARY)",
					$new_value,
					$option_name,
					$stored_value
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			wp_cache_delete( $option_name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}

		if ( $written ) {
			$sha256 = hash( 'sha256', $prompt );
			log(
				'info',
				'SpeechPrompt',
				'Speech prompt replaced through the REST API',
				array(
					'user_id'         => get_current_user_id(),
					'previous_sha256' => $current_sha256,
					'sha256'          => $sha256,
				)
			);

			return new \WP_REST_Response(
				array(
					'prompt' => $prompt,
					'sha256' => $sha256,
				)
			);
		}
	}

	log( 'error', 'SpeechPrompt', 'Speech prompt not replaced: the settings kept changing' );
	return new \WP_Error(
		'knabbel_speech_prompt_busy',
		__( 'The settings kept changing. Read the speech prompt again and retry.', 'zw-knabbel-wp' ),
		array( 'status' => 503 )
	);
}
