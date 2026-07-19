<?php
/**
 * End-to-end regression suite for the WordPress to Babbel synchronization flow.
 *
 * @package KnabbelWP
 */

declare(strict_types=1);

use KnabbelWP\StoryStatus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'Run this suite through WP-CLI.' );
}

/**
 * Runs stateful integration scenarios against isolated WordPress and Babbel databases.
 */
final class Knabbel_E2E_Suite {
	private const BABBEL_BASE_URL = 'http://babbel:8080/api/v1';
	private const STORY_HOOK      = 'knabbel_process_story';
	private const FEW_SHOT_HOOK   = 'knabbel_sync_few_shot_examples';
	private const ACTION_GROUP    = 'zw-knabbel-wp';
	private const GENERATED_TEXT  = 'Deterministische E2E-radiospreektekst.';

	/**
	 * Cookies for the independent Babbel verification client.
	 *
	 * @var array<int, WP_Http_Cookie>
	 */
	private array $babbel_cookies = array();

	/**
	 * Number of assertions completed by the suite.
	 *
	 * @var int
	 */
	private int $assertion_count = 0;

	/**
	 * WordPress ID of the shared published fixture.
	 *
	 * @var int
	 */
	private int $published_post_id = 0;

	/**
	 * Babbel ID of the shared published fixture.
	 *
	 * @var string
	 */
	private string $published_story_id = '';

	/**
	 * Execute all scenarios in dependency order.
	 */
	public function run(): void {
		$this->run_case( 'E2E-001', 'plugin activation, recurring queue and Babbel authentication', $this->test_bootstrap_and_authentication( ... ) );
		$this->run_case( 'E2E-002', 'published post creates exactly one complete Babbel story', $this->test_published_story_creation( ... ) );
		$this->run_case( 'E2E-003', 'edits synchronize and recover from an authentication failure', $this->test_update_and_error_recovery( ... ) );
		$this->run_case( 'E2E-004', 'checkbox disable soft-deletes and re-enable restores', $this->test_checkbox_delete_and_restore( ... ) );
		$this->run_case( 'E2E-005', 'scheduled post dates update when scheduled and published', $this->test_scheduled_story_lifecycle( ... ) );
		$this->run_case( 'E2E-006', 'unscheduling cancels pending processing without creating a story', $this->test_pending_schedule_cancellation( ... ) );
		$this->run_case( 'E2E-007', 'trash and untrash delete and restore the same story', $this->test_trash_and_restore( ... ) );
		$this->run_case( 'E2E-008', 'OpenAI failure retries without creating a Babbel story', $this->test_openai_failure( ... ) );
		$this->run_case( 'E2E-009', 'Babbel create failure is visible and preserves diagnostics safely', $this->test_babbel_create_failure( ... ) );
		$this->run_case( 'E2E-010', 'few-shot queue learns editor changes and honors disable', $this->test_few_shot_sync( ... ) );
		$this->run_case( 'E2E-011', 'deactivation clears sessions, caches and scheduled actions', $this->test_deactivation_cleanup( ... ) );

		WP_CLI::success( sprintf( '11 E2E scenarios passed with %d assertions.', $this->assertion_count ) );
	}

	/**
	 * Run one named case with useful failure context.
	 *
	 * @param string   $id       Stable scenario ID.
	 * @param string   $title    Scenario title.
	 * @param callable $callback Scenario callback.
	 */
	private function run_case( string $id, string $title, callable $callback ): void {
		WP_CLI::log( sprintf( '[%s] %s', $id, $title ) );

		try {
			$callback();
			WP_CLI::log( sprintf( '[%s] PASS', $id ) );
		} catch ( Throwable $throwable ) {
			WP_CLI::error( sprintf( '[%s] FAIL: %s', $id, $throwable->getMessage() ) );
		}
	}

	/**
	 * Configure deterministic credentials and story defaults.
	 *
	 * @param string $password Babbel password.
	 */
	private function configure_plugin( string $password = 'admin' ): void {
		$settings = get_option( 'knabbel_settings', array() );
		$this->assert_true( is_array( $settings ), 'Plugin settings must be an array.' );

		$settings = array_merge(
			$settings,
			array(
				'api_base_url'      => self::BABBEL_BASE_URL,
				'api_username'      => 'admin',
				'api_password'      => $password,
				'openai_api_key'    => 'e2e-openai-key',
				'openai_model'      => 'e2e-model',
				'start_days_offset' => 1,
				'end_days_offset'   => 2,
				'default_status'    => 'draft',
				'few_shot_count'    => 1,
				'weekday_sunday'    => 1,
				'weekday_monday'    => 1,
				'weekday_tuesday'   => 1,
				'weekday_wednesday' => 1,
				'weekday_thursday'  => 1,
				'weekday_friday'    => 1,
				'weekday_saturday'  => 1,
			)
		);

		update_option( 'knabbel_settings', $settings );
		KnabbelWP\babbel_clear_session_cache();
	}

