<?php
/**
 * Test fixture mu-plugin — stable-environment Abilities API shim.
 *
 * The latest stable WordPress does not ship the Abilities API (added in
 * WP 7.2). When the test environment is the stable env, this mu-plugin
 * provides the API by loading the trunk snapshot in
 * tests/Fixtures/abilities-api/, then hooks the canonical registration
 * callbacks the same way core's default-filters.php does (§10.2).
 *
 * On the trunk environment the API is baked into core, so the guard below
 * makes this a no-op there. Manually refreshed together with the fixture
 * classes; NOT package code: never shipped, never autoloaded.
 * Excluded from WPCS/PHPStan; see phpcs.xml.dist and phpstan.neon.dist.
 *
 * @package WordPress\Abilities
 */

if ( function_exists( 'wp_register_ability' ) ) {
	return; // API already present (trunk core, or a consumer plugin): no-op.
}

$abilities_fixture_dir = dirname( __DIR__ ) . '/abilities-api/';

require_once $abilities_fixture_dir . 'class-wp-ability-category.php';
require_once $abilities_fixture_dir . 'class-wp-ability-categories-registry.php';
require_once $abilities_fixture_dir . 'class-wp-ability.php';
require_once $abilities_fixture_dir . 'class-wp-abilities-registry.php';
require_once $abilities_fixture_dir . 'abilities-api.php';
require_once $abilities_fixture_dir . 'abilities.php'; // Canonical registration functions; requires abilities/*.php itself.

// Mirror core default-filters.php (src/wp-includes/default-filters.php).
add_action( 'wp_abilities_api_categories_init', 'wp_register_core_ability_categories' );
add_action( 'wp_abilities_api_init', '_wp_register_initial_settings_for_abilities', 1 );
add_action( 'wp_abilities_api_init', 'wp_register_core_abilities' );