<?php
/**
 * Global post hooks for story synchronization
 *
 * These hooks run in all contexts (admin, REST API, CLI, cron) to ensure
 * stories are synced regardless of how posts are modified.
 *
 * @package KnabbelWP
 * @since   0.2.0
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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

	// Meta change hooks for REST API and CLI support.
	add_filter( 'update_post_metadata', __NAMESPACE__ . '\\capture_checkbox_old_value', 10, 3 );
	add_filter( 'delete_post_metadata', __NAMESPACE__ . '\\capture_checkbox_old_value_before_delete', 10, 3 );
	add_action( 'updated_post_meta', __NAMESPACE__ . '\\handle_checkbox_meta_updated', 10, 4 );
	add_action( 'added_post_meta', __NAMESPACE__ . '\\handle_checkbox_meta_added', 10, 4 );
	add_action( 'deleted_post_meta', __NAMESPACE__ . '\\handle_checkbox_meta_deleted', 10, 3 );
}

/**
 * Captures the old checkbox value before meta update.
 *
 * Stores the previous value in a global so handle_checkbox_meta_updated() can detect changes.
 *
 * @since 0.2.0
 *
 * @param null|bool $check     Whether to allow updating metadata.
 * @param int       $object_id Post ID.
 * @param string    $meta_key  Meta key.
 * @return null|bool Unmodified $check to allow normal update.
 */
function capture_checkbox_old_value( $check, $object_id, $meta_key ) {
	if ( '_zw_knabbel_send_to_babbel' !== $meta_key ) {
		return $check;
	}

	// Store current value before it gets updated.
	$GLOBALS['knabbel_checkbox_old_value'] = get_post_meta( $object_id, $meta_key, true );

	return $check;
}

/**
 * Captures the old checkbox value before meta deletion.
 *
 * Stores the previous value in a global so handle_checkbox_meta_deleted() can detect
 * if the checkbox was enabled before deletion. This is necessary because the
 * deleted_post_meta action receives an empty $meta_value when delete_post_meta()
 * is called without a specific value.
 *
 * @since 0.2.0
 *
 * @param null|bool $check     Whether to allow deleting metadata.
 * @param int       $object_id Post ID.
 * @param string    $meta_key  Meta key.
 * @return null|bool Unmodified $check to allow normal delete.
 */
function capture_checkbox_old_value_before_delete( $check, $object_id, $meta_key ) {
	if ( '_zw_knabbel_send_to_babbel' !== $meta_key ) {
		return $check;
	}

	// Store current value before it gets deleted.
	$GLOBALS['knabbel_checkbox_old_value_for_delete'] = get_post_meta( $object_id, $meta_key, true );

	return $check;
}

/**
 * Handles checkbox meta being updated via REST API or CLI.
 *
 * @since 0.2.0
 *
 * @param int    $meta_id    Meta ID.
 * @param int    $post_id    Post ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value New meta value.
 */
function handle_checkbox_meta_updated( $meta_id, $post_id, $meta_key, $meta_value ): void {
	if ( '_zw_knabbel_send_to_babbel' !== $meta_key ) {
		return;
	}

	// Skip if metabox_save() already handled this (prevents double processing).
	if ( ! empty( $GLOBALS['knabbel_skip_meta_sync'] ) ) {
		return;
	}

	$old_value = $GLOBALS['knabbel_checkbox_old_value'] ?? '';
	$new_value = $meta_value;

	// Clean up.
	unset( $GLOBALS['knabbel_checkbox_old_value'] );

	// No change, nothing to do.
	if ( (bool) $old_value === (bool) $new_value ) {
		return;
	}

	handle_checkbox_change( $post_id, (bool) $old_value, (bool) $new_value );
}

/**
 * Handles checkbox meta being added via REST API or CLI.
 *
 * @since 0.2.0
 *
 * @param int    $meta_id    Meta ID.
 * @param int    $post_id    Post ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Meta value.
 */
