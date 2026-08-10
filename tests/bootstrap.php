<?php
/**
 * PHPUnit bootstrap for the Zestry WP Toolkit integration test suite.
 *
 * Loads the WordPress core PHPUnit test suite (which provides WP_UnitTestCase, a
 * real database, hook dispatch, and factories), registers this toolkit's own
 * autoloader, and loads the toolkit as a mu-plugin so its classes are available.
 *
 * The WordPress test suite is provided by wp-env (mounted at /wordpress-phpunit)
 * or by a local install-wp-tests.sh run (WP_TESTS_DIR env var).
 *
 * The demo entry file (plugin.php) is deliberately NOT loaded here: it is the
 * local development harness (it wires the .local-demo modules and dies in
 * wp_footer). Tests build their own Plugin instances; the Composer autoloader
 * alone makes every Zestry\WPToolkit\ class available.
 */

declare( strict_types=1 );

// Locate the WordPress test suite.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir && defined( 'WP_TESTS_DIR' ) ) {
	$_tests_dir = WP_TESTS_DIR;
}

// wp-env mounts the core test suite here by default.
if ( ! $_tests_dir && is_dir( '/wordpress-phpunit' ) ) {
	$_tests_dir = '/wordpress-phpunit';
}

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$_functions = $_tests_dir . '/includes/functions.php';

if ( ! file_exists( $_functions ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test suite at {$_tests_dir}.\n" .
		"Run `npm run wp-env start` (wp-env), or set WP_TESTS_DIR to a local install.\n"
	);
	exit( 1 );
}

// Composer autoloader: the toolkit (Zestry\WPToolkit\) and the test helpers (Zestry\WPToolkit\Tests\).
require dirname( __DIR__ ) . '/vendor/autoload.php';

// WP-CLI doubles (WP_CLI_Command / WP_CLI) so the CLI module is loadable and its
// output is assertable without the WP-CLI phar. Guarded; a real runtime wins.
require __DIR__ . '/Support/wp-cli-stubs.php';

// Give access to tests_add_filter() before WordPress is loaded.
require $_functions;

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
