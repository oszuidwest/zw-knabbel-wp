<?php
/**
 * Admin metabox functionality
 *
 * Provides the post editor metabox UI for sending posts to the Babbel API.
 * This file is only loaded in admin context.
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
 * Initialize admin-only metabox functionality.
 *
 * Registers hooks for the post editor UI. Must only be called in admin context.
 *
 * @since 0.1.0
 */
function metabox_init(): void {
	add_action( 'add_meta_boxes', __NAMESPACE__ . '\\metabox_add' );
	add_action( 'add_meta_boxes', __NAMESPACE__ . '\\metabox_add_status' );
	add_action( 'save_post', __NAMESPACE__ . '\\metabox_save' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\metabox_enqueue_assets' );
}

/**
 * Enqueue admin assets for post edit screens.
 *
 * @since 0.1.0
 *
 * @param string $hook The current admin page hook.
 */
function metabox_enqueue_assets( string $hook ): void {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	wp_enqueue_style(
		'zw-knabbel-wp-admin',
		KNABBEL_PLUGIN_URL . 'assets/admin.css',
		array(),
		KNABBEL_VERSION
	);

	wp_enqueue_script(
		'zw-knabbel-wp-admin',
		KNABBEL_PLUGIN_URL . 'assets/admin.js',
		array(),
		KNABBEL_VERSION,
		true
	);
}

/**
 * Adds the Radionieuws metabox to the post edit screen.
 *
 * @since 0.1.0
 */
function metabox_add(): void {
	add_meta_box(
		'knabbel-radionieuws',
		__( 'Radio News', 'zw-knabbel-wp' ),
		__NAMESPACE__ . '\\metabox_render',
		'post',
		'side',
		'high'
	);
}

/**
 * Adds the per-post status metabox when Debug Mode is enabled.
 *
 * @since 0.1.0
 */
function metabox_add_status(): void {
	$options    = get_option( 'knabbel_settings' );
	$debug_mode = isset( $options['debug_mode'] ) ? (bool) $options['debug_mode'] : false;
	if ( ! $debug_mode ) {
		return;
	}

	add_meta_box(
		'knabbel-status',
		__( 'Knabbel Status', 'zw-knabbel-wp' ),
		__NAMESPACE__ . '\\metabox_render_status',
		'post',
		'side',
		'default'
	);
}

/**
 * Displays the Radionieuws metabox content.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The current post object.
 */
function metabox_render( \WP_Post $post ): void {
		wp_nonce_field( 'knabbel_metabox_nonce', 'knabbel_nonce' );

		$send_to_babbel = get_post_meta( $post->ID, '_zw_knabbel_send_to_babbel', true );

		echo '<p>';
		echo '<label for="knabbel_send_to_babbel">';
		echo '<input type="checkbox" id="knabbel_send_to_babbel" name="knabbel_send_to_babbel" value="1" ' . checked( 1, $send_to_babbel, false ) . ' />';
		echo ' ' . esc_html__( 'Radio News', 'zw-knabbel-wp' );
		echo '</label>';
		echo '</p>';
}

/**
 * Displays the status metabox content (Debug Mode only).
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The current post object.
 */
function metabox_render_status( \WP_Post $post ): void {
		$state     = get_post_meta( $post->ID, '_zw_knabbel_story_state', true );
		$status    = is_array( $state ) && ! empty( $state['status'] ) ? $state['status'] : '';
		$story_id  = is_array( $state ) && ! empty( $state['story_id'] ) ? $state['story_id'] : '';
		$updated   = is_array( $state ) && ! empty( $state['status_changed_at'] ) ? $state['status_changed_at'] : '';
		$message   = is_array( $state ) && ! empty( $state['message'] ) ? $state['message'] : '';
		$scheduled = \as_next_scheduled_action( 'knabbel_process_story', array( 'post_id' => $post->ID ), 'zw-knabbel-wp' );
	?>
		<ul class="knabbel-status-list">
			<li>
				<strong><?php esc_html_e( 'Status', 'zw-knabbel-wp' ); ?>:</strong>
				<?php
				$status_slug = $status ? sanitize_key( $status ) : 'none';
				$label_map   = array(
					'scheduled'  => __( 'Scheduled', 'zw-knabbel-wp' ),
					'processing' => __( 'Processing', 'zw-knabbel-wp' ),
					'sent'       => __( 'Sent', 'zw-knabbel-wp' ),
					'error'      => __( 'Error', 'zw-knabbel-wp' ),
					'deleted'    => __( 'Deleted', 'zw-knabbel-wp' ),
				);
				$label       = $status && isset( $label_map[ $status ] ) ? $label_map[ $status ] : ( $status ? $status : __( '—', 'zw-knabbel-wp' ) );
				echo '<span class="knabbel-status-badge ' . esc_attr( $status_slug ) . '">' . esc_html( $label ) . '</span>';
				?>
			</li>
			<?php if ( ! empty( $updated ) ) : ?>
			<li class="knabbel-status-muted">
				<?php esc_html_e( 'Last status change:', 'zw-knabbel-wp' ); ?>
				<?php
				// status_changed_at is stored as local time string via current_time('mysql').
				$updated_ts = strtotime( $updated . ' ' . wp_timezone_string() );
				echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $updated_ts ) );
				?>
			</li>
			<?php endif; ?>
			<?php if ( ! empty( $story_id ) ) : ?>
			<li>
				<strong><?php esc_html_e( 'Story ID', 'zw-knabbel-wp' ); ?>:</strong>
				<code><?php echo esc_html( $story_id ); ?></code>
			</li>
			<?php endif; ?>
			<?php if ( ! empty( $scheduled ) ) : ?>
			<li class="knabbel-status-muted">
				<?php esc_html_e( 'Scheduled:', 'zw-knabbel-wp' ); ?>
				<?php
				// Action Scheduler returns UTC timestamp.
				echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $scheduled ) );
				?>
			</li>
			<?php endif; ?>
			<?php if ( ! empty( $message ) ) : ?>
			<li>
				<strong><?php esc_html_e( 'Message', 'zw-knabbel-wp' ); ?>:</strong>
				<div class="knabbel-pre"><?php echo esc_html( $message ); ?></div>
			</li>
			<?php endif; ?>
		</ul>
		<?php
}

