<?php
/**
 * Babbel API integration
 *
 * Handles authentication, session management, and API communication with the Babbel API.
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
 * Get Babbel API credentials from plugin settings.
 *
 * @since 0.1.0
 * @return array{base_url: string, username: string, password: string} Array with base_url, username, and password.
 *
 * @phpstan-return BabbelCredentials
 */
function babbel_get_credentials(): array {
	$options = get_option( 'knabbel_settings' );
	return array(
		'base_url' => rtrim( (string) ( $options['api_base_url'] ?? '' ), '/' ),
		'username' => (string) ( $options['api_username'] ?? '' ),
		'password' => (string) ( $options['api_password'] ?? '' ),
	);
}



/**
 * Get cached session cookies, creating new session if needed.
 *
 * @since 0.1.0
 * @return array<int, \WP_Http_Cookie>|\WP_Error Session cookies array or WP_Error on failure.
 */
function babbel_get_session_cookies(): array|\WP_Error {
	$credentials = babbel_get_credentials();

	if ( empty( $credentials['username'] ) || empty( $credentials['password'] ) ) {
		return new \WP_Error( 'missing_credentials', __( 'Username and password are required', 'zw-knabbel-wp' ) );
	}

	// Create unique cache key for this API instance.
	$cache_key      = 'knabbel_session_' . md5( $credentials['base_url'] . $credentials['username'] );
	$cached_cookies = get_transient( $cache_key );

	if ( $cached_cookies && is_array( $cached_cookies ) ) {
		return $cached_cookies;
	}

	// Create fresh session.
	$login_endpoint = $credentials['base_url'] . '/sessions';
	$login_data     = wp_json_encode(
		array(
			'username' => $credentials['username'],
			'password' => $credentials['password'],
		)
	);

		$login_args = array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => $login_data,
			'timeout' => 30,
		);

		$response = wp_remote_post( $login_endpoint, $login_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 201 !== $response_code ) {
			$body = wp_remote_retrieve_body( $response );
			return new \WP_Error( 'login_failed', __( 'Login failed: ', 'zw-knabbel-wp' ) . $body );
		}

		$cookies = wp_remote_retrieve_cookies( $response );
		if ( empty( $cookies ) ) {
			return new \WP_Error( 'no_cookies', __( 'No session cookies received', 'zw-knabbel-wp' ) );
		}

		// Cache session cookies for 50 minutes (sessions expire after 1 hour).
		set_transient( $cache_key, $cookies, 50 * MINUTE_IN_SECONDS );

		return $cookies;
}

/**
 * Clear cached session cookies.
 *
 * @since 0.1.0
 */
function babbel_clear_session_cache(): void {
	$credentials = babbel_get_credentials();
	$cache_key   = 'knabbel_session_' . md5( $credentials['base_url'] . $credentials['username'] );
	delete_transient( $cache_key );
}

/**
 * Make an authenticated request to the Babbel API.
 * Automatically retries with fresh session on 401 Unauthorized.
 *
 * @since 0.1.0
 * @param string               $url  The API endpoint URL.
 * @param array<string, mixed> $args Request arguments for wp_remote_request().
 * @return array<string, mixed>|\WP_Error HTTP response array or WP_Error on failure.
 *
 * @phpstan-param WpHttpArgs $args
 * @phpstan-return WpHttpResponse|\WP_Error
 */
function babbel_make_authenticated_request( string $url, array $args = array() ): array|\WP_Error {
	$cookies = babbel_get_session_cookies();

	if ( is_wp_error( $cookies ) ) {
		return $cookies;
	}

	// Add cached session cookies to request.
	$args['cookies'] = $args['cookies'] ?? array();
	foreach ( $cookies as $cookie ) {
		$args['cookies'][] = $cookie;
	}

	$response = wp_remote_request( $url, $args );

	// Retry on 401: clear cache and re-authenticate.
	if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 401 ) {
		log( 'info', 'BabbelApi', 'Got 401, clearing session cache and retrying' );
		babbel_clear_session_cache();

		$cookies = babbel_get_session_cookies();
		if ( is_wp_error( $cookies ) ) {
			return $cookies;
		}

		$args['cookies'] = array();
		foreach ( $cookies as $cookie ) {
			$args['cookies'][] = $cookie;
		}

		$response = wp_remote_request( $url, $args );
	}

	return $response;
}