	/**
	 * Verify activation defaults, a single recurring action, login and 401 retry.
	 */
	private function test_bootstrap_and_authentication(): void {
		$this->assert_true( defined( 'KNABBEL_VERSION' ), 'The plugin bootstrap must be loaded.' );

		$settings = get_option( 'knabbel_settings', array() );
		$this->assert_same( 1, $settings['start_days_offset'] ?? null, 'Activation must set the default start offset.' );
		$this->assert_same( 'draft', $settings['default_status'] ?? null, 'Activation must set the default story status.' );

		KnabbelWP\few_shot_schedule_sync();
		$this->assert_same(
			1,
			$this->action_count( self::FEW_SHOT_HOOK, ActionScheduler_Store::STATUS_PENDING ),
			'The recurring few-shot action must be unique.'
		);

		$this->configure_plugin();
		$result = KnabbelWP\babbel_test_connection();
		$this->assert_true( $result['success'], 'Valid Babbel credentials must connect.' );
		$this->assert_string_contains( 'admin', $result['message'], 'Connection result must identify the authenticated user.' );

		$cache_key = KnabbelWP\babbel_get_session_cache_key();
		$this->assert_not_empty( get_transient( $cache_key ), 'Successful login must cache session cookies.' );

		$invalid_cookie = new WP_Http_Cookie(
			array(
				'name'   => 'babbel_session',
				'value'  => 'invalid-e2e-session',
				'path'   => '/',
				'domain' => 'babbel',
			)
		);
		set_transient( $cache_key, array( $invalid_cookie ), MINUTE_IN_SECONDS );

		$result = KnabbelWP\babbel_test_connection();
		$this->assert_true( $result['success'], 'A 401 response must clear the cache, authenticate again and retry once.' );
		$this->assert_not_same( 'invalid-e2e-session', get_transient( $cache_key )[0]->value ?? null, 'The invalid session cookie must be replaced.' );
	}

	/**
	 * Verify publish scheduling, queue execution, payload fidelity and send-once safety.
	 */
	private function test_published_story_creation(): void {
		$title   = 'E2E gepubliceerd – één';
		$content = 'Dit artikel bevat voldoende woorden om de volledige publicatieketen betrouwbaar te testen.';

		$post_id = $this->create_enabled_draft( $title, $content );
		$this->assert_same( 0, $this->story_action_count( $post_id ), 'Enabling a draft must not schedule processing.' );

		$dates_before = KnabbelWP\calculate_story_dates( 'now' );
		$this->update_post( $post_id, array( 'post_status' => 'publish' ) );
		$this->assert_story_status( $post_id, StoryStatus::Scheduled, 'Publishing must mark the story scheduled.' );
		$this->assert_same( 1, $this->story_action_count( $post_id ), 'Publishing must enqueue one action.' );

		$this->update_post( $post_id, array( 'post_title' => $title ) );
		$this->assert_same( 1, $this->story_action_count( $post_id ), 'Repeated saves must not duplicate the pending action.' );

		$this->run_action_scheduler( self::STORY_HOOK );
		$dates_after = KnabbelWP\calculate_story_dates( 'now' );
		$state       = KnabbelWP\get_story_state( $post_id );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'The worker must mark a created story sent.' );
		$this->assert_not_empty( $state['story_id'] ?? '', 'The worker must persist the Babbel story ID.' );
		$this->assert_same( self::GENERATED_TEXT, $state['generated_speech_text'] ?? null, 'The generated speech text must be persisted.' );

		$story = $this->get_babbel_story( (string) $state['story_id'] );
		$this->assert_same( $title, $story['title'] ?? null, 'Babbel must receive the raw WordPress title.' );
		$this->assert_same( self::GENERATED_TEXT, $story['text'] ?? null, 'Babbel must receive the generated speech text.' );
		$this->assert_story_dates_in_window( $story, $dates_before, $dates_after, 'Published story dates must be based on the processing date.' );
		$this->assert_same( 127, $story['weekdays'] ?? null, 'All configured weekdays must produce bitmask 127.' );
		$this->assert_same( 'draft', $story['status'] ?? null, 'Babbel must receive the configured default status.' );
		$this->assert_same( $post_id, (int) ( $story['metadata']['wordpress_id'] ?? 0 ), 'Babbel metadata must link to the WordPress post.' );
		$this->assert_same(
			self::GENERATED_TEXT,
			$story['metadata']['original_speech_text'] ?? null,
			'Babbel metadata must retain the original generated text.'
		);

