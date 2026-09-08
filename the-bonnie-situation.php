<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://beforebonnie.com
 * @since             1.0.0
 * @package           Bonnie
 *
 * @wordpress-plugin
 * Plugin Name:       The Bonnie Situation
 * Plugin URI:        https://beforebonnie.com
 * Description:       Store Contact Form 7 submissions, then auto-delete them on a schedule - capture and browse without hoarding PII. Clean it up before Bonnie gets home.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Requires Plugins:  contact-form-7
 * Author:            Red Pocket
 * Author URI:        https://redpocket.hk
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       the-bonnie-situation
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'BONNIE_VERSION', '1.0.0' );

/**
 * Schema version (bump when the table structure changes) and shared paths.
 */
define( 'BONNIE_DB_VERSION', '1.0.0' );
define( 'BONNIE_PLUGIN_FILE', __FILE__ );
define( 'BONNIE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BONNIE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BONNIE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Cron hook fired daily to enforce the retention policy. Get the mess cleaned
 * up before Bonnie returns.
 */
define( 'BONNIE_CRON_HOOK', 'cleanup_before_bonnie_returns' );

/**
 * Capability gating access to stored submissions and settings. Granted to
 * administrators on activation; assign it to other roles to delegate access.
 */
define( 'BONNIE_CAPABILITY', 'manage_bonnie' );

/**
 * The capability required for Bonnie's admin screens, filterable so site owners
 * can remap it to a different role/capability.
 *
 * @since 1.0.0
 * @return string
 */
function bonnie_capability() {
	return apply_filters( 'bonnie_capability', BONNIE_CAPABILITY );
}

/**
 * The URL the "learn more about Bonnie Pro" link points at.
 *
 * Bonnie Pro is a separate add-on distributed off wordpress.org. This plugin is
 * fully functional on its own; the link is informational only. Filterable so the
 * add-on (or a site) can repoint or remove it.
 *
 * @since  1.0.0
 * @return string
 */
function bonnie_upgrade_url() {
	return apply_filters( 'bonnie_upgrade_url', 'https://beforebonnie.com/pro/' );
}

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-bonnie-activator.php
 */
function bonnie_activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-bonnie-activator.php';
	Bonnie_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-bonnie-deactivator.php
 */
function bonnie_deactivate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-bonnie-deactivator.php';
	Bonnie_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'bonnie_activate' );
register_deactivation_hook( __FILE__, 'bonnie_deactivate' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-bonnie.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function bonnie_run() {

	$plugin = new Bonnie();
	$plugin->run();

	return $plugin;

}

/**
 * Accessor for the running plugin's service registry.
 *
 * Add-ons can either hook the `bonnie_loaded` action (preferred, guarantees
 * ordering) or call this to reach core services on demand.
 *
 * @since  1.1.0
 * @return Bonnie_Services|null The registry, or null before the plugin has booted.
 */
function bonnie() {
	return isset( $GLOBALS['bonnie'] ) ? $GLOBALS['bonnie']->get_services() : null;
}

$GLOBALS['bonnie'] = bonnie_run();