function handle_checkbox_meta_added( $meta_id, $post_id, $meta_key, $meta_value ): void {
	if ( '_zw_knabbel_send_to_babbel' !== $meta_key ) {
		return;
	}

	// Skip if metabox_save() already handled this.
	if ( ! empty( $GLOBALS['knabbel_skip_meta_sync'] ) ) {
		return;
	}

	// Meta was added (didn't exist before), so old value is effectively false.
	if ( (bool) $meta_value ) {
		handle_checkbox_change( $post_id, false, true );
	}
}

/**
 * Handles checkbox meta being deleted via REST API or CLI.
 *
 * Uses the value captured by capture_checkbox_old_value_before_delete() instead
 * of relying on the hook's $meta_value parameter, which is empty when
 * delete_post_meta() is called without a specific value to delete.
 *
 * @since 0.2.0
 *
 * @param int[]  $meta_ids  Array of deleted meta IDs (unused but required by hook).
 * @param int    $post_id   Post ID.
 * @param string $meta_key  Meta key.
 */
function handle_checkbox_meta_deleted( $meta_ids, $post_id, $meta_key ): void {
	if ( '_zw_knabbel_send_to_babbel' !== $meta_key ) {
		return;
	}

	// Skip if metabox_save() already handled this.
	if ( ! empty( $GLOBALS['knabbel_skip_meta_sync'] ) ) {
		return;
	}

	// Use captured value from before the delete (more reliable than $meta_value).
	$old_value = $GLOBALS['knabbel_checkbox_old_value_for_delete'] ?? '';
	unset( $GLOBALS['knabbel_checkbox_old_value_for_delete'] );

	// Only act if the checkbox was actually enabled before deletion.
	if ( (bool) $old_value ) {
		handle_checkbox_change( $post_id, true, false );
	}
}

/**
 * Handles checkbox state change from any source (metabox, REST API, CLI).
 *
 * @since 0.2.0
 *
 * @param int  $post_id     Post ID.
 * @param bool $was_enabled Previous checkbox state.
 * @param bool $is_enabled  New checkbox state.
 */
function handle_checkbox_change( int $post_id, bool $was_enabled, bool $is_enabled ): void {
	$post = get_post( $post_id );
	if ( ! $post || 'post' !== $post->post_type ) {
		return;
	}

	$post_status = get_post_status( $post_id );
	$state       = get_story_state( $post_id );
	$status      = $state['status'] ?? '';
	$story_id    = $state['story_id'] ?? '';

	// Checkbox disabled.
	if ( $was_enabled && ! $is_enabled ) {
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
			delete_post_meta( $post_id, '_zw_knabbel_story_state' );
		}
		return;
	}

	// Checkbox enabled on already published/scheduled post.
	if ( ! $was_enabled && $is_enabled ) {
		// Skip if processing is already in progress or complete.
		$skip_statuses = array( StoryStatus::Sent->value, StoryStatus::Scheduled->value, StoryStatus::Processing->value );
		if ( in_array( $post_status, array( 'publish', 'future' ), true ) && ! in_array( $status, $skip_statuses, true ) ) {
			schedule_story_processing( $post_id );
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

	// Statuses that indicate processing is already in progress or complete.
	$skip_statuses = array( StoryStatus::Sent->value, StoryStatus::Scheduled->value, StoryStatus::Processing->value );

	// Handle transition to 'future' (scheduled post) - create story.
	if ( 'future' === $new_status && 'future' !== $old_status && 'publish' !== $old_status ) {
		if ( $send_to_babbel && ! in_array( $status, $skip_statuses, true ) ) {
			schedule_story_processing( $post_id );
		}
		return;
	}

	// Handle transition to 'publish' - create story if not already sent.
	if ( 'publish' === $new_status && 'publish' !== $old_status ) {
		if ( $send_to_babbel && ! in_array( $status, $skip_statuses, true ) ) {
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
