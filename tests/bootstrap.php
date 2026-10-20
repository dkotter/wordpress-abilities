<?php
/**
 * PHPUnit bootstrap for the wordpress/abilities package test suite.
 *
 * wp-phpunit/yoast-phpunit-polyfills pattern: locate the WordPress core
 * PHPUnit test suite (wp-env provides WP_TESTS_DIR in the tests-cli
 * container; wp-phpunit provides WP_PHPUNIT__TESTS_DIR on the host), load
 * the package Composer autoloader, register the stable-env API fixture via
 * muplugins_loaded, boot the suite, then load the pending-class stand-ins.
 *
 * Stand-in loading is CRITICALLY self-removing: the concrete fixture
 * doubles (tests/Fixtures/pending-class-doubles.php) load only when the
 * real class does not exist — and the class_exists() call autoloads the
 * real class once it exists at the PSR-4 path (src/Content/*.php, WP3/WP4).
 * The .stubs/pending-package-classes.php placeholder then loads ONLY if the
 * class is STILL undefined (its remaining purpose: PHPStan bootstrapFiles,
 * plus a backstop when the API itself is absent and WP_Ability is missing).
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
			require_once dirname( __DIR__ ) . '/Fixtures/mu-plugins/load-abilities-api.php';
		}
	);
}

require $_tests_dir . '/includes/bootstrap.php';

// Self-removing stand-ins (see file headers); must run after WP boot so
// WP_Ability exists when the doubles' parent chain resolves.
if ( ! class_exists( 'WordPress\Abilities\Content\Content_Query' ) ) {
	if ( class_exists( 'WP_Ability' ) ) {
		require __DIR__ . '/Fixtures/pending-class-doubles.php';
	}
}
// Task-specified shim: ONLY load when the class is still undefined.
if ( ! class_exists( 'WordPress\Abilities\Content\Content_Query' ) ) {
	require $package_root . '/.stubs/pending-package-classes.php';
}
