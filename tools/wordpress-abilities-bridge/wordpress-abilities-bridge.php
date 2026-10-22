<?php

/**
 * Plugin Name:       WordPress Abilities Bridge
 * Description:       Test bridge for the wordpress/abilities package: autoloads the package from a local checkout, provides the Abilities API when this WordPress lacks one, and registers every manifest ability on wp_abilities_api_init. Local testing only.
 * Version:           0.1.0
 * Requires at least:  6.9
 * Requires PHP:      7.4
 * Author:            WordPress Core AI Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Wiring summary (follows the package README, "Consuming this package"):
 *
 * 1. Package path, in priority order:
 *      add_filter( 'wp_abilities_bridge_package_path', fn() => '/path/to/wordpress-abilities' );
 *      define( 'WP_ABILITIES_PACKAGE_PATH', '/path/to/wordpress-abilities' ); // e.g. wp-config.php
 *      Default: resolved from this plugin's own location when it lives
 *      inside a checkout of the package repo (e.g. symlinked from
 *      tools/wordpress-abilities-bridge/).
 *
 * 2. Autoload: a tiny PSR-4 autoloader maps WordPress\Abilities\ to the
 *    package's src/. Skipped when another copy of the package is already
 *    loaded (the README's "only one copy loads" rule).
 *
 * 3. Abilities API: when this WordPress does not provide one
 *    (wp_register_ability() missing), the bridge loads the package's
 *    trunk-snapshot test fixture (tests/Fixtures/abilities-api/) as the API,
 *    with the same wiring as the package's own test mu-plugin. Disable:
 *      add_filter( 'wp_abilities_bridge_provide_api', '__return_false' );
 *
 * 4. Registration: Registrar::register_all() on wp_abilities_api_init at
 *    priority 20 — after core's own ability registrations at priority 10 —
 *    so abilities core already provides self-skip. The registry is also
 *    initialized eagerly on init so registration happens deterministically,
 *    regardless of who first touches the registry.
 *
 * 5. Status: an admin notice (manage_options) shows the resolved package
 *    path, the API provider, and which manifest abilities registered.
 *
 * This file is test scaffolding that lives in the package repo at
 * tools/wordpress-abilities-bridge/. Copy or symlink this folder into the
 * test site's wp-content/plugins/ and activate.
 *
 * @package WordPress\Abilities\Bridge
 */

if (! defined('ABSPATH')) {
	exit;
}

$GLOBALS['wp_abilities_bridge'] = array(
	'package_path' => '',
	'package_src'  => false,
	'autoload'     => '',
	'api_provider' => '',
	'error'        => '',
);

$wp_abilities_bridge = &$GLOBALS['wp_abilities_bridge'];

/*
 * 1. Locate the package checkout: filter, then constant, then the default.
 */
$bridge_package_path = '';
if (has_filter('wp_abilities_bridge_package_path')) {
	$bridge_package_path = (string) apply_filters('wp_abilities_bridge_package_path', '');
}
if ('' === $bridge_package_path && defined('WP_ABILITIES_PACKAGE_PATH')) {
	$bridge_package_path = (string) WP_ABILITIES_PACKAGE_PATH;
}
if ('' === $bridge_package_path) {
	// Resolve relative to this plugin when it lives inside a checkout of
	// the package repo, e.g. symlinked from tools/wordpress-abilities-bridge/.
	// __DIR__ follows symlinks, so symlinked installs resolve too.
	$bridge_candidate = dirname(__DIR__, 2);
	if (is_file($bridge_candidate . '/src/Registrar.php')) {
		$bridge_package_path = $bridge_candidate;
	}
}
$bridge_package_path                  = untrailingslashit($bridge_package_path);
$wp_abilities_bridge['package_path']  = $bridge_package_path;
$wp_abilities_bridge['package_src']   = is_dir($bridge_package_path . '/src');

/*
 * 2. Autoload WordPress\Abilities\ from the package's src/ (PSR-4), unless
 *    another copy of the package is already loaded.
 */