/**
 * Saves metabox data and handles checkbox state changes.
 *
 * This function handles:
 * - Saving the checkbox meta value
 * - Deleting from Babbel when checkbox is unchecked
 * - Updating dates in Babbel when checkbox is checked and story exists
 *
 * Story creation is handled by handle_post_saved() to support scheduled posts.
 *
 * @since 0.1.0
 *
 * @param int $post_id The post ID being saved.
 */
function metabox_save( int $post_id ): void {
	if (
		! isset( $_POST['knabbel_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['knabbel_nonce'] ) ), 'knabbel_metabox_nonce' )
	) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Get previous checkbox state before updating.
	$was_enabled = (bool) get_post_meta( $post_id, '_zw_knabbel_send_to_babbel', true );

	// Save new checkbox state.
	// Set flag to prevent meta hooks from double-processing this change.
	$send_to_babbel                    = isset( $_POST['knabbel_send_to_babbel'] ) ? 1 : 0;
	$GLOBALS['knabbel_skip_meta_sync'] = true;
	update_post_meta( $post_id, '_zw_knabbel_send_to_babbel', $send_to_babbel );
	unset( $GLOBALS['knabbel_skip_meta_sync'] );

	// Get current story state.
	$state    = get_story_state( $post_id );
	$status   = $state['status'] ?? '';
	$story_id = $state['story_id'] ?? '';

	// Handle checkbox being unchecked.
	if ( $was_enabled && ! $send_to_babbel ) {
		// Cancel any pending processing jobs.
		\as_unschedule_all_actions( 'knabbel_process_story', array( 'post_id' => $post_id ), 'zw-knabbel-wp' );

		// Delete from Babbel if story was sent.
		if ( $story_id && StoryStatus::Sent->value === $status ) {
			$result = babbel_delete_story( $story_id );
			if ( $result['success'] ) {
				update_story_state(
					$post_id,
					array(
						'status'  => StoryStatus::Deleted->value,
						'message' => __( 'Story deleted from Babbel', 'zw-knabbel-wp' ),
					)
				);
			} else {
				update_story_state(
					$post_id,
					array(
						'status'  => StoryStatus::Error->value,
						'message' => $result['message'],
					)
				);
			}
		} elseif ( in_array( $status, array( StoryStatus::Scheduled->value, StoryStatus::Processing->value ), true ) ) {
			// Clear pending state if job was cancelled.
			delete_post_meta( $post_id, '_zw_knabbel_story_state' );
		}
		return;
	}

	// Handle checkbox being enabled on already published/scheduled post - create story.
	if ( ! $was_enabled && $send_to_babbel && StoryStatus::Sent->value !== $status ) {
		$post_status = get_post_status( $post_id );
		if ( in_array( $post_status, array( 'publish', 'future' ), true ) ) {
			schedule_story_processing( $post_id );
		}
		return;
	}

	// Handle updates when checkbox is checked and story exists.
	if ( $send_to_babbel && $story_id && StoryStatus::Sent->value === $status ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		// Calculate dates based on post status.
		$post_status = get_post_status( $post_id );
		$base_date   = 'future' === $post_status ? $post->post_date : 'now';
		$dates       = calculate_story_dates( $base_date );

		$result = babbel_update_story(
			$story_id,
			array(
				'start_date' => $dates['start_date'],
				'end_date'   => $dates['end_date'],
				'weekdays'   => $dates['weekdays'],
			)
		);

		if ( $result['success'] ) {
			update_story_state(
				$post_id,
				array(
					'status'  => StoryStatus::Sent->value,
					'message' => __( 'Story dates updated in Babbel', 'zw-knabbel-wp' ),
				)
			);
		} else {
			update_story_state(
				$post_id,
				array(
					'status'  => StoryStatus::Error->value,
					'message' => $result['message'],
				)
			);
		}
	}
}
