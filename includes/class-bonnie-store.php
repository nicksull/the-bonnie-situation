<?php

/**
 * Data-access layer for Bonnie.
 *
 * All reads and writes against the plugin's custom tables go through this class.
 * Also the single source of truth for table names, option keys and default
 * settings so the activator, capture engine and uninstaller stay in agreement.
 *
 * @link       https://beforebonnie.com
 * @since      1.0.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * Data-access layer.
 *
 * @since      1.0.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_Store {

	/*
	 * Data-access invariants PHPCS can't see, so it flags safe code as risky:
	 *
	 * - Every table name is built solely from the trusted $wpdb->prefix (see
	 *   table()) — never from request data — so interpolating it into the query
	 *   string is safe. WP's %i identifier placeholder only landed recently and
	 *   these queries predate it; the interpolation is equivalent.
	 * - Every id list interpolated into an IN (...) clause is cast through
	 *   intval() first, so the clause can only contain integers.
	 * - The data values themselves are ALWAYS bound via $wpdb->prepare().
	 * - These are our own custom tables, so the direct, uncached calls the
	 *   DirectDatabaseQuery sniff warns about are expected and correct.
	 *
	 * Suppress the resulting false positives for this class. This does NOT
	 * relax preparation of user-supplied values, which stays mandatory.
	 */
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
	// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

	/**
	 * Option key holding the settings array.
	 *
	 * @var string
	 */
	const OPTION_SETTINGS = 'bonnie_settings';

	/**
	 * Option key holding the installed schema version.
	 *
	 * @var string
	 */
	const OPTION_DB_VERSION = 'bonnie_db_version';

	/**
	 * Fully-qualified name for one of the plugin's tables.
	 *
	 * Public so add-ons can address the shared tables without re-deriving the
	 * prefix. The name is built solely from the trusted $wpdb->prefix.
	 *
	 * @since  1.0.0
	 * @param  string $name Bare table name (e.g. "submissions").
	 * @return string       Prefixed table name (e.g. "wp_bonnie_submissions").
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'bonnie_' . $name;
	}

	/**
	 * Privacy-first default settings.
	 *
	 * @since  1.0.0
	 * @return array
	 */
	public static function default_settings() {
		$defaults = array(
			'store_ip'                 => 0,   // IP suppression ON by default.
			'store_user_agent'         => 0,
			'store_referer'            => 0,
			'capture_spam'             => 0,   // Drop spam by default.
			'capture_validation'       => 0,   // Drop validation failures by default.
			'store_on_mail_failed'     => 1,   // Still record a submission if the email failed.
			'retention_policy'         => 'none', // none | delete.
			'retention_days'           => 30,
			'delete_data_on_uninstall' => 0,
		);

		/**
		 * Filter the default settings schema. Add-ons register their own keys
		 * here so `get_settings()` merges and preserves them.
		 *
		 * @since 1.0.0
		 * @param array $defaults Default setting values keyed by setting name.
		 */
		return apply_filters( 'bonnie_default_settings', $defaults );
	}

	/**
	 * Effective settings (saved values merged over defaults).
	 *
	 * @since  1.0.0
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::default_settings(), $saved );
	}

	/**
	 * Single effective setting.
	 *
	 * @since  1.0.0
	 * @param  string $key     Setting key.
	 * @param  mixed  $default Fallback if absent.
	 * @return mixed
	 */
	public static function get_setting( $key, $default = null ) {
		$settings = self::get_settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Sanitise a settings payload, merged over current values.
	 *
	 * Shared by the REST settings endpoint (and any other writer) so validation
	 * lives in one place. Core handles the free settings; add-ons sanitise and
	 * merge their own keys through the `bonnie_sanitize_settings` filter.
	 *
	 * @since  1.0.0
	 * @param  mixed $input Raw submitted values.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$out = self::get_settings();

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$bools = array(
			'store_ip',
			'store_user_agent',
			'store_referer',
			'capture_spam',
			'capture_validation',
			'store_on_mail_failed',
			'delete_data_on_uninstall',
		);
		foreach ( $bools as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$policy                  = isset( $input['retention_policy'] ) ? (string) $input['retention_policy'] : 'none';
		$out['retention_policy'] = in_array( $policy, array( 'none', 'delete' ), true ) ? $policy : 'none';

		$out['retention_days'] = max( 1, (int) ( isset( $input['retention_days'] ) ? $input['retention_days'] : 30 ) );

		/**
		 * Filter the sanitised settings payload before it is saved. Add-ons
		 * sanitise and merge their own keys (and any additional retention
		 * policies) from the raw input here.
		 *
		 * @since 1.0.0
		 * @param array $out   Sanitised settings (core keys already handled).
		 * @param mixed $input Raw submitted values.
		 */
		return apply_filters( 'bonnie_sanitize_settings', $out, $input );
	}

	/**
	 * Persist a captured submission and its meta fields.
	 *
	 * @since  1.0.0
	 * @param  array $submission Submission column values (see Bonnie_Capture::build_submission()).
	 * @param  array $meta       meta_key => meta_value pairs for the tall meta table.
	 * @return int               The new submission id.
	 *
	 * @throws RuntimeException If the submission row cannot be written.
	 */
	public function save( array $submission, array $meta ) {
		global $wpdb;

		$data = array(
			'form_id'       => (int) $submission['form_id'],
			'form_title'    => (string) $submission['form_title'],
			'status'        => (string) $submission['status'],
			'channel'       => (string) $submission['channel'],
			'subject'       => (string) $submission['subject'],
			'from_name'     => (string) $submission['from_name'],
			'from_email'    => (string) $submission['from_email'],
			'remote_ip'     => $submission['remote_ip'],   // packed binary or null.
			'user_agent'    => $submission['user_agent'],
			'referer_url'   => $submission['referer_url'],
			'consent_flags' => $submission['consent_flags'],
			'created_at'    => (string) $submission['created_at'],
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( self::table( 'submissions' ), $data, $formats );
		if ( false === $ok ) {
			throw new RuntimeException( esc_html( 'Bonnie: submission insert failed - ' . $wpdb->last_error ) );
		}

		$submission_id = (int) $wpdb->insert_id;

		$this->insert_meta( $submission_id, $meta );

		return $submission_id;
	}

	/**
	 * Insert the per-field meta rows for a submission.
	 *
	 * @since  1.0.0
	 * @param  int   $submission_id Parent submission id.
	 * @param  array $meta          meta_key => meta_value pairs.
	 * @return void
	 */
	protected function insert_meta( $submission_id, array $meta ) {
		global $wpdb;
		$table = self::table( 'submission_meta' );

		foreach ( $meta as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				array(
					'submission_id' => (int) $submission_id,
					'meta_key'      => (string) $key,
					'meta_value'    => (string) $value,
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	/* --------------------------------------------------------------------- *
	 * Read / list queries (viewer & privacy exporter).
	 * --------------------------------------------------------------------- */

	/**
	 * Distinct forms seen in the submissions table (for the filter dropdown).
	 *
	 * @since  1.0.0
	 * @return array Row objects with form_id and form_title.
	 */
	public function get_forms() {
		global $wpdb;
		$table = self::table( 'submissions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			"SELECT form_id, MAX(form_title) AS form_title FROM `{$table}` GROUP BY form_id ORDER BY form_title ASC"
		);
	}

	/**
	 * Query submissions with filters and pagination.
	 *
	 * @since  1.0.0
	 * @param  array $args Query args (status, form_id, search, date_from/to, orderby, order, per_page, paged).
	 * @return array { items: object[], total: int }
	 */
	public function query_submissions( array $args ) {
		global $wpdb;
		$table = self::table( 'submissions' );

		list( $where_sql, $params ) = $this->build_where( $args );

		$allowed = array( 'id', 'created_at', 'form_title', 'from_email', 'from_name', 'subject', 'status' );
		$orderby = ( isset( $args['orderby'] ) && in_array( $args['orderby'], $allowed, true ) ) ? $args['orderby'] : 'created_at';
		$order   = ( isset( $args['order'] ) && 'asc' === strtolower( $args['order'] ) ) ? 'ASC' : 'DESC';
		$per     = max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) );
		$paged   = max( 1, (int) ( isset( $args['paged'] ) ? $args['paged'] : 1 ) );
		$offset  = ( $paged - 1 ) * $per;

		$sql    = "SELECT s.* FROM `{$table}` s {$where_sql} ORDER BY s.{$orderby} {$order} LIMIT %d OFFSET %d";
		$params = array_merge( $params, array( $per, $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$items = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$items = array_map(
			static function ( $item ) {
				return self::read_submission( $item, 'list' );
			},
			(array) $items
		);

		return array(
			'items' => $items,
			'total' => $this->count_submissions( $args ),
		);
	}

	/**
	 * Pass a raw submission row through the read seam.
	 *
	 * Every path that surfaces a stored submission routes through here, so an
	 * add-on can transform rows on the way out — notably decrypting fields
	 * written under encryption-at-rest. A no-op in the free build.
	 *
	 * @since  1.0.0
	 * @param  object|null $row     Raw submission row, or null.
	 * @param  string      $context Where the row is headed: 'list' | 'single' | 'export'.
	 * @return object|null
	 */
	public static function read_submission( $row, $context = 'single' ) {
		if ( null === $row ) {
			return null;
		}

		/**
		 * Filter a submission row as it leaves the store.
		 *
		 * @since 1.0.0
		 * @param object $row     Submission row.
		 * @param string $context 'list' | 'single' | 'export'.
		 */
		return apply_filters( 'bonnie_read_submission', $row, $context );
	}

	/**
	 * Count submissions matching the given filters.
	 *
	 * @since  1.0.0
	 * @param  array $args Query args (see query_submissions()).
	 * @return int
	 */
	public function count_submissions( array $args ) {
		global $wpdb;
		$table = self::table( 'submissions' );

		list( $where_sql, $params ) = $this->build_where( $args );

		$sql = "SELECT COUNT(*) FROM `{$table}` s {$where_sql}";

		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( $sql );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Build the shared WHERE clause + bound params for list queries.
	 *
	 * @since  1.0.0
	 * @param  array $args Query args.
	 * @return array [ string $where_sql, array $params ]
	 */
	protected function build_where( array $args ) {
		global $wpdb;
		$meta = self::table( 'submission_meta' );

		$where  = array();
		$params = array();

		$status = isset( $args['status'] ) ? $args['status'] : '';
		if ( '' !== $status && 'all' !== $status ) {
			$where[]  = 's.status = %s';
			$params[] = $status;
		}

		if ( ! empty( $args['form_id'] ) ) {
			$where[]  = 's.form_id = %d';
			$params[] = (int) $args['form_id'];
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 's.created_at >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 's.created_at <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = "(s.from_email LIKE %s OR s.from_name LIKE %s OR s.subject LIKE %s OR s.id IN (SELECT submission_id FROM `{$meta}` WHERE meta_value LIKE %s))";
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';
		return array( $where_sql, $params );
	}

	/**
	 * Fetch a single submission row.
	 *
	 * @since  1.0.0
	 * @param  int $id Submission id.
	 * @return object|null
	 */
	public function get_submission( $id ) {
		global $wpdb;
		$table = self::table( 'submissions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $id ) );

		return self::read_submission( $row, 'single' );
	}

	/**
	 * Fetch the meta rows for a submission, in insertion order.
	 *
	 * @since  1.0.0
	 * @param  int $id Submission id.
	 * @return object[] Rows with meta_key and meta_value.
	 */
	public function get_submission_meta( $id ) {
		global $wpdb;
		$table = self::table( 'submission_meta' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_key, meta_value FROM `{$table}` WHERE submission_id = %d ORDER BY id ASC", (int) $id )
		);

		/**
		 * Filter a submission's meta rows as they leave the store — the read
		 * counterpart to `bonnie_before_store_meta`, where an add-on decrypts
		 * meta values written under encryption-at-rest. A no-op in free.
		 *
		 * @since 1.0.0
		 * @param object[] $rows Meta rows with meta_key and meta_value.
		 * @param int      $id   Submission id.
		 */
		return apply_filters( 'bonnie_read_submission_meta', $rows, (int) $id );
	}

	/**
	 * Permanently delete submissions, cascading to their meta.
	 *
	 * @since  1.0.0
	 * @param  int[] $ids Submission ids.
	 * @return int Rows deleted.
	 *
	 * @throws Throwable On query failure (rolled back first).
	 */
	public function delete_submissions( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		/**
		 * Fires before a set of submissions is permanently deleted, whether from
		 * the admin, the retention sweep, or a privacy erasure. Add-ons that
		 * derive data from submissions (e.g. an address book) snapshot the
		 * affected rows here, then reconcile on `bonnie_after_delete_submissions`.
		 *
		 * @since 1.0.0
		 * @param int[] $ids Submission ids about to be deleted.
		 */
		do_action( 'bonnie_before_delete_submissions', $ids );

		$sub  = self::table( 'submissions' );
		$meta = self::table( 'submission_meta' );
		$in   = implode( ',', $ids );

		$wpdb->query( 'START TRANSACTION' );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
			$wpdb->query( "DELETE FROM `{$meta}` WHERE submission_id IN ({$in})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
			$deleted = (int) $wpdb->query( "DELETE FROM `{$sub}` WHERE id IN ({$in})" );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		/**
		 * Fires after a set of submissions has been permanently deleted.
		 *
		 * @since 1.0.0
		 * @param int[] $ids Submission ids that were deleted.
		 */
		do_action( 'bonnie_after_delete_submissions', $ids );

		return $deleted;
	}

	/**
	 * Render a packed binary IP back to a human-readable string.
	 *
	 * @since  1.0.0
	 * @param  string|null $packed Packed binary address.
	 * @return string
	 */
	public function ip_to_string( $packed ) {
		if ( empty( $packed ) ) {
			return '';
		}
		$str = @inet_ntop( $packed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false !== $str ? $str : '';
	}

	/* --------------------------------------------------------------------- *
	 * Effective policy resolution (filterable extension seams).
	 * --------------------------------------------------------------------- */

	/**
	 * Resolve the effective retention policy for a scope.
	 *
	 * The free build resolves to the global policy; the Pro add-on layers
	 * per-form overrides and the trash + hard-cap strategy through the filter.
	 *
	 * @since  1.0.0
	 * @param  int $form_id Form id (0 for the global scope).
	 * @return array { policy, days, hardcap }
	 */
	public static function effective_retention( $form_id = 0 ) {
		$g = self::get_settings();

		$resolved = array(
			'policy'  => isset( $g['retention_policy'] ) ? $g['retention_policy'] : 'none',
			'days'    => max( 1, (int) $g['retention_days'] ),
			'hardcap' => 0, // Free is delete-only; the trash hard-cap is a Pro concept.
		);

		/**
		 * Filter the effective retention policy for a scope.
		 *
		 * @since 1.0.0
		 * @param array $resolved { policy, days, hardcap }.
		 * @param int   $form_id  Form id, or 0 for the global scope.
		 */
		return apply_filters( 'bonnie_effective_retention', $resolved, $form_id );
	}

	/**
	 * Resolve the effective capture settings for a form.
	 *
	 * The free build resolves to the global settings; the Pro add-on layers
	 * per-form overrides, field mapping and IP anonymisation through the filter.
	 *
	 * @since  1.0.0
	 * @param  int $form_id Form id.
	 * @return array
	 */
	public static function effective_capture( $form_id ) {
		$g = self::get_settings();

		$resolved = array(
			'store_ip'         => ! empty( $g['store_ip'] ),
			'anonymize_ip'     => false, // Pro layers anonymisation via bonnie_capture_remote_ip.
			'store_user_agent' => ! empty( $g['store_user_agent'] ),
			'store_referer'    => ! empty( $g['store_referer'] ),
			'map_name'         => '',
			'map_email'        => '',
			'map_subject'      => '',
		);

		/**
		 * Filter the effective capture settings for a form.
		 *
		 * @since 1.0.0
		 * @param array $resolved Effective capture settings.
		 * @param int   $form_id  Form id.
		 */
		return apply_filters( 'bonnie_effective_capture', $resolved, $form_id );
	}

	/**
	 * Submission ids for a given contact email (for privacy export/erase).
	 *
	 * @since  1.0.0
	 * @param  string $email  Contact email.
	 * @param  int    $limit  Max rows.
	 * @param  int    $offset Offset.
	 * @return int[]
	 */
	public function ids_by_email( $email, $limit, $offset ) {
		global $wpdb;
		$table = self::table( 'submissions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE from_email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
					$email,
					(int) $limit,
					(int) $offset
				)
			)
		);
	}

}
