<?php
/**
 * Metabox functionality
 *
 * Provides the post editor metabox for sending posts to the Babbel API.
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
 * Register global post status hooks.
 *
 * These hooks must be registered in all contexts (admin, REST API, CLI, cron)
 * to ensure stories are synced regardless of how posts are modified.
 *
 * @since 0.2.0
 */
function register_post_hooks(): void {
	add_action( 'wp_after_insert_post', __NAMESPACE__ . '\\handle_post_saved', 10, 4 );
	add_action( 'wp_trash_post', __NAMESPACE__ . '\\handle_trash_post' );
	add_action( 'untrash_post', __NAMESPACE__ . '\\handle_untrash_post' );
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
 * Renders the Radionieuws metabox content.
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
 * Renders the status metabox content (Debug Mode only).
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
				// status_changed_at is stored as local time string via current_time('mysql')
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
				// Action Scheduler returns UTC timestamp
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
	$send_to_babbel = isset( $_POST['knabbel_send_to_babbel'] ) ? 1 : 0;
	update_post_meta( $post_id, '_zw_knabbel_send_to_babbel', $send_to_babbel );

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

/**
 * Handles post saves for story creation.
 *
 * Creates stories when posts transition to 'future' (scheduled) or 'publish' status.
 * Also handles deletion when transitioning from 'future' back to 'draft'.
 *
 * Uses wp_after_insert_post hook to ensure post meta (checkbox) is saved before reading it.
 *
 * @since 0.2.0
 *
 * @param int           $post_id     Post ID.
 * @param \WP_Post      $post        Post object.
 * @param bool          $update      Whether this is an existing post being updated.
 * @param \WP_Post|null $post_before Post object before the update, or null for new posts.
 */
function handle_post_saved( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
	// Only handle posts.
	if ( 'post' !== $post->post_type ) {
		return;
	}

	// Skip autosaves and revisions.
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	// Extract status values.
	$new_status     = $post->post_status;
	$old_status     = null !== $post_before ? $post_before->post_status : 'new';
	$send_to_babbel = (bool) get_post_meta( $post_id, '_zw_knabbel_send_to_babbel', true );
	$state          = get_story_state( $post_id );
	$status         = $state['status'] ?? '';
	$story_id       = $state['story_id'] ?? '';

	// Handle transition to 'future' (scheduled post) - create story.
	if ( 'future' === $new_status && 'future' !== $old_status && 'publish' !== $old_status ) {
		if ( $send_to_babbel && StoryStatus::Sent->value !== $status ) {
			schedule_story_processing( $post_id );
		}
		return;
	}

	// Handle transition to 'publish' - create story if not already sent.
	if ( 'publish' === $new_status && 'publish' !== $old_status ) {
		if ( $send_to_babbel && StoryStatus::Sent->value !== $status ) {
			schedule_story_processing( $post_id );
		}
		return;
	}

	// Handle date changes for scheduled posts with existing stories (Quick Edit, REST API).
	if ( 'future' === $new_status && 'future' === $old_status ) {
		if ( $story_id && StoryStatus::Sent->value === $status ) {
			$old_date = null !== $post_before ? $post_before->post_date : null;
			if ( $old_date !== $post->post_date ) {
				$dates  = calculate_story_dates( $post->post_date );
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
		return;
	}

	// Handle transition from 'future' to 'draft' - delete story if exists.
	if ( 'draft' === $new_status && 'future' === $old_status ) {
		// Cancel any pending processing jobs.
		\as_unschedule_all_actions( 'knabbel_process_story', array( 'post_id' => $post_id ), 'zw-knabbel-wp' );

		if ( $story_id && StoryStatus::Sent->value === $status ) {
			$result = babbel_delete_story( $story_id );
			if ( $result['success'] ) {
				update_story_state(
					$post_id,
					array(
						'status'  => StoryStatus::Deleted->value,
						'message' => __( 'Story deleted (post unscheduled)', 'zw-knabbel-wp' ),
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
	}
}

/**
 * Handles post being trashed.
 *
 * Deletes the story from Babbel when a post with an existing story is trashed.
 *
 * @since 0.2.0
 *
 * @param int $post_id The post ID being trashed.
 */
function handle_trash_post( int $post_id ): void {
	$post = get_post( $post_id );
	if ( ! $post || 'post' !== $post->post_type ) {
		return;
	}

	// Cancel any pending processing jobs.
	\as_unschedule_all_actions( 'knabbel_process_story', array( 'post_id' => $post_id ), 'zw-knabbel-wp' );

	$state    = get_story_state( $post_id );
	$status   = $state['status'] ?? '';
	$story_id = $state['story_id'] ?? '';

	if ( $story_id && StoryStatus::Sent->value === $status ) {
		$result = babbel_delete_story( $story_id );
		if ( $result['success'] ) {
			update_story_state(
				$post_id,
				array(
					'status'  => StoryStatus::Deleted->value,
					'message' => __( 'Story deleted (post trashed)', 'zw-knabbel-wp' ),
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
}

/**
 * Handles post being restored from trash.
 *
 * Restores the story in Babbel when a post with an existing story_id is untrashed.
 *
 * @since 0.2.0
 *
 * @param int $post_id The post ID being restored.
 */
function handle_untrash_post( int $post_id ): void {
	$post = get_post( $post_id );
	if ( ! $post || 'post' !== $post->post_type ) {
		return;
	}

	$send_to_babbel = (bool) get_post_meta( $post_id, '_zw_knabbel_send_to_babbel', true );
	$state          = get_story_state( $post_id );
	$status         = $state['status'] ?? '';
	$story_id       = $state['story_id'] ?? '';

	// Only restore if checkbox is enabled, we have a story_id, and it was deleted.
	if ( $send_to_babbel && $story_id && StoryStatus::Deleted->value === $status ) {
		$result = babbel_restore_story( $story_id );
		if ( $result['success'] ) {
			update_story_state(
				$post_id,
				array(
					'status'  => StoryStatus::Sent->value,
					'message' => __( 'Story restored in Babbel', 'zw-knabbel-wp' ),
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

/**
 * Schedules story processing via Action Scheduler.
 *
 * Helper function to deduplicate scheduling logic.
 *
 * @since 0.2.0
 *
 * @param int $post_id The post ID to process.
 */
function schedule_story_processing( int $post_id ): void {
	// De-dupe scheduling: skip if an event is already queued for this post.
	if ( \as_has_scheduled_action( 'knabbel_process_story', array( 'post_id' => $post_id ), 'zw-knabbel-wp' ) ) {
		update_story_state(
			$post_id,
			array(
				'status'  => StoryStatus::Scheduled->value,
				'message' => __( 'Processing already scheduled', 'zw-knabbel-wp' ),
			)
		);
		return;
	}

	// Schedule async processing via Action Scheduler.
	$scheduled = \as_schedule_single_action(
		time(),
		'knabbel_process_story',
		array( 'post_id' => $post_id ),
		'zw-knabbel-wp'
	);

	if ( $scheduled ) {
		update_story_state(
			$post_id,
			array(
				'status'  => StoryStatus::Scheduled->value,
				'message' => __( 'Processing scheduled', 'zw-knabbel-wp' ),
			)
		);
	} else {
		update_story_state(
			$post_id,
			array(
				'status'  => StoryStatus::Error->value,
				'message' => __( 'Could not schedule action', 'zw-knabbel-wp' ),
			)
		);
	}
}
