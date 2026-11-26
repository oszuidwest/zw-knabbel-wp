<?php
/**
 * Radio News control in the block editor.
 *
 * Adds a Radio News row to the Summary panel of posts and shows the last
 * Babbel sync warning under it. The row edits the post meta registered in
 * register_post_hooks(), so the block editor saves it through REST.
 *
 * @package KnabbelWP
 * @since   2.0.0
 */

declare(strict_types=1);

namespace KnabbelWP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the block editor hooks.
 *
 * @since 2.0.0
 */
function block_editor_init(): void {
	add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\block_editor_enqueue_assets' );
}

/**
 * Loads the Radio News script on the post editor.
 *
 * The strings and the sync warning are passed in from PHP, so the script needs
 * no build step and uses the plugin's own translations. The warning is the one
 * known when the editor opens.
 *
 * @since 2.0.0
 */
function block_editor_enqueue_assets(): void {
	$post = get_post();
	if ( ! $post || 'post' !== $post->post_type ) {
		return;
	}

	wp_enqueue_script(
		'zw-knabbel-wp-block-editor',
		KNABBEL_PLUGIN_URL . 'assets/block-editor.js',
		array( 'wp-components', 'wp-core-data', 'wp-editor', 'wp-element', 'wp-plugins' ),
		KNABBEL_VERSION,
		true
	);

	$sync_error = get_story_sync_error( get_story_state( $post->ID ) );

	wp_add_inline_script(
		'zw-knabbel-wp-block-editor',
		'window.knabbelBlockEditor = ' . wp_json_encode(
			array(
				'label'       => __( 'Radio News', 'zw-knabbel-wp' ),
				'yes'         => __( 'Yes', 'zw-knabbel-wp' ),
				'no'          => __( 'No', 'zw-knabbel-wp' ),
				'turnOn'      => __( 'Turn Radio News on', 'zw-knabbel-wp' ),
				'turnOff'     => __( 'Turn Radio News off', 'zw-knabbel-wp' ),
				'syncWarning' => null === $sync_error ? null : array(
					'title'   => __( 'Babbel sync warning', 'zw-knabbel-wp' ),
					'message' => $sync_error['message'],
				),
			)
		) . ';',
		'before'
	);
}
