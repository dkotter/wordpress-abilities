<?php
/**
 * PHPUnit bootstrap for the wordpress/abilities package test suite.
 *
 * Locates the WordPress core PHPUnit test suite (wp-env provides WP_TESTS_DIR
 * in the tests-cli container; wp-phpunit provides WP_PHPUNIT__TESTS_DIR on
 * the host), loads the package Composer autoloader and the PHPUnit
 * polyfills, registers the fixture Abilities API via muplugins_loaded, then
 * boots core.
 *
 * The fixture API is self-guarding: tests/Fixtures/mu-plugins/
 * load-abilities-api.php no-ops when a real Abilities API is already
 * provided (trunk core or a consumer plugin). Environments with the
 * mu-plugins mapping load it as a real mu-plugin; the hook below is a
 * backstop for runs without the mapping (e.g. host wp-phpunit).
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

$package_root = dirname( __DIR__ );

require $package_root . '/vendor/autoload.php';
require_once $package_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir || ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	$_tests_dir = getenv( 'WP_PHPUNIT__TESTS_DIR' );
}
if ( ! $_tests_dir || ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Abilities tests: could not locate the WordPress core PHPUnit test suite. Expected WP_TESTS_DIR (wp-env tests-cli) or WP_PHPUNIT__TESTS_DIR (wp-phpunit).\n" );
	exit( 1 );
}

$_fixture_api_mode = getenv( 'WP_ABILITIES_TESTS_API' );
if ( false === $_fixture_api_mode || ! in_array( $_fixture_api_mode, array( 'auto', 'on', 'off' ), true ) ) {
	$_fixture_api_mode = 'auto';
}

require_once $_tests_dir . '/includes/functions.php';

/*
 * Stable envs (or host runs) get the Abilities API from the fixture
 * snapshot. Controls:
 *   auto (default): load when the real API is absent.
 *   on:             always attempt (guarded no-op when present).
 *   off:            never — used by the API-absence guard test in CI
 *                   (`WP_ABILITIES_TESTS_API=off vendor/bin/phpunit --filter=missing_api_guard`).
 */
if ( 'off' !== $_fixture_api_mode ) {
	// @phpstan-ignore function.notFound (core test-suite helper, defined by the WP PHPUnit include above)
	tests_add_filter(
		'muplugins_loaded',
		static function (): void {
			// Guarded no-op when the API already exists (trunk core / consumer).
			require_once __DIR__ . '/Fixtures/mu-plugins/load-abilities-api.php';
		}
	);
}

require $_tests_dir . '/includes/bootstrap.php';
