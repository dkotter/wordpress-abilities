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

	/*
	 * The WP core PHPUnit test suite deliberately unhooks the core ability and
	 * category registrars (wp-phpunit includes/functions.php hooks
	 * _unhook_core_abilities_registration and _unhook_core_abilities_categories_
	 * registration at priority 1 on wp_abilities_api_init /
	 * wp_abilities_api_categories_init), so every test run boots with an EMPTY
	 * registry. The registry rejects any ability whose category is not
	 * registered, so nothing could ever register in tests. Re-add core's own
	 * category registrar via a closure at priority 2: the closure has a
	 * different hook id than the plain-function id the unhooker removes, and
	 * wp_register_ability_category() is only valid while
	 * doing_action( 'wp_abilities_api_categories_init' ), which this callback
	 * runs within. Core abilities stay unhooked on purpose: the package copies
	 * register instead, and provenance meta tells them apart (see
	 * ContentQueryTest).
	 */
	// @phpstan-ignore function.notFound (core test-suite helper)
	tests_add_filter(
		'wp_abilities_api_categories_init',
		static function (): void {
			if ( function_exists( 'wp_register_core_ability_categories' ) ) {
				wp_register_core_ability_categories();
			}
		},
		2
	);
}

require $_tests_dir . '/includes/bootstrap.php';