/**
 * Create a story via the Babbel API.
 *
 * @since 0.1.0
 * @param array<string, mixed> $story_data The story data to send.
 * @return array{success: bool, message: string, story_id?: string} Response with success status and message.
 *
 * @phpstan-param StoryData $story_data
 * @phpstan-return BabbelApiResponse
 */
function babbel_create_story( array $story_data ): array {
	$credentials = babbel_get_credentials();
	$endpoint    = $credentials['base_url'] . '/stories';

	// Build JSON payload (new API format).
	// weekdays is now expected as bitmask integer (0-127).
	$payload = array(
		'title'      => $story_data['title'],
		'text'       => $story_data['text'],
		'start_date' => $story_data['start_date'],
		'end_date'   => $story_data['end_date'],
		'status'     => $story_data['status'] ?? 'draft',
		'weekdays'   => $story_data['weekdays'] ?? WEEKDAY_ALL,
	);

	// Add metadata if provided.
	if ( isset( $story_data['metadata'] ) ) {
		$payload['metadata'] = $story_data['metadata'];
	}

	try {
		$json_body = wp_json_encode( $payload );
		if ( false === $json_body ) {
			return array(
				'success' => false,
				'message' => __( 'Could not encode story data', 'zw-knabbel-wp' ),
			);
		}
	} catch ( \JsonException $e ) {
		return array(
			'success' => false,
			'message' => __( 'Could not encode story data', 'zw-knabbel-wp' ),
		);
	}

	$args = array(
		'method'  => 'POST',
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => $json_body,
		'timeout' => 30,
	);

		$response = babbel_make_authenticated_request( $endpoint, $args );

	if ( is_wp_error( $response ) ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API request failed',
			array(
				'endpoint' => $endpoint,
				'error'    => $response->get_error_message(),
			)
		);
		return array(
			'success' => false,
			'message' => __( 'API connection failed: ', 'zw-knabbel-wp' ) . $response->get_error_message(),
		);
	}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );

		// API returns 201 (Created) for successful story creation.
	if ( 201 !== $response_code ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API HTTP error',
			array(
				'endpoint'      => $endpoint,
				'response_code' => $response_code,
				'response_body' => substr( $body, 0, 500 ),
			)
		);
		return array(
			'success' => false,
			// translators: %1$d is the HTTP status code, %2$s is the response body.
			'message' => sprintf( __( 'API error: HTTP %1$d - %2$s', 'zw-knabbel-wp' ), $response_code, substr( $body, 0, 1000 ) ),
		);
	}

	try {
		$decoded = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
	} catch ( \JsonException $e ) {
		log(
			'error',
			'BabbelApi',
			'Invalid JSON response from Babbel API',
			array(
				'endpoint'      => $endpoint,
				'response_body' => substr( $body, 0, 500 ),
				'json_error'    => $e->getMessage(),
			)
		);
		return array(
			'success' => false,
			'message' => __( 'Invalid API response', 'zw-knabbel-wp' ),
		);
	}

		// Check for error response.
	if ( isset( $decoded['error'] ) ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API returned error',
			array(
				'endpoint'      => $endpoint,
				'api_error'     => $decoded['message'] ?? 'Unknown error',
				'full_response' => $decoded,
			)
		);
		return array(
			'success' => false,
			'message' => $decoded['message'] ?? __( 'API error', 'zw-knabbel-wp' ),
		);
	}

		// Check if we got a story ID.
	if ( ! isset( $decoded['id'] ) ) {
		log(
			'error',
			'BabbelApi',
			'No story ID in Babbel API response',
			array(
				'endpoint' => $endpoint,
				'response' => $decoded,
			)
		);
		return array(
			'success' => false,
			'message' => __( 'No story ID received from API', 'zw-knabbel-wp' ),
		);
	}

		// Log successful story creation.
		log(
			'info',
			'BabbelApi',
			'Story successfully created in Babbel API',
			array(
				'story_id' => $decoded['id'],
				'endpoint' => $endpoint,
			)
		);

		return array(
			'success'  => true,
			'story_id' => $decoded['id'],
			'message'  => __( 'Story created successfully', 'zw-knabbel-wp' ),
		);
}


/**
 * Test the connection to the Babbel API.
 * Authenticates and then verifies session by fetching current user info.
 *
 * @since 0.1.0
 * @return array{success: bool, message: string} Response with success status and message.
 */
