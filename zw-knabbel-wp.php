<?php

/**
 * Plugin Name: ZuidWest Knabbel
 * Plugin URI: https://github.com/oszuidwest/zw-knabbel-wp
 * Description: WordPress plugin om berichten naar de Babbel API te sturen voor het radionieuws. Ondersteunt OpenAI GPT-modellen voor AI-gegenereerde content.
 * Version: 0.1.0
 * Requires at least: 6.8
 * Requires PHP: 8.3
 * Author: Streekomroep ZuidWest
 * Author URI: https://www.zuidwesttv.nl
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: zw-knabbel-wp
 * Domain Path: /languages
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Read version from plugin header (single source of truth).
$knabbel_plugin_data = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
define( 'KNABBEL_VERSION', $knabbel_plugin_data['Version'] );
unset( $knabbel_plugin_data );
define( 'KNABBEL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KNABBEL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load composer autoloader
require_once KNABBEL_PLUGIN_DIR . 'vendor/autoload.php';

// Load Action Scheduler (not PSR-4, needs explicit bootstrap)
require_once KNABBEL_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

// Direct includes
require_once KNABBEL_PLUGIN_DIR . 'includes/story-status.php';
require_once KNABBEL_PLUGIN_DIR . 'includes/weekdays.php';
require_once KNABBEL_PLUGIN_DIR . 'includes/babbel-api.php';
require_once KNABBEL_PLUGIN_DIR . 'includes/openai-handler.php';

/**
 * Initialize plugin hooks and actions.
 */
add_action( 'init', __NAMESPACE__ . '\\init' );
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

/**
 * Checks whether Debug Mode is enabled.
 *
 * @since 0.1.0
 * @return bool True when debug mode is enabled, false otherwise.
 */
function debug_enabled(): bool {
	$options = get_option( 'knabbel_settings' );
	return ! empty( $options['debug_mode'] );
}

/**
 * Centralized WordPress native logging with structured data.
 * Replaces duplicate log_message() methods in BabbelApi and OpenAiHandler classes.
 *
 * @since 0.1.0
 * @param string                  $level     Log level: 'error', 'warning', 'info'
 * @param string               $component Component name: 'BabbelApi', 'OpenAiHandler', etc.
 * @param string               $message   Log message.
 * @param array<string, mixed> $context   Additional context data.
 *
 * @phpstan-param LogContext $context
 */
function log( string $level, string $component, string $message, array $context = array() ): void {
	// Only log when WordPress debug logging is enabled
	if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
		return;
	}

	$log_entry = sprintf(
		'[Knabbel WP] [%s] [%s] %s%s',
		strtoupper( $level ),
		$component,
		$message,
		empty( $context ) ? '' : ' | Context: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE )
	);

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional logging to WP_DEBUG_LOG.
	error_log( $log_entry );

	// Store critical errors for admin display
	if ( 'error' === $level ) {
		$recent_errors   = get_option( 'knabbel_recent_errors', array() );
		$recent_errors[] = array(
			'timestamp' => current_time( 'mysql' ),
			'component' => $component,
			'message'   => $message,
			'context'   => $context,
		);

		// Keep only last 10 errors
		$recent_errors = array_slice( $recent_errors, -10 );
		update_option( 'knabbel_recent_errors', $recent_errors );
	}
}

/**
 * Updates consolidated per-post story state metadata with WordPress native optimization.
 *
 * Stores all state in `_knabbel_story_state` with single database operation.
 * Triggers WordPress action hook for extensibility.
 *
 * @since 0.1.0
 * @param int                                                                   $post_id The post ID.
 * @param array{status?: string, story_id?: string, message?: string}           $updates Partial state updates to apply.
 * @return bool True on successful update, false on failure.
 *
 * @phpstan-param StoryState $updates
 */
