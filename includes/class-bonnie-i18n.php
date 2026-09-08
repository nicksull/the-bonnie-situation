<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://nicksull.dev
 * @since      1.0.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Nick Sullivan <studio@redpocket.hk>
 */
class Bonnie_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * Intentionally a no-op. Since WordPress 4.6, translations for plugins
	 * hosted on WordPress.org are loaded automatically under the plugin slug —
	 * calling load_plugin_textdomain() manually is discouraged. The method (and
	 * its hook) are kept as a seam should manual loading ever be needed again.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {
		// No-op: WordPress auto-loads translations for the 'the-bonnie-situation' slug.
	}



}