		KnabbelWP\process_story_async( $post_id );
		$this->assert_same( 1, $this->count_babbel_stories_by_title( $title ), 'Re-running the worker must not create a duplicate story.' );

		$this->published_post_id  = $post_id;
		$this->published_story_id = (string) $state['story_id'];
	}

	/**
	 * Verify selective updates and recovery from a real Babbel authentication failure.
	 */
	private function test_update_and_error_recovery(): void {
		$original_story = $this->get_babbel_story( $this->published_story_id );
		$edited_text    = 'Dit is de door de redactie aangepaste Babbel-speechtekst die behouden moet blijven.';
		$response       = $this->babbel_request(
			'PUT',
			'/stories/' . $this->published_story_id,
			array(
				'text'   => $edited_text,
				'status' => $original_story['status'] ?? 'draft',
			)
		);
		$this->assert_same( 200, wp_remote_retrieve_response_code( $response ), 'The fixture speech text must be editable in Babbel.' );

		$new_content = 'De inhoud verandert, maar bestaand Babbel-speechmateriaal blijft bewust en aantoonbaar ongewijzigd.';
		$this->update_post( $this->published_post_id, array( 'post_content' => $new_content ) );
		$story = $this->get_babbel_story( $this->published_story_id );
		$this->assert_same( $edited_text, $story['text'] ?? null, 'Content-only edits must not overwrite edited Babbel speech text.' );

		$this->update_post( $this->published_post_id, array( 'post_title' => 'E2E titel gesynchroniseerd' ) );
		$story = $this->get_babbel_story( $this->published_story_id );
		$this->assert_same( 'E2E titel gesynchroniseerd', $story['title'] ?? null, 'Title edits must synchronize immediately.' );

		$this->configure_plugin( 'definitely-wrong-password' );
		$this->update_post( $this->published_post_id, array( 'post_title' => 'E2E titel tijdens fout' ) );
		$state = KnabbelWP\get_story_state( $this->published_post_id );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'Update failure must preserve sent lifecycle state.' );
		$this->assert_same( $this->published_story_id, (string) ( $state['story_id'] ?? '' ), 'Update failure must preserve the story ID.' );
		$this->assert_same( 'update', $state['last_sync_error']['operation'] ?? null, 'Update failure must persist its operation.' );
		$story = $this->get_babbel_story( $this->published_story_id );
		$this->assert_same( 'E2E titel gesynchroniseerd', $story['title'] ?? null, 'Failed update must leave remote data unchanged.' );

		$recent_errors = get_option( 'knabbel_recent_errors', array() );
		$this->assert_not_empty( $recent_errors, 'A synchronization failure must be visible in recent errors.' );
		$error_json = wp_json_encode( $recent_errors );
		$this->assert_false(
			is_string( $error_json ) && str_contains( $error_json, 'definitely-wrong-password' ),
			'Persistent diagnostics must never contain the Babbel password.'
		);

		$this->configure_plugin();
		$this->update_post( $this->published_post_id, array( 'post_title' => 'E2E titel hersteld' ) );
		$state = KnabbelWP\get_story_state( $this->published_post_id );
		$this->assert_false( isset( $state['last_sync_error'] ), 'A successful retry must clear the previous sync error.' );
		$story = $this->get_babbel_story( $this->published_story_id );
		$this->assert_same( 'E2E titel hersteld', $story['title'] ?? null, 'Remote title must synchronize after credential recovery.' );
	}

	/**
	 * Verify checkbox-driven delete and restore against the real API.
	 */
	private function test_checkbox_delete_and_restore(): void {
		update_post_meta( $this->published_post_id, '_zw_knabbel_send_to_babbel', 0 );
		$this->assert_story_status( $this->published_post_id, StoryStatus::Deleted, 'Disabling the checkbox must mark the story deleted.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $this->published_story_id );

		$this->update_post( $this->published_post_id, array( 'post_title' => 'E2E titel na verwijderen' ) );
		update_post_meta( $this->published_post_id, '_zw_knabbel_send_to_babbel', 1 );
		$state = KnabbelWP\get_story_state( $this->published_post_id );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'Re-enabling must restore the story.' );
		$this->assert_same( $this->published_story_id, (string) ( $state['story_id'] ?? '' ), 'Restore must reuse the original story ID.' );
		$story = $this->get_babbel_story( $this->published_story_id );
		$this->assert_same( 'E2E titel na verwijderen', $story['title'] ?? null, 'Restore must synchronize the current title.' );
	}

	/**
	 * Verify scheduled date calculation, rescheduling and future-to-publish recalculation.
	 */
	private function test_scheduled_story_lifecycle(): void {
		$post_id = $this->create_enabled_draft( 'E2E gepland', 'Een gepland artikel doorloopt dezelfde betrouwbare asynchrone integratieketen.' );

		$first_date = $this->future_post_date( 10 );
		$this->schedule_post( $post_id, $first_date );
		$this->assert_same( $first_date, get_post( $post_id )->post_date ?? null, 'Scheduled fixture must retain its local publication date.' );
		$this->assert_same( 'future', get_post_status( $post_id ), 'Scheduled fixture must retain future status before processing.' );
		$this->assert_same( 1, $this->story_action_count( $post_id ), 'Scheduling must enqueue one worker action.' );
		$this->run_action_scheduler( self::STORY_HOOK );

		$state    = KnabbelWP\get_story_state( $post_id );
		$story_id = (string) ( $state['story_id'] ?? '' );
		$story    = $this->get_babbel_story( $story_id );
		$dates    = KnabbelWP\calculate_story_dates( $first_date );
		$this->assert_same( $dates['start_date'], $this->date_only( $story['start_date'] ?? '' ), 'Scheduled start date must use post_date.' );
		$this->assert_same( $dates['end_date'], $this->date_only( $story['end_date'] ?? '' ), 'Scheduled end date must use post_date.' );

		$second_date = $this->future_post_date( 12 );
		$this->update_post(
			$post_id,
			array(
				'post_title'    => 'E2E opnieuw gepland',
				'post_date'     => $second_date,
				'post_date_gmt' => get_gmt_from_date( $second_date ),
			)
		);
		$story = $this->get_babbel_story( $story_id );
		$dates = KnabbelWP\calculate_story_dates( $second_date );
		$this->assert_same( 'E2E opnieuw gepland', $story['title'] ?? null, 'Rescheduling must synchronize the title.' );
		$this->assert_same( $dates['start_date'], $this->date_only( $story['start_date'] ?? '' ), 'Rescheduling must recalculate story dates.' );

		$dates_before = KnabbelWP\calculate_story_dates( 'now' );
		$this->update_post(
			$post_id,
			array(
				'post_status'   => 'publish',
				'post_date'     => current_time( 'mysql' ),
				'post_date_gmt' => current_time( 'mysql', true ),
			)
		);
		$story       = $this->get_babbel_story( $story_id );
		$dates_after = KnabbelWP\calculate_story_dates( 'now' );
		$this->assert_story_dates_in_window( $story, $dates_before, $dates_after, 'Publishing a scheduled post must recalculate from the processing date.' );
	}

	/**
	 * Verify pending work is canceled when a future post returns to draft.
	 */
	private function test_pending_schedule_cancellation(): void {
		$title   = 'E2E planning geannuleerd';
		$post_id = $this->create_enabled_draft( $title, 'Deze geplande verwerking wordt geannuleerd voordat een externe story ontstaat.' );
		$this->schedule_post( $post_id, $this->future_post_date( 15 ) );
		$this->assert_same( 1, $this->story_action_count( $post_id ), 'Future post must have one pending action before cancellation.' );

		$this->update_post( $post_id, array( 'post_status' => 'draft' ) );
		$this->assert_same( 0, $this->story_action_count( $post_id ), 'Returning to draft must cancel pending work.' );
		$this->assert_same( array(), KnabbelWP\get_story_state( $post_id ), 'Cancellation before processing must clear local story state.' );
		$this->run_action_scheduler( self::STORY_HOOK );
		$this->assert_same( 0, $this->count_babbel_stories_by_title( $title ), 'Canceled work must never create a Babbel story.' );
	}

	/**
	 * Verify post trash hooks soft-delete and untrash restores the same record.
	 */
	private function test_trash_and_restore(): void {
		$post_id  = $this->create_and_process_published_story( 'E2E prullenbak', 'Een gepubliceerd artikel wordt verwijderd en daarna veilig teruggezet.' );
		$state    = KnabbelWP\get_story_state( $post_id );
		$story_id = (string) ( $state['story_id'] ?? '' );

		wp_trash_post( $post_id );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Trashing must mark the remote story deleted.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );

		wp_untrash_post( $post_id );
		$state = KnabbelWP\get_story_state( $post_id );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'Untrash must restore the remote story.' );
		$this->assert_same( $story_id, (string) ( $state['story_id'] ?? '' ), 'Untrash must retain the story ID.' );
		$this->assert_same( 'E2E prullenbak', $this->get_babbel_story( $story_id )['title'] ?? null, 'Restored trash story must remain readable.' );
	}

	/**
	 * Verify retry count and no remote side effect when OpenAI is unavailable.
	 */
	private function test_openai_failure(): void {
		$title = 'E2E OpenAI fout';
		update_option( 'knabbel_e2e_openai_mode', 'error', false );
		update_option( 'knabbel_e2e_openai_call_count', 0, false );

		$post_id = $this->create_enabled_draft( $title, 'OpenAI faalt deterministisch zodat foutafhandeling en retries aantoonbaar blijven.' );
		$this->publish_and_process( $post_id );

		$this->assert_story_status( $post_id, StoryStatus::Error, 'OpenAI exhaustion must mark processing as error.' );
		$this->assert_same( 3, (int) get_option( 'knabbel_e2e_openai_call_count', 0 ), 'OpenAI must be attempted exactly three times.' );
		$this->assert_same( 0, $this->count_babbel_stories_by_title( $title ), 'OpenAI failure must not create a Babbel story.' );

		update_option( 'knabbel_e2e_openai_mode', 'success', false );
	}

	/**
	 * Verify a real Babbel login failure reaches per-story and operator diagnostics.
	 */
	private function test_babbel_create_failure(): void {
		$title = 'E2E Babbel fout';
		$this->configure_plugin( 'wrong-create-password' );

		$post_id = $this->create_enabled_draft( $title, 'Babbel weigert authenticatie en WordPress bewaart een begrensde foutstatus.' );
		$this->publish_and_process( $post_id );

		$state = KnabbelWP\get_story_state( $post_id );
		$this->assert_same( StoryStatus::Error->value, $state['status'] ?? null, 'Babbel create failure must mark lifecycle error.' );
		$this->assert_same( 'create', $state['last_sync_error']['operation'] ?? null, 'Babbel create failure must identify the operation.' );
		$this->assert_same( 0, $this->count_babbel_stories_by_title( $title ), 'Rejected authentication must not create a story.' );
		$error_json = wp_json_encode( get_option( 'knabbel_recent_errors', array() ) );
		$this->assert_false(
			is_string( $error_json ) && str_contains( $error_json, 'wrong-create-password' ),
			'Recent errors must exclude failed credentials.'
		);

		$this->configure_plugin();
	}

	/**
	 * Verify the recurring integration learns edited remote text and clears cache when disabled.
	 */
	private function test_few_shot_sync(): void {
		$edited_text = 'Dit is de aantoonbaar door een redacteur aangepaste radiospreektekst.';
		$response    = $this->babbel_request(
			'PUT',
			'/stories/' . $this->published_story_id,
			array(
				'text'   => $edited_text,
				'status' => 'active',
			)
		);
		$this->assert_same( 200, wp_remote_retrieve_response_code( $response ), 'The fixture story must be editable in Babbel.' );

		as_enqueue_async_action( self::FEW_SHOT_HOOK, array(), self::ACTION_GROUP );
		$this->run_action_scheduler( self::FEW_SHOT_HOOK );

		$examples = get_option( 'knabbel_few_shot_examples', array() );
		$this->assert_same( 1, count( $examples ), 'Few-shot sync must cache the configured number of examples.' );
		$this->assert_same( $edited_text, $examples[0]['output'] ?? null, 'Few-shot output must use editor-corrected Babbel text.' );
		$this->assert_same(
			wp_strip_all_tags( get_post_field( 'post_content', $this->published_post_id ) ),
			$examples[0]['input'] ?? null,
			'Few-shot input must use current WordPress content.'
		);

		$settings                   = get_option( 'knabbel_settings', array() );
		$settings['few_shot_count'] = 0;
		update_option( 'knabbel_settings', $settings );
		as_enqueue_async_action( self::FEW_SHOT_HOOK, array(), self::ACTION_GROUP );
		$this->run_action_scheduler( self::FEW_SHOT_HOOK );
		$this->assert_same( false, get_option( 'knabbel_few_shot_examples', false ), 'Disabling few-shot must remove cached examples.' );
	}

	/**
	 * Verify plugin lifecycle cleanup after all functional scenarios.
	 */
	private function test_deactivation_cleanup(): void {
		$this->configure_plugin();
		$result = KnabbelWP\babbel_test_connection();
		$this->assert_true( $result['success'], 'Precondition: session cache must exist before deactivation.' );
		update_option(
			'knabbel_few_shot_examples',
			array(
				array(
					'input'  => 'x',
					'output' => 'y',
				),
			),
			false
		);

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( 'zw-knabbel-wp/zw-knabbel-wp.php' );

		$this->assert_false( is_plugin_active( 'zw-knabbel-wp/zw-knabbel-wp.php' ), 'The plugin must be inactive after deactivation.' );
		$this->assert_same( 0, $this->action_count( self::STORY_HOOK, ActionScheduler_Store::STATUS_PENDING ), 'Deactivation must clear story actions.' );
		$this->assert_same(
			0,
			$this->action_count( self::FEW_SHOT_HOOK, ActionScheduler_Store::STATUS_PENDING ),
			'Deactivation must clear the recurring few-shot action.'
		);
		$this->assert_same( false, get_transient( KnabbelWP\babbel_get_session_cache_key() ), 'Deactivation must clear Babbel sessions.' );
		$this->assert_same( false, get_option( 'knabbel_few_shot_examples', false ), 'Deactivation must clear few-shot data.' );
	}

	/**
	 * Create and process one published story.
	 *
	 * @param string $title   Post title.
	 * @param string $content Post content.
	 * @return int Post ID.
	 * @throws RuntimeException When WordPress cannot create the post.
	 */
	private function create_and_process_published_story( string $title, string $content ): int {
		$post_id = $this->create_enabled_draft( $title, $content );
		$this->publish_and_process( $post_id );
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'Published fixture must reach sent state.' );

		return $post_id;
	}

	/**
	 * Create a draft post with radio news enabled and fail on WordPress errors.
	 *
	 * @param string $title   Post title.
	 * @param string $content Post content.
	 * @return int Post ID.
	 * @throws RuntimeException When WordPress cannot create the post.
	 */
	private function create_enabled_draft( string $title, string $content ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( 'Could not create test post: ' . $post_id->get_error_message() );
		}

		update_post_meta( $post_id, '_zw_knabbel_send_to_babbel', 1 );

		return $post_id;
	}

	/**
	 * Update a post and fail on WordPress errors.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $updates Post fields.
	 * @throws RuntimeException When WordPress cannot update the post.
	 */
	private function update_post( int $post_id, array $updates ): void {
		$result = wp_update_post( array_merge( array( 'ID' => $post_id ), $updates ), true );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( 'Could not update test post: ' . $result->get_error_message() );
		}
	}

	/**
	 * Publish a post and run its queued story processing.
	 *
	 * @param int $post_id Post ID.
	 */
	private function publish_and_process( int $post_id ): void {
		$this->update_post( $post_id, array( 'post_status' => 'publish' ) );
		$this->run_action_scheduler( self::STORY_HOOK );
	}

	/**
	 * Move a post to a future publication date.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $date    Local MySQL datetime.
	 */
	private function schedule_post( int $post_id, string $date ): void {
		$this->update_post(
			$post_id,
			array(
				'post_status'   => 'publish',
				'post_date'     => $date,
				'post_date_gmt' => get_gmt_from_date( $date ),
				'edit_date'     => true,
			)
		);
	}

	/**
	 * Run due actions through Action Scheduler's queue runner.
	 *
	 * @param string $hook Hook to execute.
	 * @throws RuntimeException When an action does not complete successfully.
	 */
	private function run_action_scheduler( string $hook ): void {
		$ids = as_get_scheduled_actions(
			array(
				'hook'         => $hook,
				'group'        => self::ACTION_GROUP,
				'status'       => ActionScheduler_Store::STATUS_PENDING,
				'date'         => time(),
				'date_compare' => '<=',
				'per_page'     => -1,
			),
			'ids'
		);

		foreach ( $ids as $id ) {
			ActionScheduler::runner()->process_action( $id, 'Knabbel E2E' );
			$status = ActionScheduler::store()->get_status( $id );

			if ( ActionScheduler_Store::STATUS_COMPLETE !== $status ) {
				throw new RuntimeException( sprintf( 'Action Scheduler action %d finished with status %s.', $id, $status ) );
			}
		}
	}

	/**
	 * Count scheduled actions by hook, status and optional action arguments.
	 *
	 * @param string                    $hook   Action hook.
	 * @param string                    $status Action Scheduler status.
	 * @param array<string, mixed>|null $args   Optional exact action arguments.
	 * @return int Action count.
	 */
	private function action_count( string $hook, string $status, ?array $args = null ): int {
		$query = array(
			'hook'     => $hook,
			'group'    => self::ACTION_GROUP,
			'status'   => $status,
			'per_page' => -1,
		);

		if ( null !== $args ) {
			$query['args'] = $args;
		}

		return count( as_get_scheduled_actions( $query, 'ids' ) );
	}

	/**
	 * Count pending story actions for one post.
	 *
	 * @param int $post_id Post ID.
	 * @return int Action count.
	 */
	private function story_action_count( int $post_id ): int {
		return $this->action_count( self::STORY_HOOK, ActionScheduler_Store::STATUS_PENDING, array( 'post_id' => $post_id ) );
	}

	/**
	 * Issue an authenticated request using an independent test client.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   API path below /api/v1.
	 * @param array<string, mixed>|null $body   Optional JSON body.
	 * @return array<string, mixed> WordPress HTTP response.
	 * @throws RuntimeException When the HTTP request fails.
	 */
	private function babbel_request( string $method, string $path, ?array $body = null ): array {
		if ( array() === $this->babbel_cookies ) {
			$this->babbel_login();
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'cookies' => $this->babbel_cookies,
		);

		if ( null !== $body ) {
			$args['headers'] = array( 'Content-Type' => 'application/json' );
			$args['body']    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::BABBEL_BASE_URL . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Independent Babbel request failed: ' . $response->get_error_message() );
		}

		return $response;
	}

	/**
	 * Authenticate the independent verification client.
	 *
	 * @throws RuntimeException When the HTTP request fails.
	 */
	private function babbel_login(): void {
		$response = wp_remote_post(
			self::BABBEL_BASE_URL . '/sessions',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'username' => 'admin',
						'password' => 'admin',
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Independent Babbel login failed: ' . $response->get_error_message() );
		}

		$this->assert_same( 201, wp_remote_retrieve_response_code( $response ), 'Independent Babbel client must authenticate.' );
		$this->babbel_cookies = wp_remote_retrieve_cookies( $response );
		$this->assert_not_empty( $this->babbel_cookies, 'Independent Babbel login must return a cookie.' );
	}

	/**
	 * Issue a GET request and decode the JSON response body.
	 *
	 * @param string $path    API path.
	 * @param string $message Failure message for a non-200 response.
	 * @return array<string, mixed> Decoded response.
	 */
	private function babbel_get_json( string $path, string $message ): array {
		$response = $this->babbel_request( 'GET', $path );
		$this->assert_same( 200, wp_remote_retrieve_response_code( $response ), $message );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );
		$this->assert_true( is_array( $decoded ), 'Babbel response must decode to an object.' );

		return $decoded;
	}

	/**
	 * Fetch and decode one Babbel story.
	 *
	 * @param string $story_id Story ID.
	 * @return array<string, mixed> Story response.
	 */
	private function get_babbel_story( string $story_id ): array {
		$this->assert_not_empty( $story_id, 'Story ID is required for remote verification.' );

		return $this->babbel_get_json( '/stories/' . rawurlencode( $story_id ), 'Expected Babbel story to be readable.' );
	}

	/**
	 * Count visible Babbel stories with an exact title.
	 *
	 * @param string $title Story title.
	 * @return int Matching count.
	 */
	private function count_babbel_stories_by_title( string $title ): int {
		$path    = '/stories?' . http_build_query(
			array(
				'filter' => array( 'title' => $title ),
				'limit'  => 100,
			)
		);
		$decoded = $this->babbel_get_json( $path, 'Babbel story list must be readable.' );
		$stories = is_array( $decoded['data'] ?? null ) ? $decoded['data'] : array();

		return count(
			array_filter(
				$stories,
				static fn( array $story ): bool => ( $story['title'] ?? null ) === $title
			)
		);
	}

	/**
	 * Assert a response status without decoding the body.
	 *
	 * @param int    $expected Expected status.
	 * @param string $method   HTTP method.
	 * @param string $path     API path.
	 */
	private function assert_babbel_response_code( int $expected, string $method, string $path ): void {
		$response = $this->babbel_request( $method, $path );
		$this->assert_same(
			$expected,
			wp_remote_retrieve_response_code( $response ),
			sprintf( 'Babbel %s %s returned an unexpected status.', $method, $path )
		);
	}

	/**
	 * Return a stable future local timestamp.
	 *
	 * @param int $days Days from now.
	 * @return string MySQL datetime.
	 */
	private function future_post_date( int $days ): string {
		return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( sprintf( '+%d days', $days ) )->setTime( 12, 0 )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Normalize an API date or datetime to Y-m-d.
	 *
	 * @param mixed $value API value.
	 * @return string Date portion.
	 */
	private function date_only( mixed $value ): string {
		return is_string( $value ) ? substr( $value, 0, 10 ) : '';
	}

	/**
	 * Assert the persisted story lifecycle status of a post.
	 *
	 * @param int         $post_id  Post ID.
	 * @param StoryStatus $expected Expected status.
	 * @param string      $message  Failure message.
	 */
	private function assert_story_status( int $post_id, StoryStatus $expected, string $message ): void {
		$this->assert_same( $expected->value, KnabbelWP\get_story_state( $post_id )['status'] ?? null, $message );
	}

	/**
	 * Assert start and end dates were calculated within a captured processing window.
	 *
	 * @param array<string, mixed>  $story   Babbel story.
	 * @param array<string, string> $before  Expected dates captured before processing.
	 * @param array<string, string> $after   Expected dates captured after processing.
	 * @param string                $message Failure message prefix.
	 */
	private function assert_story_dates_in_window( array $story, array $before, array $after, string $message ): void {
		foreach ( array( 'start_date', 'end_date' ) as $field ) {
			$this->assert_true(
				in_array( $this->date_only( $story[ $field ] ?? '' ), array( $before[ $field ], $after[ $field ] ), true ),
				sprintf( '%s (%s)', $message, $field )
			);
		}
	}

	/**
	 * Assert strict equality.
	 *
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $message  Failure message.
	 * @throws RuntimeException When the values differ.
	 */
	private function assert_same( mixed $expected, mixed $actual, string $message ): void {
		++$this->assertion_count;
		if ( $expected !== $actual ) {
			throw new RuntimeException( sprintf( '%s Expected %s, got %s.', $message, $this->describe( $expected ), $this->describe( $actual ) ) );
		}
	}

	/**
	 * Assert values are not strictly equal.
	 *
	 * @param mixed  $unexpected Unexpected value.
	 * @param mixed  $actual     Actual value.
	 * @param string $message    Failure message.
	 * @throws RuntimeException When the values are equal.
	 */
	private function assert_not_same( mixed $unexpected, mixed $actual, string $message ): void {
		++$this->assertion_count;
		if ( $unexpected === $actual ) {
			throw new RuntimeException( $message );
		}
	}

	/**
	 * Assert a truthy condition.
	 *
	 * @param bool   $condition Condition.
	 * @param string $message   Failure message.
	 * @throws RuntimeException When the condition is false.
	 */
	private function assert_true( bool $condition, string $message ): void {
		++$this->assertion_count;
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}

	/**
	 * Assert a false condition.
	 *
	 * @param bool   $condition Condition.
	 * @param string $message   Failure message.
	 */
	private function assert_false( bool $condition, string $message ): void {
		$this->assert_true( ! $condition, $message );
	}

	/**
	 * Assert a non-empty value.
	 *
	 * @param mixed  $value   Value.
	 * @param string $message Failure message.
	 * @throws RuntimeException When the value is empty.
	 */
	private function assert_not_empty( mixed $value, string $message ): void {
		++$this->assertion_count;
		if ( empty( $value ) ) {
			throw new RuntimeException( $message );
		}
	}

	/**
	 * Assert one string contains another.
	 *
	 * @param string $needle  Required substring.
	 * @param string $haystack Searched string.
	 * @param string $message Failure message.
	 */
	private function assert_string_contains( string $needle, string $haystack, string $message ): void {
		$this->assert_true( str_contains( $haystack, $needle ), $message );
	}

	/**
	 * Render a compact diagnostic value.
	 *
	 * @param mixed $value Value.
	 * @return string Description.
	 */
	private function describe( mixed $value ): string {
		$encoded = wp_json_encode( $value );
		return false === $encoded ? get_debug_type( $value ) : $encoded;
	}
}

( new Knabbel_E2E_Suite() )->run();