function babbel_test_connection(): array {
	$credentials = babbel_get_credentials();

	if ( empty( $credentials['username'] ) || empty( $credentials['password'] ) ) {
		return array(
			'success' => false,
			'message' => __( 'Username and password are required', 'zw-knabbel-wp' ),
		);
	}

	// First authenticate.
	$cookies = babbel_get_session_cookies();
	if ( is_wp_error( $cookies ) ) {
		return array(
			'success' => false,
			'message' => __( 'Login failed: ', 'zw-knabbel-wp' ) . $cookies->get_error_message(),
		);
	}

	// Then verify session by getting current user.
	$endpoint = $credentials['base_url'] . '/sessions/current';
	$response = babbel_make_authenticated_request( $endpoint, array( 'method' => 'GET' ) );

	if ( is_wp_error( $response ) ) {
		return array(
			'success' => false,
			'message' => __( 'Session verification failed: ', 'zw-knabbel-wp' ) . $response->get_error_message(),
		);
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	if ( 200 === $response_code ) {
		$body = wp_remote_retrieve_body( $response );
		try {
			$decoded  = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
			$username = $decoded['username'] ?? 'unknown';
			return array(
				'success' => true,
				// translators: %s is the username.
				'message' => sprintf( __( 'Connected as: %s', 'zw-knabbel-wp' ), $username ),
			);
		} catch ( \JsonException $e ) {
			return array(
				'success' => true,
				'message' => __( 'Connection successful', 'zw-knabbel-wp' ),
			);
		}
	}

	return array(
		'success' => false,
		// translators: %d is the HTTP status code.
		'message' => sprintf( __( 'Unexpected response: HTTP %d', 'zw-knabbel-wp' ), $response_code ),
	);
}

/**
 * Update an existing story in the Babbel API.
 *
 * @since 0.2.0
 * @param string                                                        $story_id   The Babbel story ID.
 * @param array{start_date?: string, end_date?: string, weekdays?: int} $story_data The story data to update.
 * @return array{success: bool, message: string} Response with success status and message.
 *
 * @phpstan-return BabbelApiResponse
 */
function babbel_update_story( string $story_id, array $story_data ): array {
	$credentials = babbel_get_credentials();
	$endpoint    = $credentials['base_url'] . '/stories/' . $story_id;

	if ( array() === $story_data ) {
		return array(
			'success' => false,
			'message' => __( 'No data to update', 'zw-knabbel-wp' ),
		);
	}

	try {
		$json_body = wp_json_encode( $story_data );
		if ( false === $json_body ) {
			return array(
				'success' => false,
				'message' => __( 'Could not encode update data', 'zw-knabbel-wp' ),
			);
		}
	} catch ( \JsonException $e ) {
		return array(
			'success' => false,
			'message' => __( 'Could not encode update data', 'zw-knabbel-wp' ),
		);
	}

	$args = array(
		'method'  => 'PUT',
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => $json_body,
		'timeout' => 30,
	);

	$response = babbel_make_authenticated_request( $endpoint, $args );

	if ( is_wp_error( $response ) ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API update request failed',
			array(
				'endpoint' => $endpoint,
				'story_id' => $story_id,
				'error'    => $response->get_error_message(),
			)
		);
		return array(
			'success' => false,
			'message' => __( 'API connection failed: ', 'zw-knabbel-wp' ) . $response->get_error_message(),
		);
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	$body          = wp_remote_retrieve_body( $response );

	if ( 200 !== $response_code ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API update HTTP error',
			array(
				'endpoint'      => $endpoint,
				'story_id'      => $story_id,
				'response_code' => $response_code,
				'response_body' => substr( $body, 0, 500 ),
			)
		);
		return array(
			'success' => false,
			// translators: %1$d is the HTTP status code, %2$s is the response body.
			'message' => sprintf( __( 'API error: HTTP %1$d - %2$s', 'zw-knabbel-wp' ), $response_code, substr( $body, 0, 1000 ) ),
		);
	}

	log(
		'info',
		'BabbelApi',
		'Story successfully updated in Babbel API',
		array(
			'story_id' => $story_id,
			'endpoint' => $endpoint,
		)
	);

	return array(
		'success' => true,
		'message' => __( 'Story updated successfully', 'zw-knabbel-wp' ),
	);
}

/**
 * Delete (soft delete) a story from the Babbel API.
 *
 * @since 0.2.0
 * @param string $story_id The Babbel story ID.
 * @return array{success: bool, message: string} Response with success status and message.
 *
 * @phpstan-return BabbelApiResponse
 */
