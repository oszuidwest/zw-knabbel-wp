<?php
/**
 * Plugin Name: Knabbel E2E Browser Control
 * Description: Deterministic editor and Action Scheduler controls for browser E2E tests.
 *
 * @package KnabbelWP
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'use_block_editor_for_post_type',
	static fn( bool $use_block_editor, string $post_type ): bool => 'post' === $post_type ? false : $use_block_editor,
	10,
	2
);

add_action(
	'add_meta_boxes_post',
	static function (): void {
		add_meta_box(
			// This exact ID is load-bearing: assets/admin.js and assets/admin.css
			// target #acf-group_5f21a05a18b57 (the production ACF group) to inject
			// the radionieuws checkbox, and the original metabox is hidden
			// unconditionally. Without a box of this ID the checkbox is unusable.
			'acf-group_5f21a05a18b57',
			'Knabbel E2E article fields',
			static function (): void {
				echo '<div class="acf-fields"><div class="acf-field"></div></div>';
			},
			'post',
			'normal',
			'high'
		);
	}
);

/**
 * Run due Action Scheduler actions for a hook and fail loudly on errors.
 *
 * Shared by the AJAX control endpoint below and tests/e2e/suite.php.
 *
 * @param string                    $hook Action hook to run.
 * @param array<string, mixed>|null $args Optional exact action arguments.
 * @throws RuntimeException When an action does not complete successfully.
 */
function knabbel_e2e_run_due_actions( string $hook, ?array $args = null ): void {
	$query = array(
		'hook'         => $hook,
		'group'        => 'zw-knabbel-wp',
		'status'       => ActionScheduler_Store::STATUS_PENDING,
		'date'         => time(),
		'date_compare' => '<=',
		'per_page'     => -1,
	);

	if ( null !== $args ) {
		$query['args'] = $args;
	}

	foreach ( as_get_scheduled_actions( $query, 'ids' ) as $action_id ) {
		ActionScheduler::runner()->process_action( $action_id, 'Knabbel E2E' );
		$status = ActionScheduler::store()->get_status( $action_id );

		if ( ActionScheduler_Store::STATUS_COMPLETE !== $status ) {
			throw new RuntimeException( sprintf( 'Action Scheduler action %d finished with status %s.', (int) $action_id, esc_html( $status ) ) );
		}
	}
}

add_action(
	'wp_ajax_knabbel_e2e_control',
	static function (): void {
		check_ajax_referer( 'knabbel_metabox_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : 'inspect';

		if ( ! $post_id || 'post' !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'A valid post ID is required.' ), 400 );
		}

		if ( 'run' === $operation ) {
			try {
				knabbel_e2e_run_due_actions( 'knabbel_process_story', array( 'post_id' => $post_id ) );
			} catch ( RuntimeException $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
			}
		} elseif ( 'inspect' !== $operation ) {
			wp_send_json_error( array( 'message' => 'Unsupported operation.' ), 400 );
		}

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => 'knabbel_process_story',
				'group'    => 'zw-knabbel-wp',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'args'     => array( 'post_id' => $post_id ),
				'per_page' => -1,
			),
			'ids'
		);

		wp_send_json_success(
			array(
				'pending' => count( $pending ),
				'state'   => KnabbelWP\get_story_state( $post_id ),
			)
		);
	}
);
