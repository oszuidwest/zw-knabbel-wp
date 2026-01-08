<?php
/**
 * Settings administration functionality
 *
 * Manages plugin settings page, field registration, sanitization, and validation.
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
 * Initialize settings functionality.
 *
 * @since 0.1.0
 */
function settings_init(): void {
	add_action( 'admin_menu', __NAMESPACE__ . '\\settings_add_admin_menu' );
	add_action( 'admin_init', __NAMESPACE__ . '\\settings_register_settings' );
}

/**
 * Adds the admin menu page.
 *
 * @since 0.1.0
 */
function settings_add_admin_menu(): void {
	add_options_page(
		__( 'ZuidWest Knabbel Settings', 'zw-knabbel-wp' ),
		__( 'ZuidWest Knabbel', 'zw-knabbel-wp' ),
		'manage_options',
		'zw-knabbel-wp-settings',
		__NAMESPACE__ . '\\settings_page'
	);
}

/**
 * Registers settings with WordPress Settings API.
 *
 * Only registers the settings group and sanitization callback.
 * Field rendering is handled manually in settings_page().
 *
 * @since 0.1.0
 */
function settings_register_settings(): void {
	register_setting(
		'knabbel_settings_group',
		'knabbel_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize_settings',
			'default'           => array(
				'api_base_url'      => '',
				'api_username'      => '',
				'api_password'      => '',
				'openai_api_key'    => '',
				'openai_model'      => 'gpt-4.1-mini',
				'title_prompt'      => '',
				'speech_prompt'     => '',
				'debug_mode'        => false,
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
			),
		)
	);
}

/**
 * Sanitizes the settings array before saving to the database.
 *
 * @since 0.1.0
 * @param array<string, mixed> $input The raw input array from the form.
 * @return array<string, mixed> The sanitized settings array.
 */
function sanitize_settings( array $input ): array {
	$sanitized = array();

	// URL fields.
	if ( isset( $input['api_base_url'] ) ) {
		$sanitized['api_base_url'] = esc_url_raw( rtrim( $input['api_base_url'], '/' ) );
	}

	// Text fields.
	$text_fields = array( 'api_username', 'api_password', 'openai_api_key', 'openai_model' );
	foreach ( $text_fields as $field ) {
		if ( isset( $input[ $field ] ) ) {
			$sanitized[ $field ] = sanitize_text_field( $input[ $field ] );
		}
	}

	// Textarea fields (prompts) - preserve newlines.
	$textarea_fields = array( 'title_prompt', 'speech_prompt' );
	foreach ( $textarea_fields as $field ) {
		if ( isset( $input[ $field ] ) ) {
			$sanitized[ $field ] = sanitize_textarea_field( $input[ $field ] );
		}
	}

	// Integer fields.
	if ( isset( $input['start_days_offset'] ) ) {
		$sanitized['start_days_offset'] = absint( $input['start_days_offset'] );
	}
	if ( isset( $input['end_days_offset'] ) ) {
		$sanitized['end_days_offset'] = absint( $input['end_days_offset'] );
	}

	// Select field with allowed values.
	if ( isset( $input['default_status'] ) ) {
		$sanitized['default_status'] = in_array( $input['default_status'], array( 'draft', 'active' ), true )
			? $input['default_status']
			: 'draft';
	}

	// Boolean/checkbox fields.
	$sanitized['debug_mode'] = ! empty( $input['debug_mode'] ) ? 1 : 0;

	// Weekday checkboxes.
	$weekdays = array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );
	foreach ( $weekdays as $day ) {
		$field_name               = 'weekday_' . $day;
		$sanitized[ $field_name ] = ! empty( $input[ $field_name ] ) ? 1 : 0;
	}

	return $sanitized;
}

/**
 * Outputs the admin settings page with card-based layout.
 *
 * @since 0.1.0
 */
function settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to view this page.', 'zw-knabbel-wp' ) );
	}

	// Enqueue admin CSS.
	wp_enqueue_style(
		'zw-knabbel-wp-admin',
		KNABBEL_PLUGIN_URL . 'assets/admin.css',
		array(),
		KNABBEL_VERSION
	);

	// Enqueue admin JS with localized strings.
	wp_enqueue_script(
		'zw-knabbel-wp-admin',
		KNABBEL_PLUGIN_URL . 'assets/admin.js',
		array( 'jquery' ),
		KNABBEL_VERSION,
		true
	);

	wp_localize_script(
		'zw-knabbel-wp-admin',
		'knabbel_admin',
		array(
			'nonce'        => wp_create_nonce( 'knabbel_test_api_nonce' ),
			'testing_text' => __( 'Testing...', 'zw-knabbel-wp' ),
			'button_text'  => __( 'Test API Connection', 'zw-knabbel-wp' ),
			'error_text'   => __( 'An error occurred', 'zw-knabbel-wp' ),
		)
	);

	$settings = get_option( 'knabbel_settings', array() );
	?>
	<div class="wrap knabbel-wp-admin">
		<!-- Page Header -->
		<div class="knabbel-page-header">
			<h1><?php esc_html_e( 'ZuidWest Knabbel', 'zw-knabbel-wp' ); ?></h1>
			<button type="submit" form="knabbel-settings-form" class="knabbel-btn knabbel-btn-primary">
				<?php esc_html_e( 'Wijzigingen opslaan', 'zw-knabbel-wp' ); ?>
			</button>
		</div>

		<form id="knabbel-settings-form" method="post" action="options.php">
			<?php settings_fields( 'knabbel_settings_group' ); ?>

			<div class="knabbel-settings-grid">
				<?php
				render_babbel_api_card( $settings );
				render_openai_card( $settings );
				render_prompts_card( $settings );
				render_defaults_card( $settings );
				?>
			</div>
		</form>

		<?php
		// Verzonden artikelen section (debug mode only).
		if ( ! empty( $settings['debug_mode'] ) ) {
			render_articles_overview();
		}
		?>
	</div>
	<?php
}

/**
 * Displays the Babbel API settings card.
 *
 * @since 0.1.05
 * @param array<string, mixed> $settings Current settings.
 */