if (class_exists('WordPress\Abilities\Registrar')) {
	$wp_abilities_bridge['autoload'] = 'another copy already loaded; reusing it';
} elseif ('' === $bridge_package_path) {
	$wp_abilities_bridge['error'] = 'Package checkout not found — set the WP_ABILITIES_PACKAGE_PATH constant or the wp_abilities_bridge_package_path filter.';
} elseif (! $wp_abilities_bridge['package_src']) {
	$wp_abilities_bridge['error'] = 'Package src/ not found at ' . $bridge_package_path
		. ' — set the WP_ABILITIES_PACKAGE_PATH constant or the wp_abilities_bridge_package_path filter.';
} else {
	$bridge_src = $bridge_package_path . '/src';
	spl_autoload_register(
		static function ($class_name) use ($bridge_src) {
			$prefix = 'WordPress\\Abilities\\';
			if (0 !== strpos($class_name, $prefix)) {
				return;
			}
			$relative = substr($class_name, strlen($prefix));
			$file     = $bridge_src . '/' . str_replace('\\', '/', $relative) . '.php';
			if (is_file($file)) {
				require $file;
			}
		}
	);
	$wp_abilities_bridge['autoload'] = 'PSR-4 autoloader -> ' . $bridge_src;
}

/*
 * 3. Provide the Abilities API when this WordPress lacks one, using the
 *    package's trunk-snapshot test fixture (same require order and hook
 *    wiring as tests/Fixtures/mu-plugins/load-abilities-api.php). Core or
 *    another plugin that loaded the API first wins.
 */
if (function_exists('wp_register_ability')) {
	$wp_abilities_bridge['api_provider'] = 'WordPress core (or a plugin that loaded the API first)';
} elseif (! apply_filters('wp_abilities_bridge_provide_api', true)) {
	$wp_abilities_bridge['api_provider'] = 'missing (wp_abilities_bridge_provide_api is false)';
} else {
	$bridge_fixture_dir = $bridge_package_path . '/tests/Fixtures/abilities-api/';
	if (! is_file($bridge_fixture_dir . 'abilities-api.php')) {
		$wp_abilities_bridge['api_provider'] = 'missing (no test fixture at ' . $bridge_fixture_dir
			. ') — point the bridge at the full package checkout, or provide the API another way.';
	} else {
		require_once $bridge_fixture_dir . 'class-wp-ability-category.php';
		require_once $bridge_fixture_dir . 'class-wp-ability-categories-registry.php';
		require_once $bridge_fixture_dir . 'class-wp-ability.php';
		require_once $bridge_fixture_dir . 'class-wp-abilities-registry.php';
		require_once $bridge_fixture_dir . 'abilities-api.php';
		require_once $bridge_fixture_dir . 'abilities.php'; // Also loads the core ability classes and the shared category constant.

		// Mirror core default-filters.php (see the package's test mu-plugin).
		add_action('wp_abilities_api_categories_init', 'wp_register_core_ability_categories');
		add_action('wp_abilities_api_init', '_wp_register_initial_settings_for_abilities', 1);
		add_action('wp_abilities_api_init', 'wp_register_core_abilities');

		$wp_abilities_bridge['api_provider'] = 'package test fixture (trunk snapshot)';
	}
}

/**
 * Registers every manifest ability the Registrar lets through.
 *
 * Priority 20: after core's own ability registrations at priority 10, so
 * abilities core already provides self-skip via the Registrar's
 * wp_has_ability() gate.
 */
function wp_abilities_bridge_register_abilities()
{
	if (! class_exists('WordPress\Abilities\Registrar')) {
		return;
	}
	WordPress\Abilities\Registrar::register_all();
}
add_action('wp_abilities_api_init', 'wp_abilities_bridge_register_abilities', 20);

/**
 * Initializes the abilities registry eagerly on init, so registration runs
 * deterministically even when nothing else touches the registry first.
 */
