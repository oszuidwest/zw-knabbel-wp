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
			'acf-group_5f21a05a18b57',
			'Knabbel E2E article fields',
			static function (): void {
				?>
				<div class="acf-fields">
					<div class="acf-field">
						<div class="acf-label"><label>Test field</label></div>
						<div class="acf-input"><input type="checkbox" disabled /></div>
					</div>
				</div>
				<?php
			},
			'post',
			'normal',
			'high'
		);
	}
);

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

		$query = array(
			'hook'     => 'knabbel_process_story',
			'group'    => 'zw-knabbel-wp',
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'args'     => array( 'post_id' => $post_id ),
			'per_page' => -1,
		);

		if ( 'run' === $operation ) {
			$due_query                 = $query;
			$due_query['date']         = time();
			$due_query['date_compare'] = '<=';
			$action_ids                = as_get_scheduled_actions( $due_query, 'ids' );

			foreach ( $action_ids as $action_id ) {
				ActionScheduler::runner()->process_action( $action_id, 'Knabbel browser E2E' );
			}
		} elseif ( 'inspect' !== $operation ) {
			wp_send_json_error( array( 'message' => 'Unsupported operation.' ), 400 );
		}

		$pending = as_get_scheduled_actions( $query, 'ids' );
		$state   = function_exists( 'KnabbelWP\\get_story_state' ) ? KnabbelWP\get_story_state( $post_id ) : array();

		wp_send_json_success(
			array(
				'pending' => count( $pending ),
				'state'   => $state,
			)
		);
	}
);