function render_babbel_api_card( array $settings ): void {
	?>
	<div class="knabbel-settings-card">
		<div class="knabbel-card-title">
			<span class="dashicons dashicons-cloud card-icon"></span>
			<h2><?php esc_html_e( 'Babbel API', 'zw-knabbel-wp' ); ?></h2>
		</div>
		<div class="knabbel-card-content">
			<p class="knabbel-card-description">
				<?php esc_html_e( 'Configure the Babbel API settings.', 'zw-knabbel-wp' ); ?>
			</p>

			<div class="knabbel-field">
				<label class="knabbel-field-label">
					<?php esc_html_e( 'Babbel API Base URL', 'zw-knabbel-wp' ); ?>
					<span class="required">*</span>
				</label>
				<input type="text"
					name="knabbel_settings[api_base_url]"
					class="knabbel-field-input"
					value="<?php echo esc_attr( $settings['api_base_url'] ?? '' ); ?>"
					placeholder="https://api.example.com" />
				<p class="knabbel-field-description">
					<?php esc_html_e( 'The base URL of the Babbel API (without trailing slash).', 'zw-knabbel-wp' ); ?>
				</p>
			</div>

			<div class="knabbel-field-row">
				<div class="knabbel-field">
					<label class="knabbel-field-label">
						<?php esc_html_e( 'Babbel API Username', 'zw-knabbel-wp' ); ?>
						<span class="required">*</span>
					</label>
					<input type="text"
						name="knabbel_settings[api_username]"
						class="knabbel-field-input"
						value="<?php echo esc_attr( $settings['api_username'] ?? '' ); ?>"
						placeholder="<?php esc_attr_e( 'username', 'zw-knabbel-wp' ); ?>" />
				</div>

				<div class="knabbel-field">
					<label class="knabbel-field-label">
						<?php esc_html_e( 'Babbel API Password', 'zw-knabbel-wp' ); ?>
						<span class="required">*</span>
					</label>
					<input type="password"
						name="knabbel_settings[api_password]"
						class="knabbel-field-input"
						value="<?php echo esc_attr( $settings['api_password'] ?? '' ); ?>" />
				</div>
			</div>

			<div class="knabbel-field" style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #f0f0f1;">
				<label class="knabbel-toggle">
					<input type="checkbox"
						name="knabbel_settings[debug_mode]"
						value="1"
						<?php checked( ! empty( $settings['debug_mode'] ) ); ?> />
					<span class="knabbel-toggle-track"></span>
					<span class="knabbel-toggle-label">
						<?php esc_html_e( 'Enable Debug Mode', 'zw-knabbel-wp' ); ?>
					</span>
				</label>
				<p class="knabbel-field-description" style="margin-left: 50px;">
					<?php esc_html_e( 'Show debug information and status in the sidebar. Only enable for troubleshooting.', 'zw-knabbel-wp' ); ?>
				</p>
			</div>

			<div class="knabbel-test-connection">
				<button type="button" id="test-babbel-api" class="knabbel-btn knabbel-btn-secondary">
					<?php esc_html_e( 'Test API Connection', 'zw-knabbel-wp' ); ?>
				</button>
				<span id="api-test-result" class="knabbel-test-result"></span>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Displays the OpenAI settings card.
 *
 * @since 0.1.05
 * @param array<string, mixed> $settings Current settings.
 */
function render_openai_card( array $settings ): void {
	?>
	<div class="knabbel-settings-card">
		<div class="knabbel-card-title">
			<span class="dashicons dashicons-lightbulb card-icon"></span>
			<h2><?php esc_html_e( 'OpenAI', 'zw-knabbel-wp' ); ?></h2>
		</div>
		<div class="knabbel-card-content">
			<p class="knabbel-card-description">
				<?php esc_html_e( 'Configure OpenAI for generating titles and speech text.', 'zw-knabbel-wp' ); ?>
			</p>

			<div class="knabbel-field">
				<label class="knabbel-field-label">
					<?php esc_html_e( 'OpenAI API Key', 'zw-knabbel-wp' ); ?>
					<span class="required">*</span>
				</label>
				<input type="password"
					name="knabbel_settings[openai_api_key]"
					class="knabbel-field-input"
					value="<?php echo esc_attr( $settings['openai_api_key'] ?? '' ); ?>"
					placeholder="sk-..." />
				<p class="knabbel-field-description">
					<?php
					printf(
						/* translators: %s: URL to OpenAI API keys page */
						esc_html__( 'Your OpenAI API key. Available at %s', 'zw-knabbel-wp' ),
						'<a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>'
					);
					?>
				</p>
			</div>

			<div class="knabbel-field">
				<label class="knabbel-field-label">
					<?php esc_html_e( 'OpenAI Model', 'zw-knabbel-wp' ); ?>
				</label>
				<input type="text"
					name="knabbel_settings[openai_model]"
					class="knabbel-field-input"
					value="<?php echo esc_attr( $settings['openai_model'] ?? 'gpt-4.1-mini' ); ?>"
					placeholder="gpt-4.1-mini" />
				<p class="knabbel-field-description">
					<?php esc_html_e( 'The OpenAI model for text generation (e.g., gpt-4.1-mini, gpt-4o-mini, gpt-4o).', 'zw-knabbel-wp' ); ?>
				</p>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Displays the AI Prompts settings card.
 *
 * @since 0.1.05
 * @param array<string, mixed> $settings Current settings.
 */
function render_prompts_card( array $settings ): void {
	?>
	<div class="knabbel-settings-card full-width">
		<div class="knabbel-card-title">
			<span class="dashicons dashicons-format-chat card-icon"></span>
			<h2><?php esc_html_e( 'AI Prompts', 'zw-knabbel-wp' ); ?></h2>
		</div>
		<div class="knabbel-card-content">
			<p class="knabbel-card-description">
				<?php esc_html_e( 'Customize the AI prompts for generating titles and speech text.', 'zw-knabbel-wp' ); ?>
			</p>

			<div class="knabbel-field-row">
				<div class="knabbel-field">
					<label class="knabbel-field-label">
						<?php esc_html_e( 'Title Generation Prompt', 'zw-knabbel-wp' ); ?>
					</label>
					<textarea name="knabbel_settings[title_prompt]"
						class="knabbel-field-input"
						rows="4"
						<?php // phpcs:ignore Generic.Files.LineLength.TooLong -- Placeholder text with newlines ?>
						placeholder="<?php echo esc_attr( "Creëer een pakkende radiotitel (max 60 karakters) die:\n- Direct de kernboodschap weergeeft\n- Nieuwswaardig en luisteraantrekkelijk is\n- Geschikt voor gesproken presentatie\n- Actief geformuleerd is" ); ?>"><?php echo esc_textarea( $settings['title_prompt'] ?? '' ); ?></textarea>
					<p class="knabbel-field-description">
						<?php esc_html_e( 'Prompt for generating radio-friendly titles. Leave empty for default prompt.', 'zw-knabbel-wp' ); ?>
					</p>
				</div>

				<div class="knabbel-field">
					<label class="knabbel-field-label">
						<?php esc_html_e( 'Speech Text Generation Prompt', 'zw-knabbel-wp' ); ?>
					</label>
					<textarea name="knabbel_settings[speech_prompt]"
						class="knabbel-field-input"
						rows="4"
						<?php // phpcs:ignore Generic.Files.LineLength.TooLong -- Placeholder text with newlines ?>
						placeholder="<?php echo esc_attr( "Transformeer naar natuurlijke radiospreektekst met:\n- Korte, heldere zinnen\n- Spreektaal en radiofrases\n- Logische volgorde voor luisteraars\n- Actieve zinsbouw\n- Getallen uitgeschreven waar natuurlijk" ); ?>"><?php echo esc_textarea( $settings['speech_prompt'] ?? '' ); ?></textarea>
					<p class="knabbel-field-description">
						<?php esc_html_e( 'Prompt for converting to radio-friendly speech text. Leave empty for default prompt.', 'zw-knabbel-wp' ); ?>
					</p>
				</div>
			</div>
		</div>
	</div>
	<?php
}

/**
 * Displays the Story Defaults settings card.
 *
 * @since 0.1.05
 * @param array<string, mixed> $settings Current settings.
 */
function render_defaults_card( array $settings ): void {
	$weekdays  = array(
		'sunday'    => __( 'Sun', 'zw-knabbel-wp' ),
		'monday'    => __( 'Mon', 'zw-knabbel-wp' ),
		'tuesday'   => __( 'Tue', 'zw-knabbel-wp' ),
		'wednesday' => __( 'Wed', 'zw-knabbel-wp' ),
		'thursday'  => __( 'Thu', 'zw-knabbel-wp' ),
		'friday'    => __( 'Fri', 'zw-knabbel-wp' ),
		'saturday'  => __( 'Sat', 'zw-knabbel-wp' ),
	);
	$is_active = ( $settings['default_status'] ?? 'draft' ) === 'active';
	?>
	<div class="knabbel-settings-card full-width">
		<div class="knabbel-card-title">
			<span class="dashicons dashicons-clock card-icon"></span>
			<h2><?php esc_html_e( 'Story Defaults', 'zw-knabbel-wp' ); ?></h2>
		</div>
		<div class="knabbel-card-content" style="padding: 0;">
			<table class="knabbel-form-table">
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Start Date (days from now)', 'zw-knabbel-wp' ); ?></td>
					<td>
						<div class="input-inline">
							<input type="number"
								name="knabbel_settings[start_days_offset]"
								class="knabbel-field-input"
								value="<?php echo esc_attr( (string) ( $settings['start_days_offset'] ?? 1 ) ); ?>"
								min="0" />
							<span class="input-suffix"><?php esc_html_e( 'Number of days from publication for start date.', 'zw-knabbel-wp' ); ?></span>
						</div>
					</td>
				</tr>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'End Date (days from now)', 'zw-knabbel-wp' ); ?></td>
					<td>
						<div class="input-inline">
							<input type="number"
								name="knabbel_settings[end_days_offset]"
								class="knabbel-field-input"
								value="<?php echo esc_attr( (string) ( $settings['end_days_offset'] ?? 2 ) ); ?>"
								min="0" />
							<span class="input-suffix"><?php esc_html_e( 'Number of days from publication for end date.', 'zw-knabbel-wp' ); ?></span>
						</div>
					</td>
				</tr>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Status', 'zw-knabbel-wp' ); ?></td>
					<td>
						<input type="hidden" name="knabbel_settings[default_status]" value="draft" />
						<label class="knabbel-toggle">
							<input type="checkbox"
								name="knabbel_settings[default_status]"
								value="active"
								<?php checked( $is_active ); ?> />
							<span class="knabbel-toggle-track"></span>
							<span class="knabbel-toggle-label"><?php esc_html_e( 'Active', 'zw-knabbel-wp' ); ?></span>
						</label>
					</td>
				</tr>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Days', 'zw-knabbel-wp' ); ?></td>
					<td>
						<div class="knabbel-weekdays">
							<?php foreach ( $weekdays as $key => $label ) : ?>
								<?php
								$field_name = 'weekday_' . $key;
								$checked    = ! isset( $settings[ $field_name ] ) || ! empty( $settings[ $field_name ] );
								?>
								<label class="knabbel-weekday <?php echo $checked ? 'checked' : ''; ?>">
									<input type="checkbox"
										name="knabbel_settings[<?php echo esc_attr( $field_name ); ?>]"
										value="1"
										<?php checked( $checked ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="knabbel-field-description" style="margin-top: 8px;">
							<?php esc_html_e( 'Which days the story may be broadcast.', 'zw-knabbel-wp' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>
	</div>
	<?php
}

/**
 * Displays the Verzonden Artikelen overview card.
 *
 * @since 0.1.05
 */
function render_articles_overview(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Debug post selector, no state change.
	$selected_id = isset( $_GET['knabbel_debug_post'] ) ? intval( $_GET['knabbel_debug_post'] ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page slug from admin URL.
	$page_slug = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'zw-knabbel-wp-settings';

	$latest_post  = null;
	$latest_state = null;
	$latest_ts    = 0;
	$candidates   = array();

	$query = new \WP_Query(
		array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => 25,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => '_zw_knabbel_story_state',
					'compare' => 'EXISTS',
				),
			),
		)
	);

	if ( $query->have_posts() ) {
		foreach ( $query->posts as $post ) {
			$state = get_post_meta( $post->ID, '_zw_knabbel_story_state', true );
			if ( ! is_array( $state ) || empty( $state['status_changed_at'] ) ) {
				continue;
			}
			$ts           = strtotime( $state['status_changed_at'] . ' ' . wp_timezone_string() );
			$candidates[] = array(
				'post'  => $post,
				'state' => $state,
				'ts'    => $ts,
			);
			if ( $ts && $ts > $latest_ts ) {
				$latest_ts    = $ts;
				$latest_post  = $post;
				$latest_state = $state;
			}
		}
	}

	// Select current item.
	$selected       = null;
	$selected_state = null;
	$selected_ts    = 0;
	if ( $selected_id && ! empty( $candidates ) ) {
		foreach ( $candidates as $item ) {
			if ( $item['post']->ID === $selected_id ) {
				$selected       = $item['post'];
				$selected_state = $item['state'];
				$selected_ts    = $item['ts'];
				break;
			}
		}
	}
	if ( ! $selected && $latest_post ) {
		$selected       = $latest_post;
		$selected_state = $latest_state;
		$selected_ts    = $latest_ts;
	}

	if ( ! $selected || ! $selected_state ) {
		return;
	}

	$status    = $selected_state['status'] ?? '';
	$label_map = array(
		'scheduled'  => __( 'Scheduled', 'zw-knabbel-wp' ),
		'processing' => __( 'Processing', 'zw-knabbel-wp' ),
		'sent'       => __( 'Sent', 'zw-knabbel-wp' ),
		'error'      => __( 'Error', 'zw-knabbel-wp' ),
	);
	$slug      = $status ? sanitize_key( $status ) : 'none';
	$label     = $status ? ( $label_map[ $status ] ?? $status ) : '—';
	$is_error  = 'error' === $status;
	$is_sent   = 'sent' === $status;

	// Build select options.
	$options_html = '';
	foreach ( $candidates as $item ) {
		$post          = $item['post'];
		$title         = esc_html( get_the_title( $post ) );
		$sel           = selected( $post->ID, $selected->ID, false );
		$options_html .= '<option value="' . intval( $post->ID ) . '" ' . $sel . '>' . $title . '</option>';
	}

	// phpcs:ignore Generic.Files.LineLength.TooLong -- Inline JS for refresh and select change.
	$refresh_js = "location.href=location.pathname + '?page=' + encodeURIComponent('" . esc_js( $page_slug ) . "') + '&knabbel_debug_post=' + encodeURIComponent(document.getElementById('knabbel_debug_post').value) + '#knabbel-debug-overview'";
	?>
	<div id="knabbel-debug-overview" class="knabbel-settings-card" style="margin-top: 24px;">
		<div class="knabbel-card-title knabbel-articles-header">
			<div class="header-left">
				<span class="dashicons dashicons-clipboard card-icon"></span>
				<h2><?php esc_html_e( 'Knabbel Story Debug Overview', 'zw-knabbel-wp' ); ?></h2>
			</div>
			<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>" />
				<select id="knabbel_debug_post"
					name="knabbel_debug_post"
					class="knabbel-articles-select"
					<?php // phpcs:ignore Generic.Files.LineLength.TooLong -- Inline JS for anchor navigation ?>
					onchange="location.href=this.form.action + '?page=' + this.form.page.value + '&knabbel_debug_post=' + this.value + '#knabbel-debug-overview';">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
					echo $options_html;
					?>
				</select>
				<noscript><button class="button"><?php esc_html_e( 'Show', 'zw-knabbel-wp' ); ?></button></noscript>
			</form>
		</div>
		<div class="knabbel-card-content" style="padding: 0;">
			<table class="knabbel-articles-table">
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Article', 'zw-knabbel-wp' ); ?></td>
					<td>
						<a href="<?php echo esc_url( get_edit_post_link( $selected->ID ) ); ?>" class="article-link">
							<?php echo esc_html( get_the_title( $selected ) ); ?>
						</a>
						<span class="post-id">(Post ID: <?php echo intval( $selected->ID ); ?>)</span>
					</td>
				</tr>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Status', 'zw-knabbel-wp' ); ?></td>
					<td>
						<span class="knabbel-status-badge <?php echo esc_attr( $slug ); ?>">
							<span class="status-dot"></span>
							<?php echo esc_html( $label ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Last status change', 'zw-knabbel-wp' ); ?></td>
					<td>
						<?php echo esc_html( wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $selected_ts ) ); ?>
					</td>
				</tr>
				<?php if ( $is_sent && ! empty( $selected_state['story_id'] ) ) : ?>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Babbel ID', 'zw-knabbel-wp' ); ?></td>
					<td class="mono"><?php echo esc_html( $selected_state['story_id'] ); ?></td>
				</tr>
				<?php elseif ( $is_error && ! empty( $selected_state['message'] ) ) : ?>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Message', 'zw-knabbel-wp' ); ?></td>
					<td class="error-message"><?php echo esc_html( $selected_state['message'] ); ?></td>
				</tr>
				<?php elseif ( 'processing' === $status ) : ?>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Message', 'zw-knabbel-wp' ); ?></td>
					<td><?php esc_html_e( 'Story is being processed...', 'zw-knabbel-wp' ); ?></td>
				</tr>
				<?php elseif ( 'scheduled' === $status ) : ?>
				<tr>
					<td class="label-cell"><?php esc_html_e( 'Babbel ID', 'zw-knabbel-wp' ); ?></td>
					<td class="muted">—</td>
				</tr>
				<?php endif; ?>
			</table>
		</div>
		<div class="knabbel-articles-footer">
			<?php if ( $is_error ) : ?>
			<button type="button"
				class="knabbel-btn knabbel-btn-secondary"
				id="knabbel-retry-btn"
				data-post-id="<?php echo intval( $selected->ID ); ?>"
				style="padding: 6px 12px;">
				<?php esc_html_e( 'Retry', 'zw-knabbel-wp' ); ?>
			</button>
			<?php endif; ?>
			<div class="footer-right">
				<button type="button" class="knabbel-refresh" onclick="<?php echo esc_attr( $refresh_js ); ?>">
					<?php esc_html_e( 'Refresh', 'zw-knabbel-wp' ); ?>
				</button>
			</div>
		</div>
	</div>
	<?php
}
