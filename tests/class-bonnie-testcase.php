<?php
/**
 * Shared fixtures for Bonnie tests.
 *
 * @package Bonnie
 */

/**
 * Base test case: empty plugin tables and run record, a delete policy, and
 * helpers to seed submissions and force SQL errors.
 */
abstract class Bonnie_TestCase extends WP_UnitTestCase {

	/**
	 * Plugin tables are real tables created once in the bootstrap, and
	 * Bonnie_Store::delete_submissions() opens its own transaction (which
	 * commits the test's), so each test starts from empty tables instead of
	 * relying on rollback.
	 */
	public function set_up() {
		parent::set_up();
		$this->empty_tables();
		delete_option( Bonnie_Retention_Status::OPTION );
		$this->set_policy( 'delete', 30 );
	}

	public function tear_down() {
		$this->empty_tables();
		delete_option( Bonnie_Retention_Status::OPTION );
		global $wpdb;
		$wpdb->suppress_errors( false );
		parent::tear_down();
	}

	protected function empty_tables() {
		global $wpdb;
		foreach ( array( 'submissions', 'submission_meta', 'retention_log' ) as $table ) {
			$wpdb->query( 'DELETE FROM `' . Bonnie_Store::table( $table ) . '`' ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Set the global retention policy.
	 *
	 * @param string $policy none | delete.
	 * @param int    $days   Window.
	 */
	protected function set_policy( $policy, $days = 30 ) {
		$settings                     = Bonnie_Store::get_settings();
		$settings['retention_policy'] = $policy;
		$settings['retention_days']   = $days;
		update_option( Bonnie_Store::OPTION_SETTINGS, $settings );
	}

	/**
	 * Insert submissions (each with one meta row).
	 *
	 * @param string $created_at MySQL datetime.
	 * @param int    $count      How many.
	 * @param int    $form_id    Form id.
	 * @return int[] Ids.
	 */
	protected function insert_submissions( $created_at, $count = 1, $form_id = 1 ) {
		global $wpdb;
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$wpdb->insert(
				Bonnie_Store::table( 'submissions' ),
				array(
					'form_id'    => $form_id,
					'subject'    => 'Test',
					'from_name'  => 'Test',
					'from_email' => 'test@example.com',
					'created_at' => $created_at,
				)
			);
			$ids[] = (int) $wpdb->insert_id;
			$wpdb->insert(
				Bonnie_Store::table( 'submission_meta' ),
				array(
					'submission_id' => $wpdb->insert_id,
					'meta_key'      => 'message', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'    => 'Hello', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
		}
		return $ids;
	}

	protected function count_rows( $table ) {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . Bonnie_Store::table( $table ) . '`' ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Make every query matching a pattern fail, as a real SQL error would.
	 * Hooks are restored by WP_UnitTestCase after each test.
	 *
	 * @param string $pattern Regex matched against the SQL.
	 */
	protected function fail_queries( $pattern ) {
		global $wpdb;
		$wpdb->suppress_errors( true );
		add_filter(
			'query',
			static function ( $query ) use ( $pattern ) {
				return preg_match( $pattern, $query ) ? 'SELECT * FROM `bonnie_test_missing_table`' : $query;
			}
		);
	}

	/** A datetime well outside a 30-day window. */
	protected function old() {
		return gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
	}

	/** A datetime inside a 30-day window. */
	protected function recent() {
		return gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
	}
}
