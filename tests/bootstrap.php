<?php
/**
 * PHPUnit bootstrap: loads the WordPress test library and this plugin.
 *
 * Run inside wp-env (see CLAUDE.md): `npm run test:php`.
 *
 * @package Bonnie
 */

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$bonnie_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/wordpress-phpunit';

if ( ! file_exists( $bonnie_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test library in {$bonnie_tests_dir}. Run the tests through wp-env.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once $bonnie_tests_dir . '/includes/functions.php';

// Core only: Bonnie Pro is deliberately not loaded, so these tests cover the
// free build's behaviour.
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/the-bonnie-situation.php';
	}
);

// Create the plugin's tables once (real tables, outside the per-test
// transaction); test cases empty them in set_up().
tests_add_filter(
	'init',
	static function () {
		require_once dirname( __DIR__ ) . '/includes/class-bonnie-activator.php';
		Bonnie_Activator::activate();
	},
	0
);

require $bonnie_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/class-bonnie-testcase.php';