function update_story_state( int $post_id, array $updates = array() ): bool {
	// Single read operation
	$meta_value    = get_post_meta( $post_id, '_zw_knabbel_story_state', true );
	$current_state = is_array( $meta_value ) ? $meta_value : array();

	// Merge updates with current state and add timestamp
	$new_state = array_merge(
		$current_state,
		$updates,
		array(
			'status_changed_at' => current_time( 'mysql' ),
			'post_id'           => $post_id,
		)
	);

	// Single write operation for all data
	$success = update_post_meta( $post_id, '_zw_knabbel_story_state', $new_state );

	if ( $success ) {
		/**
		 * Fires when story state is changed.
		 *
		 * @since 0.1.0
		 *
		 * @param int   $post_id       The post ID.
		 * @param array $new_state     The new story state array.
		 * @param array $current_state The previous story state array.
		 */
		do_action( 'knabbel_story_state_changed', $post_id, $new_state, $current_state );
	}

	return (bool) $success;
}

/**
 * Get story state data for a post.
 *
 * @since 0.1.0
 * @param int $post_id The post ID.
 * phpcs:ignore Generic.Files.LineLength.TooLong -- Type annotation.
 * @return array{status?: string, story_id?: string, status_changed_at?: string, message?: string, post_id?: int} Story state data or empty array if none exists.
 *
 * @phpstan-return StoryState
 */
function get_story_state( int $post_id ): array {
	$state = get_post_meta( $post_id, '_zw_knabbel_story_state', true );
	return is_array( $state ) ? $state : array();
}

/**
 * Calculate story dates based on a base date and configured offsets.
 *
 * For published posts, base_date should be 'now'.
 * For scheduled posts, base_date should be the scheduled publish time (post_date).
 *
 * @since 0.2.0
 * @param string $base_date Date string to calculate from (e.g., 'now' or '2025-01-15 10:00:00').
 * @return array{start_date: string, end_date: string, weekdays: int} Story dates and weekdays bitmask.
 */
function calculate_story_dates( string $base_date = 'now' ): array {
	$options      = get_option( 'knabbel_settings' );
	$start_offset = (int) ( $options['start_days_offset'] ?? 1 );
	$end_offset   = (int) ( $options['end_days_offset'] ?? 2 );

	$tz   = wp_timezone();
	$base = new \DateTimeImmutable( $base_date, $tz );

	return array(
		'start_date' => $base->modify( "+{$start_offset} day" )->format( 'Y-m-d' ),
		'end_date'   => $base->modify( "+{$end_offset} day" )->format( 'Y-m-d' ),
		'weekdays'   => settings_to_weekdays_bitmask( $options ),
	);
}

/**
 * Initializes the plugin.
 *
 * Loads text domain and registers hooks.
 *
 * @since 0.1.0
 */
function init(): void {
	load_plugin_textdomain( 'zw-knabbel-wp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Run migrations on upgrade (activation hook doesn't fire on updates).
	migrate_status_changed_at();

	// Register cron hook for async story processing (always, not just admin).
	add_action( 'knabbel_process_story', __NAMESPACE__ . '\\process_story_async', 10, 1 );

	// Register global post hooks for REST API, CLI, and cron support.
	require_once KNABBEL_PLUGIN_DIR . 'includes/admin/metabox.php';
	register_post_hooks();

	if ( is_admin() ) {
		admin_init();
	}
}

/**
 * Initializes admin‑specific functionality.
 *
 * Loads admin functions and registers AJAX actions.
 *
 * @since 0.1.0
 */
function admin_init(): void {
	// Load admin functions directly
	require_once KNABBEL_PLUGIN_DIR . 'includes/admin/settings.php';
	require_once KNABBEL_PLUGIN_DIR . 'includes/admin/metabox.php';

	// Initialize admin functionality
	settings_init();
	metabox_init();

	add_action( 'wp_ajax_knabbel_test_api', __NAMESPACE__ . '\\ajax_test_api' );
}

/**
 * Runs on plugin activation.
 *
 * Sets up default options and cleans legacy metadata.
 *
 * @since 0.1.0
 */
