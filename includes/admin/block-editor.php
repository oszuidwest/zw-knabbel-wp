<?php
/**
 * Radio News control in the block editor.
 *
 * Adds a Radio News row to the Summary panel of posts and shows the last
 * Babbel sync warning under it. The row edits the post meta and reads the
 * knabbel_sync_warning REST field, both registered in includes/post-hooks.php.
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
 * The strings come from PHP, so the script needs no build step and uses the
 * plugin's own translations.
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

	wp_localize_script(
		'zw-knabbel-wp-block-editor',
		'knabbelBlockEditor',
		array(
			'label'            => __( 'Radio News', 'zw-knabbel-wp' ),
			'yes'              => __( 'Yes', 'zw-knabbel-wp' ),
			'no'               => __( 'No', 'zw-knabbel-wp' ),
			'syncWarningTitle' => __( 'Babbel sync warning', 'zw-knabbel-wp' ),
		)
	);
}
