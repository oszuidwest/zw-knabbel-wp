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
	private const BABBEL_BASE_URL         = 'http://babbel:8080/api/v1';
	private const STORY_HOOK              = 'knabbel_process_story';
	private const FEW_SHOT_HOOK           = 'knabbel_sync_few_shot_examples';
	private const ACTION_GROUP            = 'zw-knabbel-wp';
	private const SEND_TO_BABBEL_META_KEY = '_zw_knabbel_send_to_babbel';
	// Keep in sync with the AI Client MU plugin and editor-flow.spec.ts.
	private const GENERATED_TEXT = 'Deterministische E2E-radiospreektekst.';

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
	 * Number of scenarios completed successfully.
	 *
	 * @var int
	 */
	private int $case_count = 0;

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
		$this->run_case( 'E2E-001', 'recurring queue and Babbel authentication', $this->test_queue_and_authentication( ... ) );
		$this->run_case( 'E2E-002', 'published post creates exactly one complete Babbel story', $this->test_published_story_creation( ... ) );
		$this->run_case( 'E2E-003', 'edits synchronize and recover from an authentication failure', $this->test_update_and_error_recovery( ... ) );
		$this->run_case( 'E2E-004', 'checkbox disable soft-deletes and re-enable restores', $this->test_checkbox_delete_and_restore( ... ) );
		$this->run_case( 'E2E-005', 'scheduled post dates update when scheduled and published', $this->test_scheduled_story_lifecycle( ... ) );
		$this->run_case( 'E2E-006', 'unscheduling cancels pending processing without creating a story', $this->test_pending_schedule_cancellation( ... ) );
		$this->run_case( 'E2E-007', 'trash and untrash delete and restore the same story', $this->test_trash_and_restore( ... ) );
		$this->run_case( 'E2E-008', 'AI provider failure retries without creating a Babbel story', $this->test_ai_failure( ... ) );
		$this->run_case( 'E2E-009', 'Babbel create failure is visible and preserves diagnostics safely', $this->test_babbel_create_failure( ... ) );
		$this->run_case( 'E2E-010', 'few-shot queue learns editor changes and honors disable', $this->test_few_shot_sync( ... ) );
		$this->run_case( 'E2E-011', 'enabling existing published and future posts schedules one story', $this->test_enable_existing_posts( ... ) );
		$this->run_case( 'E2E-012', 'checkbox and trash cancel scheduled and processing work', $this->test_pending_work_cancellation( ... ) );
		$this->run_case( 'E2E-013', 'every transition away from future deletes a sent story', $this->test_sent_story_unscheduling( ... ) );
		$this->run_case( 'E2E-014', 'meta deletion and rapid toggles preserve lifecycle invariants', $this->test_meta_deletion_and_rapid_toggles( ... ) );
		$this->run_case( 'E2E-015', 'in-flight story states prevent duplicate scheduling', $this->test_in_flight_state_guards( ... ) );
		$this->run_case( 'E2E-016', 'untrash honors a disabled checkbox', $this->test_untrash_with_checkbox_disabled( ... ) );
		$this->run_case( 'E2E-017', 'delete and restore failures remain retryable', $this->test_delete_and_restore_failures( ... ) );
		$this->run_case( 'E2E-018', 'deactivation clears sessions, caches and scheduled actions', $this->test_deactivation_cleanup( ... ) );

		WP_CLI::success( sprintf( '%d E2E scenarios passed with %d assertions.', $this->case_count, $this->assertion_count ) );
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
			++$this->case_count;
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

		$settings = array_merge(
			$settings,
			array(
				'api_base_url'      => self::BABBEL_BASE_URL,
				'api_username'      => 'admin',
				'api_password'      => $password,
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
	 * Verify a single recurring action, login and 401 retry.
	 */
	private function test_queue_and_authentication(): void {
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
		$this->assert_true( 'invalid-e2e-session' !== ( get_transient( $cache_key )[0]->value ?? null ), 'The invalid session cookie must be replaced.' );
	}

	/**
	 * Verify publish scheduling, queue execution, payload fidelity and send-once safety.
	 */
	private function test_published_story_creation(): void {
		$title          = 'E2E gepubliceerd – één';
		$content        = 'Dit artikel bevat voldoende woorden om de volledige publicatieketen betrouwbaar te testen.';
		$example_input  = 'Voorbeeldartikel voor de native AI Client-gespreksgeschiedenis.';
		$example_output = 'Voorbeeld van gecorrigeerde radiospreektekst.';

		update_option(
			'knabbel_few_shot_examples',
			array(
				array(
					'input'  => $example_input,
					'output' => $example_output,
				),
			),
			false
		);

		$post_id = $this->create_enabled_draft( $title, $content );
		$this->assert_same( 0, $this->story_action_count( $post_id ), 'Enabling a draft must not schedule processing.' );

		$dates_before = KnabbelWP\calculate_story_dates( 'now' );
		$this->update_post( $post_id, array( 'post_status' => 'publish' ) );
		$this->assert_story_status( $post_id, StoryStatus::Scheduled, 'Publishing must mark the story scheduled.' );

		$this->update_post( $post_id, array( 'post_title' => $title ) );
		$this->assert_same( 1, $this->story_action_count( $post_id ), 'Repeated saves must not duplicate the pending action.' );

		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );
		$dates_after = KnabbelWP\calculate_story_dates( 'now' );
		$state       = KnabbelWP\get_story_state( $post_id );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'The worker must mark a created story sent.' );
		$this->assert_same( self::GENERATED_TEXT, $state['generated_speech_text'] ?? null, 'The generated speech text must be persisted.' );
		$ai_request = get_option( 'knabbel_e2e_ai_last_request', array() );
		$this->assert_true( is_array( $ai_request ), 'The native AI provider request must be observable.' );
		$this->assert_same( 1000, $ai_request['max_output_tokens'] ?? null, 'The native AI request must retain the output token limit.' );
		$this->assert_same( 0.7, $ai_request['temperature'] ?? null, 'The native AI request must retain the configured temperature.' );
		$request_input = (string) wp_json_encode( $ai_request['input'] ?? array() );
		$this->assert_string_contains( $example_input, $request_input, 'The native AI request must include the few-shot user example.' );
		$this->assert_string_contains( $example_output, $request_input, 'The native AI request must include the few-shot model example.' );
		delete_option( 'knabbel_few_shot_examples' );

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
		$this->babbel_request(
			'PUT',
			'/stories/' . $this->published_story_id,
			array(
				'text'   => $edited_text,
				'status' => $original_story['status'] ?? 'draft',
			)
		);

		$new_content = 'De inhoud verandert, maar bestaand Babbel-speechmateriaal blijft bewust en aantoonbaar ongewijzigd.';
		$this->update_post( $this->published_post_id, array( 'post_content' => $new_content ) );
		$story = $this->get_babbel_story( $this->published_story_id );
		$this->assert_same( $edited_text, $story['text'] ?? null, 'Content-only edits must not overwrite edited Babbel speech text.' );

		$this->update_post( $this->published_post_id, array( 'post_title' => 'E2E titel gesynchroniseerd' ) );
		$story = $this->get_babbel_story( $this->published_story_id );
		$this->assert_same( 'E2E titel gesynchroniseerd', $story['title'] ?? null, 'Title edits must synchronize immediately.' );

		$this->configure_plugin( 'definitely-wrong-password' );
		$this->update_post( $this->published_post_id, array( 'post_title' => 'E2E titel tijdens fout' ) );
		$this->assert_sync_failure_preserved( $this->published_post_id, $this->published_story_id, StoryStatus::Sent, 'update' );
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
		update_post_meta( $this->published_post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->assert_story_status( $this->published_post_id, StoryStatus::Deleted, 'Disabling the checkbox must mark the story deleted.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $this->published_story_id );

		$this->update_post( $this->published_post_id, array( 'post_title' => 'E2E titel na verwijderen' ) );
		update_post_meta( $this->published_post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
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
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );

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
		$this->assert_same( 0, $this->count_babbel_stories_by_title( $title ), 'Canceled work must never create a Babbel story.' );
	}

	/**
	 * Verify post trash hooks soft-delete and untrash restores the same record.
	 */
	private function test_trash_and_restore(): void {
		list( $post_id, $story_id ) = $this->create_sent_story( 'E2E prullenbak', 'Een gepubliceerd artikel wordt verwijderd en daarna veilig teruggezet.' );
		$this->assert_story_status( $post_id, StoryStatus::Sent, 'Published fixture must reach sent state.' );

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
	 * Verify retry count and no remote side effect when the AI provider is unavailable.
	 */
	private function test_ai_failure(): void {
		$title = 'E2E AI-providerfout';
		update_option( 'knabbel_e2e_ai_call_count', 0, false );
		add_filter( 'wp_supports_ai', '__return_false' );
		try {
			$this->assert_same(
				null,
				KnabbelWP\ai_generate_content( 'Unsupported AI must fail without a provider request.' ),
				'Unsupported text generation must fail immediately.'
			);
			$this->assert_same( 0, (int) get_option( 'knabbel_e2e_ai_call_count', 0 ), 'Unsupported text generation must not call the provider.' );
		} finally {
			remove_filter( 'wp_supports_ai', '__return_false' );
		}

		update_option( 'knabbel_e2e_ai_mode', 'error', false );
		update_option( 'knabbel_e2e_ai_call_count', 0, false );

		$post_id = $this->create_enabled_draft( $title, 'De AI-provider faalt deterministisch zodat foutafhandeling en retries aantoonbaar blijven.' );
		$this->publish_and_process( $post_id );

		$this->assert_story_status( $post_id, StoryStatus::Error, 'AI provider exhaustion must mark processing as error.' );
		$this->assert_same( 3, (int) get_option( 'knabbel_e2e_ai_call_count', 0 ), 'The AI provider must be attempted exactly three times.' );
		$this->assert_same( 0, $this->count_babbel_stories_by_title( $title ), 'AI provider failure must not create a Babbel story.' );

		update_option( 'knabbel_e2e_ai_mode', 'success', false );
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
		$this->babbel_request(
			'PUT',
			'/stories/' . $this->published_story_id,
			array(
				'text'   => $edited_text,
				'status' => 'active',
			)
		);

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
	 * Verify both metadata hook variants enable already-public stories.
	 */
	private function test_enable_existing_posts(): void {
		$published_title = 'E2E bestaand gepubliceerd';
		$published_id    = $this->create_draft(
			$published_title,
			'Een bestaand gepubliceerd bericht krijgt radionieuws pas na publicatie ingeschakeld.'
		);
		$this->update_post( $published_id, array( 'post_status' => 'publish' ) );
		update_post_meta( $published_id, self::SEND_TO_BABBEL_META_KEY, 0 );

		$this->assert_same( array(), KnabbelWP\get_story_state( $published_id ), 'Publishing while disabled must not create story state.' );
		$this->assert_same( 0, $this->story_action_count( $published_id ), 'Publishing while disabled must not queue processing.' );

		update_post_meta( $published_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->assert_story_status( $published_id, StoryStatus::Scheduled, 'Enabling an existing published post must schedule processing.' );
		$this->assert_same( 1, $this->story_action_count( $published_id ), 'Published enable must queue exactly one action.' );
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $published_id ) );
		$this->assert_story_status( $published_id, StoryStatus::Sent, 'The enabled published post must reach sent state.' );
		$this->assert_same( 1, $this->count_babbel_stories_by_title( $published_title ), 'Published enable must create exactly one story.' );

		$future_title = 'E2E bestaand gepland';
		$future_id    = $this->create_draft(
			$future_title,
			'Een bestaand gepland bericht krijgt radionieuws pas na het plannen ingeschakeld.'
		);
		$future_date  = $this->future_post_date( 18 );
		$this->schedule_post( $future_id, $future_date );

		$this->assert_same( array(), KnabbelWP\get_story_state( $future_id ), 'Scheduling while disabled must not create story state.' );
		$this->assert_same( 0, $this->story_action_count( $future_id ), 'Scheduling while disabled must not queue processing.' );

		add_post_meta( $future_id, self::SEND_TO_BABBEL_META_KEY, 1, true );
		$this->assert_story_status( $future_id, StoryStatus::Scheduled, 'Adding enabled meta to an existing future post must schedule processing.' );
		$this->assert_same( 1, $this->story_action_count( $future_id ), 'Future enable must queue exactly one action.' );
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $future_id ) );

		$state = KnabbelWP\get_story_state( $future_id );
		$story = $this->get_babbel_story( (string) ( $state['story_id'] ?? '' ) );
		$dates = KnabbelWP\calculate_story_dates( $future_date );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'The enabled future post must reach sent state.' );
		$this->assert_same( $dates['start_date'], $this->date_only( $story['start_date'] ?? '' ), 'Future enable must retain scheduled dates.' );
		$this->assert_same( 1, $this->count_babbel_stories_by_title( $future_title ), 'Future enable must create exactly one story.' );
	}

	/**
	 * Verify pending work is canceled for both cancellable lifecycle states.
	 */
	private function test_pending_work_cancellation(): void {
		foreach ( array( 'checkbox', 'trash' ) as $cancel_via ) {
			foreach ( array( 'scheduled', 'processing' ) as $prior_state ) {
				$label   = $cancel_via . ' ' . $prior_state;
				$title   = 'E2E annulering ' . $label;
				$post_id = $this->create_enabled_draft(
					$title,
					'Een nog niet uitgevoerde storyactie moet zonder externe bijwerking worden geannuleerd.'
				);
				$this->update_post( $post_id, array( 'post_status' => 'publish' ) );
				$this->assert_same( 1, $this->story_action_count( $post_id ), 'Precondition: one story action must be pending.' );

				if ( 'processing' === $prior_state ) {
					// Synthetic pairing: the worker only sets Processing after claiming the pending action.
					KnabbelWP\update_story_state( $post_id, array( 'status' => StoryStatus::Processing->value ) );
				}

				if ( 'trash' === $cancel_via ) {
					wp_trash_post( $post_id );
				} else {
					update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
				}

				$this->assert_same( 0, $this->story_action_count( $post_id ), $label . ' must cancel pending processing.' );
				$this->assert_same( array(), KnabbelWP\get_story_state( $post_id ), $label . ' must clear transient story state.' );
				$this->assert_same( 0, $this->count_babbel_stories_by_title( $title ), $label . ' must not create a remote story.' );
			}
		}
	}

	/**
	 * Verify all non-publish transitions away from future remove sent stories.
	 */
	private function test_sent_story_unscheduling(): void {
		foreach ( array( 'draft', 'pending', 'private', 'trash' ) as $new_status ) {
			$title   = 'E2E verzonden planning naar ' . $new_status;
			$post_id = $this->create_enabled_draft(
				$title,
				'Een reeds verzonden geplande story wordt verwijderd wanneer de planning vervalt.'
			);
			$this->schedule_post( $post_id, $this->future_post_date( 20 ) );
			$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );

			$state    = KnabbelWP\get_story_state( $post_id );
			$story_id = (string) ( $state['story_id'] ?? '' );
			$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'Precondition: scheduled story must be sent.' );

			if ( 'trash' === $new_status ) {
				wp_trash_post( $post_id );
			} else {
				$this->update_post( $post_id, array( 'post_status' => $new_status ) );
			}
			$this->assert_story_status( $post_id, StoryStatus::Deleted, 'Future to ' . $new_status . ' must mark the story deleted.' );
			$this->assert_same( 0, $this->story_action_count( $post_id ), 'Future to ' . $new_status . ' must leave no pending action.' );
			$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );
		}
	}

	/**
	 * Verify metadata deletion and rapid changes use the same lifecycle rules.
	 */
	private function test_meta_deletion_and_rapid_toggles(): void {
		$delete_title = 'E2E checkboxmeta verwijderd';

		list( $delete_id, $story_id ) = $this->create_sent_story(
			$delete_title,
			'Het volledig verwijderen van de checkboxmeta moet hetzelfde werken als uitschakelen.'
		);

		delete_post_meta( $delete_id, self::SEND_TO_BABBEL_META_KEY );
		$this->assert_story_status( $delete_id, StoryStatus::Deleted, 'Deleting enabled checkbox meta must delete the story.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );

		add_post_meta( $delete_id, self::SEND_TO_BABBEL_META_KEY, 1, true );
		$state = KnabbelWP\get_story_state( $delete_id );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'Re-adding checkbox meta must restore the story.' );
		$this->assert_same( $story_id, (string) ( $state['story_id'] ?? '' ), 'Meta restore must reuse the original story ID.' );
		$this->assert_babbel_response_code( 200, 'GET', '/stories/' . $story_id );

		$toggle_title = 'E2E snelle checkboxwissel';
		$toggle_id    = $this->create_draft(
			$toggle_title,
			'Snel in- en uitschakelen mag nooit dubbele storyacties of externe stories veroorzaken.'
		);
		$this->update_post( $toggle_id, array( 'post_status' => 'publish' ) );

		update_post_meta( $toggle_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->assert_same( 1, $this->story_action_count( $toggle_id ), 'First enable must queue one action.' );
		update_post_meta( $toggle_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->assert_same( 0, $this->story_action_count( $toggle_id ), 'Disable must cancel the queued action.' );
		$this->assert_same( array(), KnabbelWP\get_story_state( $toggle_id ), 'Disable must clear the queued lifecycle state.' );
		update_post_meta( $toggle_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		KnabbelWP\handle_checkbox_change( $toggle_id, false, true );
		$this->assert_same( 1, $this->story_action_count( $toggle_id ), 'A duplicate enable event must retain exactly one action.' );

		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $toggle_id ) );
		$this->assert_same( 1, $this->count_babbel_stories_by_title( $toggle_title ), 'Rapid toggles must create exactly one story.' );
	}

	/**
	 * Verify scheduled and processing state guards prevent duplicate work.
	 */
	private function test_in_flight_state_guards(): void {
		$scheduled_id = $this->create_draft(
			'E2E guard scheduled',
			'Een bestaande scheduled-state mag bij het inschakelen geen tweede actie krijgen.'
		);
		$this->update_post( $scheduled_id, array( 'post_status' => 'publish' ) );
		KnabbelWP\schedule_story_processing( $scheduled_id );
		$this->assert_same( 1, $this->story_action_count( $scheduled_id ), 'Precondition: scheduled state must have one action.' );

		update_post_meta( $scheduled_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->assert_same( 1, $this->story_action_count( $scheduled_id ), 'Enabling scheduled state must not duplicate its action.' );
		$this->assert_story_status( $scheduled_id, StoryStatus::Scheduled, 'Enabling scheduled state must preserve its lifecycle status.' );

		$processing_id = $this->create_draft(
			'E2E guard processing',
			'Een bestaande processing-state mag bij het inschakelen geen nieuwe actie krijgen.'
		);
		$this->update_post( $processing_id, array( 'post_status' => 'publish' ) );
		KnabbelWP\update_story_state( $processing_id, array( 'status' => StoryStatus::Processing->value ) );

		update_post_meta( $processing_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->assert_same( 0, $this->story_action_count( $processing_id ), 'Enabling processing state must not queue another action.' );
		$this->assert_story_status( $processing_id, StoryStatus::Processing, 'Enabling processing state must preserve its lifecycle status.' );
	}

	/**
	 * Verify restoring a post does not restore a deliberately disabled story.
	 */
	private function test_untrash_with_checkbox_disabled(): void {
		list( $post_id, $story_id ) = $this->create_sent_story(
			'E2E prullenbak checkbox uit',
			'Een story blijft verwijderd als radionieuws in de prullenbak is uitgeschakeld.'
		);

		wp_trash_post( $post_id );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		wp_untrash_post( $post_id );

		$state = KnabbelWP\get_story_state( $post_id );
		$this->assert_same( StoryStatus::Deleted->value, $state['status'] ?? null, 'Untrash with checkbox disabled must preserve deleted state.' );
		$this->assert_same( $story_id, (string) ( $state['story_id'] ?? '' ), 'Disabled untrash must retain the deleted story ID.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );
	}

	/**
	 * Verify failed delete and restore transitions retain enough state to retry.
	 */
	private function test_delete_and_restore_failures(): void {
		list( $post_id, $story_id ) = $this->create_sent_story(
			'E2E verwijder- en herstelfout',
			'Mislukte synchronisatieovergangen moeten zonder verlies van lifecycle-state opnieuw uitvoerbaar blijven.'
		);

		$this->configure_plugin( 'wrong-delete-password' );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->assert_sync_failure_preserved( $post_id, $story_id, StoryStatus::Sent, 'delete' );
		$this->assert_babbel_response_code( 200, 'GET', '/stories/' . $story_id );

		$this->configure_plugin();
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		$this->assert_story_status( $post_id, StoryStatus::Deleted, 'A retried delete must reach deleted state.' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );

		$this->configure_plugin( 'wrong-restore-password' );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$this->assert_sync_failure_preserved( $post_id, $story_id, StoryStatus::Deleted, 'restore' );
		$this->assert_babbel_response_code( 404, 'GET', '/stories/' . $story_id );

		$this->configure_plugin();
		$retry_title = 'E2E verwijder- en herstelfout hersteld';
		$this->update_post( $post_id, array( 'post_title' => $retry_title ) );
		// Reset the checkbox edge so the next enable retries the failed restore.
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 0 );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );
		$state = KnabbelWP\get_story_state( $post_id );
		$this->assert_same( StoryStatus::Sent->value, $state['status'] ?? null, 'A retried restore must reach sent state.' );
		$this->assert_false( isset( $state['last_sync_error'] ), 'Successful restore retry must clear its previous error.' );
		$story = $this->get_babbel_story( $story_id );
		$this->assert_same( $retry_title, $story['title'] ?? null, 'Successful restore retry must synchronize the current title.' );
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

		$pending_id = $this->create_enabled_draft(
			'E2E deactivatie wachtrij',
			'Een nog wachtende geparametriseerde storyactie moet bij deactivatie worden opgeruimd.'
		);
		$this->update_post( $pending_id, array( 'post_status' => 'publish' ) );
		$this->assert_same( 1, $this->story_action_count( $pending_id ), 'Precondition: a parameterized story action must be pending.' );

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
	 * Create a draft post with radio news enabled and fail on WordPress errors.
	 *
	 * @param string $title   Post title.
	 * @param string $content Post content.
	 * @return int Post ID.
	 * @throws RuntimeException When WordPress cannot create the post.
	 */
	private function create_enabled_draft( string $title, string $content ): int {
		$post_id = $this->create_draft( $title, $content );
		update_post_meta( $post_id, self::SEND_TO_BABBEL_META_KEY, 1 );

		return $post_id;
	}

	/**
	 * Create a draft post without checkbox metadata and fail on WordPress errors.
	 *
	 * @param string $title   Post title.
	 * @param string $content Post content.
	 * @return int Post ID.
	 * @throws RuntimeException When WordPress cannot create the post.
	 */
	private function create_draft( string $title, string $content ): int {
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
		$this->run_action_scheduler( self::STORY_HOOK, 1, array( 'post_id' => $post_id ) );
	}

	/**
	 * Create an enabled draft, publish it and process it into a sent story.
	 *
	 * @param string $title   Post title.
	 * @param string $content Post content.
	 * @return array{0: int, 1: string} Post ID and Babbel story ID.
	 */
	private function create_sent_story( string $title, string $content ): array {
		$post_id = $this->create_enabled_draft( $title, $content );
		$this->publish_and_process( $post_id );
		$story_id = (string) ( KnabbelWP\get_story_state( $post_id )['story_id'] ?? '' );

		return array( $post_id, $story_id );
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
	 * Delegates to the shared helper in the knabbel-e2e-control MU plugin.
	 *
	 * @param string                    $hook           Hook to execute.
	 * @param int                       $expected_count Expected number of actions to process.
	 * @param array<string, mixed>|null $args           Optional exact action arguments.
	 * @throws RuntimeException When an action does not complete successfully.
	 */
	private function run_action_scheduler( string $hook, int $expected_count = 1, ?array $args = null ): void {
		if ( ! function_exists( 'knabbel_e2e_run_due_actions' ) ) {
			throw new RuntimeException( 'The Knabbel E2E control MU plugin is required. Check the tests/e2e/compose.yml mount.' );
		}

		$processed = knabbel_e2e_run_due_actions( $hook, self::ACTION_GROUP, $args );
		$this->assert_same( $expected_count, $processed, sprintf( 'Expected %d due action(s) for hook %s.', $expected_count, $hook ) );
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
		// Keep the Babbel fixture credentials in sync with tests/playwright/utils.ts.
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
		$stories = $decoded['data'] ?? null;
		$this->assert_true( is_array( $stories ), 'Babbel story list data must be an array.' );
		// The filter is applied server-side, so a full page means results were truncated.
		$this->assert_true( count( $stories ) < 100, 'Babbel story list must not fill the verification page.' );

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
	 * Assert a failed sync operation preserved the lifecycle state needed for a retry.
	 *
	 * @param int         $post_id   Post ID.
	 * @param string      $story_id  Expected Babbel story ID.
	 * @param StoryStatus $status    Expected preserved lifecycle status.
	 * @param string      $operation Expected failed sync operation.
	 */
	private function assert_sync_failure_preserved( int $post_id, string $story_id, StoryStatus $status, string $operation ): void {
		$state          = KnabbelWP\get_story_state( $post_id );
		$message_prefix = ucfirst( $operation ) . ' failure must ';
		$this->assert_same( $status->value, $state['status'] ?? null, $message_prefix . 'preserve ' . $status->value . ' state.' );
		$this->assert_same( $story_id, (string) ( $state['story_id'] ?? '' ), $message_prefix . 'preserve the story ID.' );
		$this->assert_same( $operation, $state['last_sync_error']['operation'] ?? null, $message_prefix . 'identify its operation.' );
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
		$actual   = array(
			'start_date' => $this->date_only( $story['start_date'] ?? '' ),
			'end_date'   => $this->date_only( $story['end_date'] ?? '' ),
		);
		$expected = array(
			array(
				'start_date' => $before['start_date'],
				'end_date'   => $before['end_date'],
			),
			array(
				'start_date' => $after['start_date'],
				'end_date'   => $after['end_date'],
			),
		);

		$this->assert_true(
			in_array( $actual, $expected, true ),
			$message
		);
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