function babbel_delete_story( string $story_id ): array {
	$credentials = babbel_get_credentials();
	$endpoint    = $credentials['base_url'] . '/stories/' . $story_id;

	$args = array(
		'method'  => 'DELETE',
		'timeout' => 30,
	);

	$response = babbel_make_authenticated_request( $endpoint, $args );

	if ( is_wp_error( $response ) ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API delete request failed',
			array(
				'endpoint' => $endpoint,
				'story_id' => $story_id,
				'error'    => $response->get_error_message(),
			)
		);
		return array(
			'success' => false,
			'message' => __( 'API connection failed: ', 'zw-knabbel-wp' ) . $response->get_error_message(),
		);
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	$body          = wp_remote_retrieve_body( $response );

	// API returns 200 for successful delete (soft delete).
	if ( 200 !== $response_code && 204 !== $response_code ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API delete HTTP error',
			array(
				'endpoint'      => $endpoint,
				'story_id'      => $story_id,
				'response_code' => $response_code,
				'response_body' => substr( $body, 0, 500 ),
			)
		);
		return array(
			'success' => false,
			// translators: %1$d is the HTTP status code, %2$s is the response body.
			'message' => sprintf( __( 'API error: HTTP %1$d - %2$s', 'zw-knabbel-wp' ), $response_code, substr( $body, 0, 1000 ) ),
		);
	}

	log(
		'info',
		'BabbelApi',
		'Story successfully deleted from Babbel API',
		array(
			'story_id' => $story_id,
			'endpoint' => $endpoint,
		)
	);

	return array(
		'success' => true,
		'message' => __( 'Story deleted successfully', 'zw-knabbel-wp' ),
	);
}

/**
 * Restore a soft-deleted story in the Babbel API.
 *
 * @since 0.2.0
 * @param string $story_id The Babbel story ID.
 * @return array{success: bool, message: string} Response with success status and message.
 *
 * @phpstan-return BabbelApiResponse
 */
function babbel_restore_story( string $story_id ): array {
	$credentials = babbel_get_credentials();
	$endpoint    = $credentials['base_url'] . '/stories/' . $story_id;

	// PATCH with deleted_at: null to restore.
	$payload = array( 'deleted_at' => null );

	try {
		$json_body = wp_json_encode( $payload );
		if ( false === $json_body ) {
			return array(
				'success' => false,
				'message' => __( 'Could not encode restore data', 'zw-knabbel-wp' ),
			);
		}
	} catch ( \JsonException $e ) {
		return array(
			'success' => false,
			'message' => __( 'Could not encode restore data', 'zw-knabbel-wp' ),
		);
	}

	$args = array(
		'method'  => 'PATCH',
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => $json_body,
		'timeout' => 30,
	);

	$response = babbel_make_authenticated_request( $endpoint, $args );

	if ( is_wp_error( $response ) ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API restore request failed',
			array(
				'endpoint' => $endpoint,
				'story_id' => $story_id,
				'error'    => $response->get_error_message(),
			)
		);
		return array(
			'success' => false,
			'message' => __( 'API connection failed: ', 'zw-knabbel-wp' ) . $response->get_error_message(),
		);
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	$body          = wp_remote_retrieve_body( $response );

	if ( 200 !== $response_code ) {
		log(
			'error',
			'BabbelApi',
			'Babbel API restore HTTP error',
			array(
				'endpoint'      => $endpoint,
				'story_id'      => $story_id,
				'response_code' => $response_code,
				'response_body' => substr( $body, 0, 500 ),
			)
		);
		return array(
			'success' => false,
			// translators: %1$d is the HTTP status code, %2$s is the response body.
			'message' => sprintf( __( 'API error: HTTP %1$d - %2$s', 'zw-knabbel-wp' ), $response_code, substr( $body, 0, 1000 ) ),
		);
	}

	log(
		'info',
		'BabbelApi',
		'Story successfully restored in Babbel API',
		array(
			'story_id' => $story_id,
			'endpoint' => $endpoint,
		)
	);

	return array(
		'success' => true,
		'message' => __( 'Story restored successfully', 'zw-knabbel-wp' ),
	);
}

/**
 * Clean up session data and debug information.
 *
 * @since 0.1.0
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 */
function babbel_cleanup_sessions(): void {
	// Clean up cached session transients (WordPress native cleanup).
	global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $wpdb->options . ' WHERE option_name LIKE %s OR option_name LIKE %s',
				'_transient_knabbel_session_%',
				'_transient_timeout_knabbel_session_%'
			)
		);
}
