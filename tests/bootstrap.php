<?php
/**
 * PHPUnit bootstrap for the wordpress/abilities package test suite.
 *
 * Locates the WordPress core PHPUnit test suite (wp-env provides WP_TESTS_DIR
 * in the tests-cli container; wp-phpunit provides WP_PHPUNIT__TESTS_DIR on
 * the host), loads the package Composer autoloader and the PHPUnit polyfills,
 * installs the tests' premise guard, then boots core.
 *
 * The Abilities API ships in WordPress core since 6.9, so every supported
 * WordPress provides the API in core and the tests assume it. The guard
 * below hard-fails any environment where the API could not be loaded by the
 * time mu-plugins have run.
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

require_once $_tests_dir . '/includes/functions.php';

/*
 * Minimal premise guard: the tests assume the core-provided Abilities API
 * (WordPress 6.9+). The check runs on muplugins_loaded — not at bootstrap top
 * level — because core's API functions do not exist until wp-settings has
 * loaded wp-includes; mu-plugins load after that, so absence here means the
 * environment violates the support floor.
 */
// @phpstan-ignore function.notFound (core test-suite helper, defined by the WP PHPUnit include above)
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			fwrite( STDERR, "Abilities tests require the core-provided Abilities API (WordPress 6.9+).\n" );
			exit( 1 );
		}
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
 *
 * P8: this callback no longer carries any package-side category backfill.
 * The Registrar owns the manifest-declared categories — it queues its own
 * declaring callback on this action at priority 20 when register_all()/
 * register() is called (see Registrar::queue_category_declarations()).
 * Pre-7.2 hosts ship only `site` and `user`, so a run whose Registrar
 * declaring hook is not queued sees a genuinely missing `content`
 * category — the exact consumer scenario P8 fixes in the Registrar.
 */
// @phpstan-ignore function.notFound (core test-suite helper)
tests_add_filter(
	'wp_abilities_api_categories_init',
	static function (): void {
		// Host category registrar: wp-phpunit unhooks it, so re-add it here.
		if ( function_exists( 'wp_register_core_ability_categories' ) ) {
			wp_register_core_ability_categories();
		}
	},
	2
);

require $_tests_dir . '/includes/bootstrap.php';
