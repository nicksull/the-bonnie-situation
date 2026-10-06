<?php

/**
 * Fired during plugin activation
 *
 * @link       https://nicksull.dev
 * @since      1.0.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_Activator {

	/**
	 * Create tables, seed default options and schedule the purge cron.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function activate() {
		require_once plugin_dir_path( __FILE__ ) . 'class-bonnie-store.php';

		self::create_tables();
		self::seed_options();
		self::schedule_cron();
		self::add_capabilities();

		update_option( Bonnie_Store::OPTION_DB_VERSION, BONNIE_DB_VERSION );
	}

	/**
	 * Upgrade the schema to BONNIE_DB_VERSION.
	 *
	 * 1.1.0 widens the personal-data columns to TEXT (so an add-on can store
	 * them encrypted, which makes them longer) and adds email_hash for
	 * exact-email lookups on encrypted rows. See create_tables().
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public static function upgrade() {
		require_once plugin_dir_path( __FILE__ ) . 'class-bonnie-store.php';

		self::create_tables();

		update_option( Bonnie_Store::OPTION_DB_VERSION, BONNIE_DB_VERSION );
	}

	/**
	 * Drop the 1.0.0 full-length from_email index, which blocks converting the
	 * column to TEXT. dbDelta re-adds it as a prefix index.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	private static function drop_legacy_email_index() {
		global $wpdb;
		$table = Bonnie_Store::table( 'submissions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$index = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", 'from_email' ) );
		if ( $index && null === $index->Sub_part ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX from_email" );
		}
	}

	/**
	 * Grant the Bonnie capability to administrators.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function add_capabilities() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( BONNIE_CAPABILITY ) ) {
			$role->add_cap( BONNIE_CAPABILITY );
		}
	}

	/**
	 * Create the custom tables via dbDelta().
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset     = $wpdb->get_charset_collate();
		$submissions = Bonnie_Store::table( 'submissions' );
		$meta        = Bonnie_Store::table( 'submission_meta' );
		$log         = Bonnie_Store::table( 'retention_log' );

		self::drop_legacy_email_index();

		$statements = array();

		// The status column and nullable trashed_at leave room for the Pro trash
		// workflow to operate on this shared table without altering it; free core
		// only ever writes the 'active' and 'spam' statuses. Personal-data columns
		// are TEXT so an add-on can store them encrypted; email_hash is a keyed
		// hash an add-on fills for exact-email lookups on encrypted rows.
		$statements[] = "CREATE TABLE $submissions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			form_title varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			channel varchar(20) NOT NULL DEFAULT 'inbound',
			subject text NOT NULL,
			from_name text NOT NULL,
			from_email text NOT NULL,
			email_hash char(64) NOT NULL DEFAULT '',
			remote_ip varbinary(16) DEFAULT NULL,
			user_agent text,
			referer_url text,
			consent_flags text,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			trashed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY status (status),
			KEY from_email (from_email(191)),
			KEY email_hash (email_hash),
			KEY created_at (created_at),
			KEY trashed_at (trashed_at)
		) $charset;";

		$statements[] = "CREATE TABLE $meta (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submission_id bigint(20) unsigned NOT NULL,
			meta_key varchar(255) NOT NULL DEFAULT '',
			meta_value longtext,
			PRIMARY KEY  (id),
			KEY submission_id (submission_id),
			KEY meta_key (meta_key(191))
		) $charset;";

		$statements[] = "CREATE TABLE $log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			action varchar(16) NOT NULL DEFAULT '',
			scope varchar(64) NOT NULL DEFAULT '',
			affected_rows int unsigned NOT NULL DEFAULT 0,
			cutoff datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY run_at (run_at)
		) $charset;";

		foreach ( $statements as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Seed default settings on first activation only.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function seed_options() {
		if ( false === get_option( Bonnie_Store::OPTION_SETTINGS, false ) ) {
			add_option( Bonnie_Store::OPTION_SETTINGS, Bonnie_Store::default_settings() );
		}
	}

	/**
	 * Schedule the daily retention purge if not already scheduled.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function schedule_cron() {
		if ( ! wp_next_scheduled( BONNIE_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', BONNIE_CRON_HOOK );
		}
	}

}
