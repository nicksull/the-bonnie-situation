<?php
/**
 * Bonnie_Retention_Status: the run record, health, stale detection.
 *
 * @package Bonnie
 */

class Test_Bonnie_Retention_Status extends Bonnie_TestCase {

	/** @var Bonnie_Retention_Status */
	protected $status;

	public function set_up() {
		parent::set_up();
		$this->status = new Bonnie_Retention_Status();
		if ( ! wp_next_scheduled( BONNIE_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', BONNIE_CRON_HOOK );
		}
	}

	protected function set_record( array $fields ) {
		update_option( Bonnie_Retention_Status::OPTION, array_merge( $this->status->get(), $fields ) );
	}

	public function test_two_failed_scopes_in_one_run_count_as_one_failure() {
		$this->status->start();
		$this->status->fail( 'global', 'one' );
		$this->status->fail( 'addon', 'two' );
		$this->status->finish();

		$record = $this->status->get();
		$this->assertSame( 1, $record['failures'] );
		$this->assertSame( 'addon', $record['last_error_scope'] );
		$this->assertSame( 2, did_action( 'bonnie_retention_failed' ) );
	}

	public function test_consecutive_failed_runs_are_counted() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->status->start();
			$this->status->fail( 'global', 'boom' );
			$this->status->finish();
		}

		$this->assertSame( 3, $this->status->get()['failures'] );
	}

	public function test_failed_action_receives_message_scope_and_record() {
		$args = null;
		add_action(
			'bonnie_retention_failed',
			static function ( $message, $scope, $record ) use ( &$args ) {
				$args = array( $message, $scope, $record['failures'] );
			},
			10,
			3
		);

		$this->status->start();
		$this->status->fail( 'global', new RuntimeException( '<b>DB</b> gone' ) );

		$this->assertSame( array( 'DB gone', 'global', 1 ), $args );
	}

	public function test_long_error_is_truncated() {
		$this->status->fail( 'global', str_repeat( 'x', 2000 ) );

		$this->assertSame( Bonnie_Retention_Status::MAX_ERROR_LENGTH, strlen( $this->status->get()['last_error'] ) );
	}

	public function test_recovered_fires_only_after_a_problem() {
		$this->status->start();
		$this->status->finish();
		$this->assertSame( 0, did_action( 'bonnie_retention_recovered' ) );

		$this->status->start();
		$this->status->fail( 'global', 'boom' );
		$this->status->finish();
		$this->status->start();
		$this->status->finish();

		$this->assertSame( 1, did_action( 'bonnie_retention_recovered' ) );
		$this->assertSame( '', $this->status->get()['last_error'] );
	}

	public function test_last_run_failed_reflects_the_current_run() {
		$this->status->start();
		$this->status->fail( 'global', 'boom' );
		$this->status->finish();
		$this->assertTrue( $this->status->last_run_failed() );

		$this->status->start();
		$this->status->finish();
		$this->assertFalse( $this->status->last_run_failed() );
	}

	public function test_health_is_disabled_without_a_policy() {
		$this->set_policy( 'none' );

		$this->assertSame( 'disabled', $this->status->health()['state'] );
	}

	public function test_override_with_a_real_policy_enables_health() {
		$this->set_policy( 'none' );
		add_filter( 'bonnie_overriding_form_ids', static fn() => array( 5 ) );
		add_filter(
			'bonnie_effective_retention',
			static function ( $resolved, $form_id ) {
				return 5 === $form_id ? array_merge( $resolved, array( 'policy' => 'trash' ) ) : $resolved;
			},
			10,
			2
		);

		$this->assertTrue( $this->status->enabled() );
	}

	public function test_override_that_retains_forever_does_not_enable_health() {
		$this->set_policy( 'none' );
		add_filter( 'bonnie_overriding_form_ids', static fn() => array( 5 ) );

		$this->assertFalse( $this->status->enabled() );
	}

	public function test_health_is_stale_after_48_hours_without_success() {
		$this->set_record( array( 'last_success' => time() - 3 * DAY_IN_SECONDS ) );

		$health = $this->status->health();
		$this->assertSame( 'stale', $health['state'] );
		$this->assertSame( 'stalled', $health['reason'] );
	}

	public function test_health_is_ok_within_48_hours() {
		$this->set_record( array( 'last_success' => time() - DAY_IN_SECONDS ) );

		$this->assertSame( 'ok', $this->status->health()['state'] );
	}

	public function test_never_run_uses_first_seen_as_the_baseline() {
		$this->set_record( array( 'first_seen' => time() - 3 * DAY_IN_SECONDS ) );
		$this->assertSame( 'stale', $this->status->health()['state'] );

		$this->set_record( array( 'first_seen' => time() - HOUR_IN_SECONDS ) );
		$this->assertSame( 'ok', $this->status->health()['state'] );
	}

	public function test_health_reports_a_missing_cron_event() {
		wp_clear_scheduled_hook( BONNIE_CRON_HOOK );

		$health = $this->status->health();
		$this->assertSame( 'stale', $health['state'] );
		$this->assertSame( 'unscheduled', $health['reason'] );
	}

	public function test_check_recreates_a_missing_cron_event() {
		wp_clear_scheduled_hook( BONNIE_CRON_HOOK );

		$this->status->check();

		$this->assertNotFalse( wp_next_scheduled( BONNIE_CRON_HOOK ) );
		$this->assertSame( 'ok', $this->status->health()['state'] );
	}

	public function test_check_sets_first_seen() {
		$this->status->check();

		$this->assertGreaterThan( 0, $this->status->get()['first_seen'] );
	}

	public function test_check_fires_stale_once_per_stall() {
		$this->set_record( array( 'last_success' => time() - 3 * DAY_IN_SECONDS ) );

		$this->status->check();
		$this->status->check();
		$this->assertSame( 1, did_action( 'bonnie_retention_stale' ) );

		// A good run ends the stall and announces recovery.
		$this->status->start();
		$this->status->finish();
		$this->assertSame( 1, did_action( 'bonnie_retention_recovered' ) );
	}

	public function test_check_does_not_fire_stale_while_healthy_or_disabled() {
		$this->status->check();
		$this->set_policy( 'none' );
		$this->set_record( array( 'last_success' => time() - 3 * DAY_IN_SECONDS ) );
		$this->status->check();

		$this->assertSame( 0, did_action( 'bonnie_retention_stale' ) );
	}

	public function test_describe_is_empty_when_healthy_and_explains_problems() {
		$this->assertSame( '', Bonnie_Retention_Status::describe( $this->status->health() ) );

		$this->status->fail( 'global', 'DB gone' );
		$this->assertStringContainsString( 'DB gone', Bonnie_Retention_Status::describe( $this->status->health() ) );
	}

	public function test_site_health_is_critical_when_failing() {
		$this->assertSame( 'good', $this->status->site_health_test()['status'] );

		$this->status->fail( 'global', 'DB gone' );

		$result = $this->status->site_health_test();
		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'DB gone', $result['description'] );
	}

	public function test_site_health_test_is_registered() {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );

		$this->assertArrayHasKey( 'bonnie_retention', $tests['direct'] );
	}

	public function test_error_text_is_escaped_in_site_health() {
		$this->status->fail( 'global', 'a & b' );

		$this->assertStringContainsString( 'a &amp; b', $this->status->site_health_test()['description'] );
	}
}
