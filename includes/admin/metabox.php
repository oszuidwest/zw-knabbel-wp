<?php
/**
 * Debug status metabox for the post editor.
 *
 * Shows the story state of a post in Debug Mode. The Radio News control is in
 * includes/admin/block-editor.php. This file is only loaded in admin context.
 *
 * @package KnabbelWP
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the admin-only metabox hooks.
 *
 * @since 0.1.0
 * @since 2.0.0 No longer adds the Publish box control and its save handler.
 */
function metabox_init(): void {
	add_action( 'add_meta_boxes', __NAMESPACE__ . '\\metabox_add_status' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\metabox_enqueue_assets' );
}

/**
 * Enqueues assets for post edit screens.
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
}

/**
 * Adds the per-post status metabox in Debug Mode.
 *
 * @since 0.1.0
 */
function metabox_add_status(): void {
	if ( ! debug_enabled() ) {
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
 * Displays status details in Debug Mode.
 *
 * @since 0.1.0
 *
 * @param \WP_Post $post The current post object.
 */
function metabox_render_status( \WP_Post $post ): void {
		$state      = get_post_meta( $post->ID, '_zw_knabbel_story_state', true );
		$status     = is_array( $state ) && ! empty( $state['status'] ) ? $state['status'] : '';
		$story_id   = is_array( $state ) && ! empty( $state['story_id'] ) ? $state['story_id'] : '';
		$updated    = is_array( $state ) && ! empty( $state['status_changed_at'] ) ? $state['status_changed_at'] : '';
		$message    = is_array( $state ) && ! empty( $state['message'] ) ? $state['message'] : '';
		$sync_error = is_array( $state ) ? get_story_sync_error( $state ) : null;
		$scheduled  = \as_next_scheduled_action( 'knabbel_process_story', array( 'post_id' => $post->ID ), 'zw-knabbel-wp' );
	?>
		<ul class="knabbel-status-list">
			<li>
				<strong><?php esc_html_e( 'Status', 'zw-knabbel-wp' ); ?>:</strong>
				<?php
				$status_slug = $status ? sanitize_key( $status ) : 'none';
				$label       = $status ? ( StoryStatus::tryFrom( $status )?->label() ?? $status ) : __( '—', 'zw-knabbel-wp' );
				echo '<span class="knabbel-status-badge ' . esc_attr( $status_slug ) . '">' . esc_html( $label ) . '</span>';
				?>
			</li>
			<?php if ( ! empty( $updated ) ) : ?>
			<li class="knabbel-status-muted">
				<?php esc_html_e( 'Last status change:', 'zw-knabbel-wp' ); ?>
				<?php echo esc_html( format_stored_datetime( $updated ) ); ?>
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
				echo esc_html( format_stored_datetime( (int) $scheduled ) );
				?>
			</li>
			<?php endif; ?>
			<?php if ( null !== $sync_error ) : ?>
			<li>
				<strong><?php esc_html_e( 'Last sync error', 'zw-knabbel-wp' ); ?>:</strong>
				<div class="knabbel-sync-warning">
					<?php echo esc_html( $sync_error['message'] ); ?>
					<div class="knabbel-status-muted">
						<?php if ( ! empty( $sync_error['operation'] ) ) : ?>
							<?php esc_html_e( 'Operation', 'zw-knabbel-wp' ); ?>:
							<code><?php echo esc_html( $sync_error['operation'] ); ?></code>
						<?php endif; ?>
						<?php if ( ! empty( $sync_error['occurred_at'] ) ) : ?>
							<?php echo ' · ' . esc_html( format_stored_datetime( $sync_error['occurred_at'] ) ); ?>
						<?php endif; ?>
					</div>
				</div>
			</li>
			<?php endif; ?>
			<?php if ( ! empty( $message ) && null === $sync_error ) : ?>
			<li>
				<strong><?php esc_html_e( 'Message', 'zw-knabbel-wp' ); ?>:</strong>
				<div class="knabbel-pre"><?php echo esc_html( $message ); ?></div>
			</li>
			<?php endif; ?>
		</ul>
		<?php
}