function wp_abilities_bridge_init_registry()
{
	if (function_exists('wp_has_ability')) {
		// Forces the lazily-initialized registry to boot, which fires
		// wp_abilities_api_init and runs all registrations — including ours
		// at priority 20. Function-based, so it works no matter which
		// WordPress (or fixture) provides the API, regardless of internal
		// class names.
		wp_has_ability('core/content-query');
	}
}
add_action('init', 'wp_abilities_bridge_init_registry', 5);

/**
 * Category backfill for host API revisions that predate core 7.2's default
 * category set: 6.9-7.1 ship only `site` and `user`, so the `content`
 * category is missing and every package ability's registration would be
 * rejected ("category not registered"). Mirrors the test harness backfill
 * in tests/bootstrap.php; the guard makes 7.2+ hosts (or the active
 * fixture) no-ops. Priority 20: after core's own category registration.
 */
function wp_abilities_bridge_backfill_categories()
{
	$package_path = $GLOBALS['wp_abilities_bridge']['package_path'];
	if (! defined('WP_ABILITIES_TEST_CONTENT_CATEGORY') && is_file($package_path . '/tests/Fixtures/content-category.php')) {
		require_once $package_path . '/tests/Fixtures/content-category.php';
	}
	if (
		defined('WP_ABILITIES_TEST_CONTENT_CATEGORY')
		&& function_exists('wp_register_ability_category')
		&& function_exists('wp_has_ability_category')
		&& ! wp_has_ability_category(WP_ABILITIES_TEST_CONTENT_CATEGORY['slug'])
	) {
		wp_register_ability_category(
			WP_ABILITIES_TEST_CONTENT_CATEGORY['slug'],
			array(
				'label'       => WP_ABILITIES_TEST_CONTENT_CATEGORY['label'],
				'description' => WP_ABILITIES_TEST_CONTENT_CATEGORY['description'],
			)
		);
	}
}
add_action('wp_abilities_api_categories_init', 'wp_abilities_bridge_backfill_categories', 20);

/**
 * Admin status notice: what the bridge loaded and what ended up registered.
 */
function wp_abilities_bridge_admin_notice()
{
	if (! current_user_can('manage_options')) {
		return;
	}

	$bridge = $GLOBALS['wp_abilities_bridge'];

	echo '<div class="notice notice-info"><p>';
	echo '<strong>WordPress Abilities Bridge:</strong> ';
	echo 'API: ' . esc_html($bridge['api_provider']) . ' | ';
	echo 'Autoload: ' . esc_html($bridge['autoload']);
	if ('' !== $bridge['error']) {
		echo '</p><p style="color:#b32d2e;">' . esc_html($bridge['error']) . '</p>';
	}
	echo '</p>';

	if (class_exists('WordPress\Abilities\Manifest')) {
		if (! function_exists('wp_has_ability')) {
			echo '<p style="margin-top:4px;">No Abilities API on this site — nothing can register.</p>';
		} else {
			echo '<p style="margin-top:4px;">';
			foreach (array_keys(WordPress\Abilities\Manifest::all()) as $bridge_name) {
				// wp_has_ability() itself boots the lazily-initialized registry,
				// so the first call here forces wp_abilities_api_init to fire
				// and registration to complete before the lookup runs.
				$bridge_registered = wp_has_ability($bridge_name);
				echo esc_html($bridge_name) . ': ';
				echo $bridge_registered ? '<strong style="color:#00a32a;">registered</strong>' : '<span style="color:#b32d2e;">not registered</span>';
				echo ' &nbsp; ';
			}
			echo ' | WP ' . esc_html(get_bloginfo('version'));
			echo ' | api_init fired: ' . (did_action('wp_abilities_api_init') ? 'yes' : 'no');
			echo '</p>';
		}
	}

	echo '</div>';
}
add_action('admin_notices', 'wp_abilities_bridge_admin_notice');