function activate(): void {
		$default_options = array(
			'api_base_url'      => 'https://babbel.example.com/api/v1',
			'api_username'      => '',
			'api_password'      => '',
			'openai_api_key'    => '',
			'openai_model'      => 'gpt-4.1-mini',
			'title_prompt'      => '',
			'speech_prompt'     => '',
			'debug_mode'        => false,
			// Story defaults
			'start_days_offset' => 1,
			'end_days_offset'   => 2,
			'default_status'    => 'draft',
			'weekday_sunday'    => true,
			'weekday_monday'    => true,
			'weekday_tuesday'   => true,
			'weekday_wednesday' => true,
			'weekday_thursday'  => true,
			'weekday_friday'    => true,
			'weekday_saturday'  => true,
		);

		// Explicitly set autoload to true since these settings are needed on every admin page load.
		// WP 6.6+ changed the default autoload behavior from 'yes' to dynamic heuristics.
		add_option( 'knabbel_settings', $default_options, '', true );

		// Cleanup all legacy data on activation
		cleanup_legacy_data();

		// Run data migrations
		migrate_status_changed_at();
}

/**
 * Runs on plugin deactivation.
 *
 * Cleans up sessions and scheduled Action Scheduler jobs.
 *
 * @since 0.1.0
 */
function deactivate(): void {
	babbel_cleanup_sessions();

	// Clear Action Scheduler jobs
	\as_unschedule_all_actions( 'knabbel_process_story', array(), 'zw-knabbel-wp' );
}

/**
 * Comprehensive cleanup of all legacy data from previous plugin versions.
 *
 * Removes legacy meta fields, options, transients and debug data.
 * Safe to run multiple times.
 *
 * @since 0.1.0
 * @global wpdb $wpdb WordPress database abstraction object.
 */
function cleanup_legacy_data(): void {
	global $wpdb;

	// Remove legacy options-based debug keys
	$legacy_options = array(
		'knabbel_last_cron_run',
		'knabbel_last_cron_error',
		'knabbel_last_cron_success',
		'knabbel_last_story_data',
		'knabbel_debug_logs',
		'knabbel_recent_errors',
		// Additional legacy options from older versions
		'knabbel_api_credentials',
		'knabbel_openai_settings',
		'knabbel_cached_settings',
		'knabbel_version_check',
		'knabbel_migration_status',
	);

	foreach ( $legacy_options as $option ) {
		delete_option( $option );
	}

	// Remove all legacy per-post meta keys in single query
	$legacy_meta_keys = array(
		'_zw_knabbel_babbel_status',
		'_zw_knabbel_babbel_error',
		'_zw_knabbel_babbel_story_id',
		'_zw_knabbel_babbel_last_run',
		'_zw_knabbel_babbel_last_success',
		'_zw_knabbel_babbel_last_error',
		'_zw_knabbel_babbel_last_story_data',
		'_zw_knabbel_babbel_debug_payload',
		// Additional legacy meta keys from older plugin versions
		'_zw_knabbel_babbel_processed',
		'_zw_knabbel_babbel_retry_count',
		'_zw_knabbel_babbel_locked',
		'_zw_knabbel_babbel_queued_at',
		'_zw_knabbel_old_status',
		'_zw_knabbel_migration_done',
		'_zw_knabbel_backup_data',
	);

	$placeholders = implode( ',', array_fill( 0, count( $legacy_meta_keys ), '%s' ) );
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholders.
			'DELETE FROM ' . $wpdb->postmeta . ' WHERE meta_key IN (' . $placeholders . ')',
			$legacy_meta_keys
		)
	);

	// Clean up legacy session transients and cached data.
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore Generic.Files.LineLength.TooLong -- SQL query readability.
			'DELETE FROM ' . $wpdb->options . ' WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
			'_transient_knabbel_session_%',
			'_transient_timeout_knabbel_session_%',
			'knabbel_session_%',
			'_transient_knabbel_%',
			'_transient_timeout_knabbel_%'
		)
	);

	// Clean up legacy user meta keys (in case any user-specific data was stored)
	$legacy_user_meta_keys = array(
		'_zw_knabbel_user_preferences',
		'_zw_knabbel_last_activity',
		'_zw_knabbel_permission_cache',
	);

	$user_placeholders = implode( ',', array_fill( 0, count( $legacy_user_meta_keys ), '%s' ) );
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholders.
			'DELETE FROM ' . $wpdb->usermeta . ' WHERE meta_key IN (' . $user_placeholders . ')',
			$legacy_user_meta_keys
		)
	);

	// Clear any legacy cron jobs that might be stuck
	wp_clear_scheduled_hook( 'knabbel_legacy_cleanup' );
	wp_clear_scheduled_hook( 'knabbel_old_process' );
	wp_clear_scheduled_hook( 'knabbel_babbel_process' );

	// Log cleanup completion with detailed stats
	log(
		'info',
		'Cleanup',
		'Comprehensive legacy data cleanup completed on activation',
		array(
			'options_removed'        => count( $legacy_options ),
			'post_meta_keys_removed' => count( $legacy_meta_keys ),
			'user_meta_keys_removed' => count( $legacy_user_meta_keys ),
			'cleanup_timestamp'      => current_time( 'mysql' ),
		)
	);
}

