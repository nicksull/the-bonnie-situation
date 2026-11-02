<?php

/**
 * Retention health — records each purge run and reports when it is failing.
 *
 * The purge runs unattended on WP-Cron, so without this a failing or stalled
 * purge is silent and submissions outlive the configured retention window. Each
 * run is bracketed by start() / finish(); any scope that throws calls fail().
 * health() combines that record with the cron schedule to spot purges that
 * never run at all (WP-Cron disabled with no system cron, a lost event, or a
 * run killed by a fatal error).
 *
 * Add-ons are told about state changes through `bonnie_retention_failed`,
 * `bonnie_retention_stale` and `bonnie_retention_recovered` (see docs/HOOKS.md).
 * Core surfaces the same state in Site Health and an admin notice.
 *
 * @link       https://beforebonnie.com
 * @since      1.3.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * Retention health.
 *
 * @since      1.3.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_Retention_Status {

	/**
	 * Option holding the run record.
	 *
	 * @var string
	 */
	const OPTION = 'bonnie_retention_status';

	/**
	 * Seconds without a successful run before the purge counts as stalled. The
	 * event is daily, so this allows one missed run (e.g. a quiet site whose
	 * WP-Cron only fires on traffic) before warning.
	 *
	 * @var int
	 */
	const STALE_AFTER = 172800;

	/**
	 * Longest stored error message.
	 *
	 * @var int
	 */
	const MAX_ERROR_LENGTH = 500;

	/**
	 * Whether a run is in progress in this request.
	 *
	 * @var bool
	 */
	protected $running = false;

	/**
	 * Whether any scope of the current run failed.
	 *
	 * @var bool
	 */
	protected $run_failed = false;

	/**
	 * The stored record, merged over defaults.
	 *
	 * - `last_started` / `last_success` / `last_failure`: Unix timestamps (0 = never).
	 * - `last_error` / `last_error_scope`: the most recent failure.
	 * - `failures`: consecutive failed runs (reset by a successful one).
	 * - `stale_alerted`: when `bonnie_retention_stale` last fired (0 = not this episode).
	 * - `first_seen`: when the record was created, the stall baseline before any run.
	 *
	 * @since  1.3.0
	 * @return array
	 */
	public function get() {
		$stored = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'last_started'     => 0,
				'last_success'     => 0,
				'last_failure'     => 0,
				'last_error'       => '',
				'last_error_scope' => '',
				'failures'         => 0,
				'stale_alerted'    => 0,
				'first_seen'       => 0,
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * Persist the record (autoloaded: it is read on every request by check()).
	 *
	 * @since 1.3.0
	 * @param array $status Record.
	 * @return void
	 */
	protected function save( array $status ) {
		update_option( self::OPTION, $status, true );
	}

	/**
	 * Mark the start of a purge run.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function start() {
		$this->running    = true;
		$this->run_failed = false;

		$status                 = $this->get();
		$status['last_started'] = time();
		$this->save( $status );
	}

	/**
	 * Record a failed scope and notify listeners.
	 *
	 * @since 1.3.0
	 * @param string           $scope Scope that failed ("global", "addon", or an add-on's own label).
	 * @param \Throwable|string $error Exception or message.
	 * @return void
	 */
	public function fail( $scope, $error ) {
		$message = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
		$message = '' === trim( $message ) ? __( 'Unknown error.', 'the-bonnie-situation' ) : $message;
		$message = mb_substr( wp_strip_all_tags( $message ), 0, self::MAX_ERROR_LENGTH );

		$status = $this->get();

		// Count runs, not scopes: a run where two scopes fail is one failure.
		if ( ! $this->running || ! $this->run_failed ) {
			++$status['failures'];
		}
		$this->run_failed = true;

		$status['last_failure']     = time();
		$status['last_error']       = $message;
		$status['last_error_scope'] = (string) $scope;
		$this->save( $status );

		/**
		 * Fires when a retention purge scope fails. Fires on every failure, so
		 * listeners that notify people should throttle (`$status['failures']` is
		 * the consecutive failed-run count).
		 *
		 * @since 1.3.0
		 * @param string $message Error message (plain text).
		 * @param string $scope   Scope that failed.
		 * @param array  $status  The run record after this failure.
		 */
		do_action( 'bonnie_retention_failed', $message, (string) $scope, $status );
	}

	/**
	 * Mark the end of a purge run; a run with no failures resets the record.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function finish() {
		if ( ! $this->running ) {
			return;
		}
		$this->running = false;

		if ( $this->run_failed ) {
			return;
		}

		$status    = $this->get();
		$recovered = $status['failures'] > 0 || $status['stale_alerted'] > 0;

		$status['last_success']     = time();
		$status['failures']         = 0;
		$status['last_error']       = '';
		$status['last_error_scope'] = '';
		$status['stale_alerted']    = 0;
		$this->save( $status );

		if ( $recovered ) {
			/**
			 * Fires on the first successful purge after failures or a stall.
			 *
			 * @since 1.3.0
			 * @param array $status The run record.
			 */
			do_action( 'bonnie_retention_recovered', $status );
		}
	}

	/**
	 * Whether the last run of this request failed. For callers that run the
	 * purge on demand (e.g. WP-CLI) and need to report the outcome.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	public function last_run_failed() {
		return $this->run_failed;
	}

	/**
	 * Whether any retention policy is in force, i.e. a stalled purge matters.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	public function enabled() {
		$global = Bonnie_Store::effective_retention( 0 );
		if ( 'none' !== $global['policy'] ) {
			return true;
		}

		/** This filter is documented in includes/class-bonnie-retention.php */
		foreach ( (array) apply_filters( 'bonnie_overriding_form_ids', array() ) as $form_id ) {
			$resolved = Bonnie_Store::effective_retention( (int) $form_id );
			if ( 'none' !== $resolved['policy'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Current health.
	 *
	 * `state` is one of:
	 * - `disabled` — no retention policy, nothing to enforce.
	 * - `ok`       — the last run succeeded recently.
	 * - `failing`  — the last run failed (`error`, `scope`).
	 * - `stale`    — the purge isn't running (`reason`: `unscheduled` or `stalled`).
	 *
	 * @since  1.3.0
	 * @return array { state, reason, error, scope, last_success, failures }
	 */
	public function health() {
		$status = $this->get();
		$out    = array(
			'state'        => 'ok',
			'reason'       => '',
			'error'        => $status['last_error'],
			'scope'        => $status['last_error_scope'],
			'last_success' => (int) $status['last_success'],
			'failures'     => (int) $status['failures'],
		);

		if ( ! $this->enabled() ) {
			$out['state'] = 'disabled';
			return $out;
		}

		if ( $status['failures'] > 0 ) {
			$out['state'] = 'failing';
			return $out;
		}

		if ( ! wp_next_scheduled( BONNIE_CRON_HOOK ) ) {
			$out['state']  = 'stale';
			$out['reason'] = 'unscheduled';
			return $out;
		}

		$baseline = $status['last_success'] ? $status['last_success'] : $status['first_seen'];
		if ( $baseline && $baseline < time() - self::STALE_AFTER ) {
			$out['state']  = 'stale';
			$out['reason'] = 'stalled';
		}

		return $out;
	}

	/**
	 * Per-request check (on `init`): re-create a lost cron event and fire
	 * `bonnie_retention_stale` once per stall. Reads only autoloaded options.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function check() {
		$status = $this->get();

		if ( ! $status['first_seen'] ) {
			$status['first_seen'] = time();
			$this->save( $status );
		}

		if ( ! wp_next_scheduled( BONNIE_CRON_HOOK ) ) {
			// The daily event is only scheduled on activation, so a wiped cron
			// option would stop the purge for good. Put it back.
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', BONNIE_CRON_HOOK );
		}

		if ( $status['stale_alerted'] ) {
			return;
		}

		$health = $this->health();
		if ( 'stale' !== $health['state'] ) {
			return;
		}

		$status['stale_alerted'] = time();
		$this->save( $status );

		/**
		 * Fires once when the purge stops running (no successful run for
		 * STALE_AFTER seconds, or the cron event can't be scheduled). Fires
		 * again only after a successful run ends the stall.
		 *
		 * @since 1.3.0
		 * @param array $health See Bonnie_Retention_Status::health().
		 */
		do_action( 'bonnie_retention_stale', $health );
	}

	/**
	 * A one-line, human-readable description of an unhealthy state.
	 *
	 * @since  1.3.0
	 * @param  array $health See health().
	 * @return string Empty when healthy.
	 */
	public static function describe( array $health ) {
		if ( 'failing' === $health['state'] ) {
			return sprintf(
				/* translators: %s: error message. */
				__( 'The scheduled cleanup of old Contact Form 7 submissions failed, so entries may be kept past your retention window. Error: %s', 'the-bonnie-situation' ),
				$health['error']
			);
		}

		if ( 'stale' === $health['state'] && 'unscheduled' === $health['reason'] ) {
			return __( 'The scheduled cleanup of old Contact Form 7 submissions isn\'t scheduled, so entries are being kept past your retention window.', 'the-bonnie-situation' );
		}

		if ( 'stale' === $health['state'] ) {
			return $health['last_success']
				? sprintf(
					/* translators: %s: time since the last successful cleanup, e.g. "3 days". */
					__( 'The scheduled cleanup of old Contact Form 7 submissions hasn\'t run successfully for %s, so entries may be kept past your retention window. Check that WP-Cron (or a system cron calling wp-cron.php) is running.', 'the-bonnie-situation' ),
					human_time_diff( $health['last_success'] )
				)
				: __( 'The scheduled cleanup of old Contact Form 7 submissions hasn\'t run yet, so entries may be kept past your retention window. Check that WP-Cron (or a system cron calling wp-cron.php) is running.', 'the-bonnie-situation' );
		}

		return '';
	}

	/**
	 * Site Health: register the retention test.
	 *
	 * @since 1.3.0
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function register_site_health_test( $tests ) {
		$tests['direct']['bonnie_retention'] = array(
			'label' => __( 'Contact Form 7 submission cleanup', 'the-bonnie-situation' ),
			'test'  => array( $this, 'site_health_test' ),
		);
		return $tests;
	}

	/**
	 * Site Health: the retention test result.
	 *
	 * @since 1.3.0
	 * @return array
	 */
	public function site_health_test() {
		$health = $this->health();
		$result = array(
			'label'       => __( 'Old Contact Form 7 submissions are being cleaned up on schedule', 'the-bonnie-situation' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Privacy', 'the-bonnie-situation' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Bonnie deletes stored submissions once they pass your retention window.', 'the-bonnie-situation' ) . '</p>',
			'actions'     => '',
			'test'        => 'bonnie_retention',
		);

		if ( 'disabled' === $health['state'] ) {
			$result['label']       = __( 'No retention policy is set for Contact Form 7 submissions', 'the-bonnie-situation' );
			$result['description'] = '<p>' . esc_html__( 'Bonnie keeps stored submissions indefinitely. Set a retention window so personal data isn\'t kept longer than you need it.', 'the-bonnie-situation' ) . '</p>';
		} elseif ( 'ok' !== $health['state'] ) {
			$result['label']       = 'failing' === $health['state']
				? __( 'The cleanup of old Contact Form 7 submissions is failing', 'the-bonnie-situation' )
				: __( 'The cleanup of old Contact Form 7 submissions isn\'t running', 'the-bonnie-situation' );
			$result['status']      = 'critical';
			$result['description'] = '<p>' . esc_html( self::describe( $health ) ) . '</p>';
		}

		$result['actions'] = sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=bonnie-settings' ) ),
			esc_html__( 'Bonnie settings', 'the-bonnie-situation' )
		);

		return $result;
	}

	/**
	 * Admin notice while the purge is failing or stalled, on the dashboard and
	 * Bonnie's screens. Not dismissible: it clears itself on the next good run.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function maybe_admin_notice() {
		if ( ! current_user_can( bonnie_capability() ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ids    = array( 'dashboard', 'toplevel_page_bonnie', 'bonnie_page_bonnie-settings' );
		if ( ! $screen || ! in_array( $screen->id, $ids, true ) ) {
			return;
		}

		$message = self::describe( $this->health() );
		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Bonnie:', 'the-bonnie-situation' ),
			esc_html( $message )
		);
	}
}
