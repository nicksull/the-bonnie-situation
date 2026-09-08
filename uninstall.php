<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * When populating this file, consider the following flow
 * of control:
 *
 * - This method should be static
 * - Check if the $_REQUEST content actually is the plugin name
 * - Run an admin referrer check to make sure it goes through authentication
 * - Verify the output of $_GET makes sense
 * - Repeat with other user roles. Best directly by using the links/query string parameters.
 * - Repeat things for multisite. Once for a single site in the network, once sitewide.
 *
 * This file may be updated more in future version of the Boilerplate; however, this is the
 * general skeleton and outline for how the file should work.
 *
 * For more information, see the following discussion:
 * https://github.com/tommcfarlin/WordPress-Plugin-Boilerplate/pull/123#issuecomment-28541913
 *
 * @link       https://nicksull.dev
 * @since      1.0.0
 *
 * @package    Bonnie
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-bonnie-store.php';

// Remove the custom capability from all roles (not PII; always cleaned up).
$bonnie_role = get_role( 'administrator' );
if ( $bonnie_role && $bonnie_role->has_cap( 'manage_bonnie' ) ) {
	$bonnie_role->remove_cap( 'manage_bonnie' );
}

$bonnie_settings = get_option( Bonnie_Store::OPTION_SETTINGS, array() );

// Preserve all data unless the operator has explicitly opted in to deletion.
if ( empty( $bonnie_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Only the free core's own tables. The Pro add-on owns (and cleans up) its
// contacts table and per-form settings on its own uninstall.
$bonnie_tables = array(
	Bonnie_Store::table( 'submissions' ),
	Bonnie_Store::table( 'submission_meta' ),
	Bonnie_Store::table( 'retention_log' ),
);

foreach ( $bonnie_tables as $bonnie_table ) {
	// Table name is built from the trusted internal prefix, not user input.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$wpdb->query( "DROP TABLE IF EXISTS `{$bonnie_table}`" );
}

delete_option( Bonnie_Store::OPTION_SETTINGS );
delete_option( Bonnie_Store::OPTION_DB_VERSION );