/**
 * Migrates story state data from updated_at to status_changed_at field.
 *
 * This migration runs once to rename the field for clarity.
 * Safe to run multiple times - skips already migrated records.
 *
 * @since 0.1.02
 * @global wpdb $wpdb WordPress database abstraction object.
 */
function migrate_status_changed_at(): void {
	global $wpdb;

	// Check if migration already ran
	$migration_done = get_option( 'knabbel_migration_status_changed_at', false );
	if ( $migration_done ) {
		return;
	}

	// Find all posts with story state
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration.
	$results = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
			'_zw_knabbel_story_state'
		)
	);

	$migrated = 0;
	foreach ( $results as $row ) {
		$state = maybe_unserialize( $row->meta_value );
		if ( ! is_array( $state ) ) {
			continue;
		}

		// Skip if already migrated or no updated_at field
		if ( isset( $state['status_changed_at'] ) || ! isset( $state['updated_at'] ) ) {
			continue;
		}

		// Rename the field
		$state['status_changed_at'] = $state['updated_at'];
		unset( $state['updated_at'] );

		update_post_meta( (int) $row->post_id, '_zw_knabbel_story_state', $state );
		++$migrated;
	}

	// Mark migration as complete
	update_option( 'knabbel_migration_status_changed_at', true );

	if ( $migrated > 0 ) {
		log(
			'info',
			'Migration',
			'Migrated updated_at to status_changed_at',
			array( 'records_migrated' => $migrated )
		);
	}
}

/**
 * Handles AJAX request to test the Babbel API connection.
 *
 * @since 0.1.0
 */
function ajax_test_api(): void {
	check_ajax_referer( 'knabbel_test_api_nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions', 'zw-knabbel-wp' ) );
	}

	$result = babbel_test_connection();

	if ( $result['success'] ) {
		wp_send_json_success( $result['message'] );
	} else {
		wp_send_json_error( $result['message'] );
	}
}

/**
 * Processes a story asynchronously via Action Scheduler.
 *
 * Generates AI content and sends it to the Babbel API.
 *
 * @since 0.1.0
 * @param int|array{post_id?: int} $post_id_or_args The WordPress post ID or Action Scheduler args array.
 */
