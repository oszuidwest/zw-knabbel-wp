<?php
/**
 * Radio News control in the block editor.
 *
 * Adds a Radio News row to the Summary panel of posts and shows the last
 * Babbel sync error under it and as an editor notice. The script edits the post
 * meta and reads the knabbel_sync_warning REST field, both registered in
 * includes/post-hooks.php. Its styles are in assets/admin.css, loaded by
 * metabox_enqueue_assets().
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
 * The strings come from PHP, so the plugin's PO file translates them and no
 * JSON translation files are needed.
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
		array( 'wp-components', 'wp-core-data', 'wp-data', 'wp-editor', 'wp-element', 'wp-notices', 'wp-plugins' ),
		KNABBEL_VERSION,
		true
	);

	wp_localize_script(
		'zw-knabbel-wp-block-editor',
		'knabbelBlockEditor',
		array(
			'label'            => __( 'Radio News', 'zw-knabbel-wp' ),
			'yes'              => __( 'Yes', 'zw-knabbel-wp' ),
			'no'               => __( 'No', 'zw-knabbel-wp' ),
			'syncWarningTitle' => __( 'Babbel was not updated', 'zw-knabbel-wp' ),
			'syncRetry'        => __( 'Save the post to try again.', 'zw-knabbel-wp' ),
		)
	);
}
