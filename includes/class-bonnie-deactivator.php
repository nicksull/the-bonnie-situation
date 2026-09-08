<?php

/**
 * Fired during plugin deactivation
 *
 * @link       https://nicksull.dev
 * @since      1.0.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_Deactivator {

	/**
	 * Unschedule the purge cron. Data is intentionally left intact here;
	 * destructive cleanup only happens on uninstall, and only if opted in.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( BONNIE_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, BONNIE_CRON_HOOK );
		}
		wp_clear_scheduled_hook( BONNIE_CRON_HOOK );
	}

}
