<?php
/**
 * Bonnie_Retention: the scheduled purge and how it reports failures.
 *
 * @package Bonnie
 */

class Test_Bonnie_Retention extends Bonnie_TestCase {

	/** @var Bonnie_Retention_Status */
	protected $status;

	/** @var Bonnie_Retention */
	protected $engine;

	public function set_up() {
		parent::set_up();
		$this->status = new Bonnie_Retention_Status();
		$this->engine = new Bonnie_Retention( new Bonnie_Store(), new Bonnie_Logger(), $this->status );
	}

	public function test_deletes_only_rows_past_the_window() {
		$this->insert_submissions( $this->old(), 3 );
		$this->insert_submissions( $this->recent(), 2 );

		$this->engine->run();

		$this->assertSame( 2, $this->count_rows( 'submissions' ) );
		$this->assertSame( 2, $this->count_rows( 'submission_meta' ) );
	}

	public function test_audit_log_records_rows_deleted() {
		$this->insert_submissions( $this->old(), 3 );

		$this->engine->run();

		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM `' . Bonnie_Store::table( 'retention_log' ) . '` ORDER BY id DESC LIMIT 1' ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'deleted', $row->action );
		$this->assertSame( 'global', $row->scope );
		$this->assertSame( 3, (int) $row->affected_rows );
	}

	public function test_policy_none_deletes_nothing() {
		$this->set_policy( 'none' );
		$this->insert_submissions( $this->old(), 2 );

		$this->engine->run();

		$this->assertSame( 2, $this->count_rows( 'submissions' ) );
	}

	public function test_successful_run_is_recorded() {
		$this->insert_submissions( $this->old(), 1 );

		$this->engine->run();

		$record = $this->status->get();
		$this->assertGreaterThan( 0, $record['last_success'] );
		$this->assertSame( 0, $record['failures'] );
		$this->assertSame( 'ok', $this->status->health()['state'] );
		$this->assertSame( 0, did_action( 'bonnie_retention_failed' ) );
	}

	public function test_failed_delete_is_reported_and_keeps_rows_and_writes_no_log() {
		$this->insert_submissions( $this->old(), 2 );
		$this->fail_queries( '/^DELETE FROM `' . preg_quote( Bonnie_Store::table( 'submission_meta' ), '/' ) . '`/' );

		$this->engine->run();

		$this->assertSame( 2, $this->count_rows( 'submissions' ) );
		$this->assertSame( 0, $this->count_rows( 'retention_log' ) );
		$record = $this->status->get();
		$this->assertSame( 1, $record['failures'] );
		$this->assertSame( 'global', $record['last_error_scope'] );
		$this->assertSame( 0, $record['last_success'] );
		$this->assertSame( 'failing', $this->status->health()['state'] );
		$this->assertSame( 1, did_action( 'bonnie_retention_failed' ) );
	}

	public function test_failed_select_is_reported_not_treated_as_nothing_to_delete() {
		$this->insert_submissions( $this->old(), 2 );
		$this->fail_queries( '/^SELECT id FROM `' . preg_quote( Bonnie_Store::table( 'submissions' ), '/' ) . '`/' );

		$this->engine->run();

		$this->assertSame( 2, $this->count_rows( 'submissions' ) );
		$this->assertSame( 'failing', $this->status->health()['state'] );
		$this->assertStringContainsString( 'select expired submissions', $this->status->get()['last_error'] );
	}

	public function test_failure_does_not_reschedule_a_follow_up() {
		$this->insert_submissions( $this->old(), 1 );
		$this->fail_queries( '/^DELETE FROM `' . preg_quote( Bonnie_Store::table( 'submission_meta' ), '/' ) . '`/' );
		$scheduled = count( _get_cron_array() );

		$this->engine->run();

		$this->assertSame( $scheduled, count( _get_cron_array() ) );
	}

	public function test_addon_failure_is_reported_and_core_sweep_still_runs() {
		$this->insert_submissions( $this->old(), 2 );
		add_action(
			'bonnie_retention_after_run',
			static function () {
				throw new RuntimeException( 'add-on broke' );
			}
		);

		$this->engine->run();

		$this->assertSame( 0, $this->count_rows( 'submissions' ) );
		$record = $this->status->get();
		$this->assertSame( 'addon', $record['last_error_scope'] );
		$this->assertSame( 'add-on broke', $record['last_error'] );
	}

	public function test_core_failure_still_runs_addon_scopes() {
		$this->insert_submissions( $this->old(), 1 );
		$this->fail_queries( '/^DELETE FROM `' . preg_quote( Bonnie_Store::table( 'submission_meta' ), '/' ) . '`/' );
		$ran = false;
		add_action(
			'bonnie_retention_after_run',
			static function () use ( &$ran ) {
				$ran = true;
			}
		);

		$this->engine->run();

		$this->assertTrue( $ran );
	}

	public function test_addon_can_report_its_own_caught_failure() {
		$status = $this->status;
		add_action(
			'bonnie_retention_after_run',
			static function () use ( $status ) {
				$status->fail( 'form:12', new RuntimeException( 'form scope broke' ) );
			}
		);

		$this->engine->run();

		$this->assertSame( 'form:12', $this->status->get()['last_error_scope'] );
		$this->assertSame( 'failing', $this->status->health()['state'] );
	}

	public function test_overriding_forms_are_excluded_from_the_global_sweep() {
		$this->insert_submissions( $this->old(), 1, 7 );
		$this->insert_submissions( $this->old(), 1, 8 );
		add_filter(
			'bonnie_overriding_form_ids',
			static function () {
				return array( 7 );
			}
		);

		$this->engine->run();

		global $wpdb;
		$this->assertSame( array( '7' ), $wpdb->get_col( 'SELECT form_id FROM `' . Bonnie_Store::table( 'submissions' ) . '`' ) ); // phpcs:ignore WordPress.DB
	}

	public function test_recovery_after_failure_resets_and_fires_recovered() {
		$this->insert_submissions( $this->old(), 1 );
		$pattern = '/^DELETE FROM `' . preg_quote( Bonnie_Store::table( 'submission_meta' ), '/' ) . '`/';
		$this->fail_queries( $pattern );
		$this->engine->run();
		remove_all_filters( 'query' );

		$this->engine->run();

		$this->assertSame( 0, $this->count_rows( 'submissions' ) );
		$this->assertSame( 0, $this->status->get()['failures'] );
		$this->assertSame( 1, did_action( 'bonnie_retention_recovered' ) );
	}
}
