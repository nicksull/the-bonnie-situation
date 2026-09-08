<?php

/**
 * Retention engine — the scheduled delete-only purge.
 *
 * Resolves the global retention policy and enforces it by permanently deleting
 * aged submissions in batches (cascading to meta), writing an audit row for
 * every destructive sweep. The free core is delete-only; add-ons layer the
 * trash workflow, hard-cap and per-form overrides on `bonnie_retention_after_run`.
 *
 * @link       https://beforebonnie.com
 * @since      1.0.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * Retention engine.
 *
 * @since      1.0.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_Retention {

	/*
	 * The purge queries below address the plugin's own submissions table by a
	 * name built solely from the trusted $wpdb->prefix, and interpolate a scope
	 * clause assembled only from intval()-cast form ids. Every data value
	 * (cutoffs, batch sizes) is bound via $wpdb->prepare(). PHPCS can't see these
	 * invariants, so suppress the identifier / direct-query false positives here.
	 */
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
	// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

	/**
	 * Rows processed per query iteration.
	 *
	 * @var int
	 */
	const BATCH = 500;

	/**
	 * Maximum iterations per cron run before yielding (reschedules a follow-up).
	 *
	 * @var int
	 */
	const MAX_BATCHES_PER_RUN = 20;

	/**
	 * Data-access layer.
	 *
	 * @var Bonnie_Store
	 */
	protected $store;

	/**
	 * Logger.
	 *
	 * @var Bonnie_Logger
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Bonnie_Store  $store  Data-access layer.
	 * @param Bonnie_Logger $logger Logger.
	 */
	public function __construct( Bonnie_Store $store, Bonnie_Logger $logger ) {
		$this->store  = $store;
		$this->logger = $logger;
	}

	/**
	 * Run a purge cycle: enforce the global delete policy, then let add-ons run.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function run() {
		try {
			$now_ts = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			$now    = gmdate( 'Y-m-d H:i:s', $now_ts );
			$more   = false;

			/**
			 * Filter the set of form ids that override the global retention policy.
			 *
			 * Per-form overrides are a Pro capability; in the free build no forms
			 * override, so the global sweep applies everywhere. The add-on supplies
			 * this list and enforces those forms on `bonnie_retention_after_run`.
			 *
			 * @since 1.0.0
			 * @param int[] $ids Overriding form ids.
			 */
			$overrides = apply_filters( 'bonnie_overriding_form_ids', array() );

			$global = Bonnie_Store::effective_retention( 0 );

			if ( 'delete' === $global['policy'] ) {
				$cutoff = $this->cutoff( $now_ts, $global['days'], 'global' );
				$more   = $this->delete_before( $cutoff, $now, $this->exclude_clause( $overrides ), 'global' ) || $more;
			}

			/**
			 * Fires after the free core's delete-only sweep. Add-ons enforce the
			 * trash workflow, the hard-cap sweep and per-form overrides here.
			 *
			 * @since 1.0.0
			 * @param int    $now_ts Current timestamp (site time).
			 * @param string $now    Current MySQL datetime.
			 */
			do_action( 'bonnie_retention_after_run', $now_ts, $now );

			if ( $more ) {
				$this->reschedule();
			}
		} catch ( \Throwable $e ) {
			$this->logger->error( 'purge_failed', $e );
		}
	}

	/**
	 * SQL fragment excluding overriding forms from the global scope.
	 *
	 * @since 1.0.0
	 * @param int[] $ids Overriding form ids.
	 * @return string
	 */
	protected function exclude_clause( array $ids ) {
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( empty( $ids ) ) {
			return '';
		}
		return ' AND form_id NOT IN (' . implode( ',', $ids ) . ')';
	}

	/**
	 * Compute the age cutoff for a scope, exposed via filter.
	 *
	 * @since 1.0.0
	 * @param int    $now_ts Current timestamp (site time).
	 * @param int    $days   Retention window in days.
	 * @param string $scope  "global" or "form:{id}".
	 * @return string MySQL datetime cutoff.
	 */
	protected function cutoff( $now_ts, $days, $scope ) {
		$cutoff = gmdate( 'Y-m-d H:i:s', $now_ts - $days * DAY_IN_SECONDS );

		/**
		 * Filter the retention age threshold.
		 *
		 * @since 1.0.0
		 * @param string $cutoff MySQL datetime; rows older than this are acted on.
		 * @param string $scope  "global" or "form:{id}".
		 */
		return apply_filters( 'bonnie_purge_cutoff', $cutoff, $scope );
	}

	/**
	 * Permanently delete submissions older than the cutoff (any status).
	 *
	 * Deletes route through Bonnie_Store::delete_submissions() so the
	 * before/after delete actions fire and add-ons can keep derived data in sync.
	 *
	 * @since 1.0.0
	 * @param string $cutoff MySQL datetime threshold.
	 * @param string $now    MySQL datetime stamp for the log.
	 * @param string $clause Scope SQL fragment (form filter), already escaped.
	 * @param string $label  Audit scope label.
	 * @return bool True if work likely remains (batch ceiling reached).
	 */
	protected function delete_before( $cutoff, $now, $clause, $label ) {
		global $wpdb;
		$table = Bonnie_Store::table( 'submissions' );

		$total   = 0;
		$batches = 0;
		$last    = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE created_at < %s {$clause} ORDER BY id ASC LIMIT %d",
					$cutoff,
					self::BATCH
				)
			);

			$last = count( $ids );
			if ( 0 === $last ) {
				break;
			}

			$this->store->delete_submissions( array_map( 'intval', $ids ) );

			$total += $last;
			++$batches;
		} while ( self::BATCH === $last && $batches < self::MAX_BATCHES_PER_RUN );

		if ( $total > 0 ) {
			$this->log( 'deleted', $label, $total, $cutoff, $now );
		}

		return self::BATCH === $last;
	}

	/**
	 * Write an audit row for a destructive action.
	 *
	 * @since 1.0.0
	 * @param string $action One of "trashed" or "deleted".
	 * @param string $scope  Audit scope label.
	 * @param int    $rows   Affected row count.
	 * @param string $cutoff Threshold applied.
	 * @param string $now    Run timestamp.
	 * @return void
	 */
	public function log( $action, $scope, $rows, $cutoff, $now ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Bonnie_Store::table( 'retention_log' ),
			array(
				'run_at'        => $now,
				'action'        => $action,
				'scope'         => $scope,
				'affected_rows' => (int) $rows,
				'cutoff'        => $cutoff,
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Schedule a near-term follow-up run when a cycle yields with work left.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function reschedule() {
		// WordPress de-duplicates identical events within a 10-minute window,
		// so repeated calls are safe.
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, BONNIE_CRON_HOOK );
	}

}