function process_story_async( int|array $post_id_or_args ): void {
	// Handle both WP-Cron (int) and Action Scheduler (array) formats
	$post_id = is_array( $post_id_or_args ) ? (int) ( $post_id_or_args['post_id'] ?? 0 ) : $post_id_or_args;

	if ( ! $post_id ) {
		log( 'error', 'CronProcessor', 'Invalid post_id in process_story_async' );
		return;
	}

	// Debug logging for cron execution
	log( 'info', 'CronProcessor', 'Starting async story processing', array( 'post_id' => $post_id ) );

		// Send-once safety: if already sent, do nothing
		$existing_state = get_post_meta( $post_id, '_zw_knabbel_story_state', true );
	if ( is_array( $existing_state ) && isset( $existing_state['status'] ) && \KnabbelWP\StoryStatus::Sent->value === $existing_state['status'] ) {
		update_story_state(
			$post_id,
			array(
				'status'  => \KnabbelWP\StoryStatus::Sent->value,
				'message' => __( 'Already sent — skipping', 'zw-knabbel-wp' ),
			)
		);
		return;
	}

		$post = get_post( $post_id );
	if ( ! $post ) {
		update_story_state(
			$post_id,
			array(
				'status'  => \KnabbelWP\StoryStatus::Error->value,
				'message' => __( 'Post not found', 'zw-knabbel-wp' ) . ' (ID: ' . $post_id . ')',
			)
		);
		return;
	}

	// Check if still enabled for this post.
	$send_to_babbel = get_post_meta( $post_id, '_zw_knabbel_send_to_babbel', true );
	if ( ! $send_to_babbel ) {
		update_story_state(
			$post_id,
			array(
				'status'  => \KnabbelWP\StoryStatus::Error->value,
				'message' => __( 'Send to Babbel is disabled for this post', 'zw-knabbel-wp' ),
			)
		);
		return;
	}

	// Update status to processing.
	update_story_state(
		$post_id,
		array(
			'status'  => \KnabbelWP\StoryStatus::Processing->value,
			'message' => __( 'Story is being processed...', 'zw-knabbel-wp' ),
		)
	);

	$content = wp_strip_all_tags( $post->post_content );

	// Generate title.
	$title = openai_generate_content( $content, 'title' );
	if ( null === $title ) {
		update_story_state(
			$post_id,
			array(
				'status'  => \KnabbelWP\StoryStatus::Error->value,
				'message' => __( 'Could not generate title', 'zw-knabbel-wp' ),
			)
		);
		return;
	}

	// Generate speech text.
	$speech_text = openai_generate_content( $content, 'speech' );
	if ( null === $speech_text ) {
		update_story_state(
			$post_id,
			array(
				'status'  => \KnabbelWP\StoryStatus::Error->value,
				'message' => __( 'Could not generate speech text', 'zw-knabbel-wp' ),
			)
		);
		return;
	}

	// Prepare story data using configurable defaults.
	$options        = get_option( 'knabbel_settings' );
	$default_status = $options['default_status'] ?? 'draft';

	// Calculate dates based on post status:
	// - For scheduled posts (future): use the scheduled publish time (post_date)
	// - For published posts: use current time
	$post_status = get_post_status( $post_id );
	$base_date   = 'future' === $post_status ? $post->post_date : 'now';
	$dates       = calculate_story_dates( $base_date );

	$story_data = array(
		'title'      => $title,
		'text'       => $speech_text,
		'start_date' => $dates['start_date'],
		'end_date'   => $dates['end_date'],
		'status'     => $default_status,
		'weekdays'   => $dates['weekdays'],
		'metadata'   => array(
			'wordpress_id'         => $post_id,
			'original_speech_text' => $speech_text,
		),
	);

	// Send to Babbel API.
	$result = babbel_create_story( $story_data );

	if ( $result['success'] ) {
		update_story_state(
			$post_id,
			array(
				'status'                => \KnabbelWP\StoryStatus::Sent->value,
				'story_id'              => $result['story_id'],
				'message'               => __( 'Story created successfully', 'zw-knabbel-wp' ),
				'generated_title'       => $title,
				'generated_speech_text' => $speech_text,
			)
		);
	} else {
		update_story_state(
			$post_id,
			array(
				'status'  => \KnabbelWP\StoryStatus::Error->value,
				'message' => $result['message'],
			)
		);
	}
}
