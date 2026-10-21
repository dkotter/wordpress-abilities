<?php
/**
 * TEST FIXTURE WIRING — single source of truth for the core `content` ability
 * category across the test harness.
 *
 * Two runtime registration sites must describe the `content` category
 * identically: the fixture/trunk mirror (tests/Fixtures/abilities-api/
 * abilities.php, wp_register_core_ability_categories()) and the PHPUnit
 * bootstrap's category backfill for pre-7.2 hosts (tests/bootstrap.php).
 * Both reference this constant instead of carrying verbatim copies.
 *
 * The strings mirror trunk's src/wp-includes/abilities.php verbatim; keep
 * this file in sync whenever the fixture mirror is refreshed (see the
 * refresh contract in the tests/Fixtures/abilities-api/abilities.php header).
 *
 * NOT package code: never shipped, never autoloaded. Excluded from
 * WPCS/PHPStan by the tests/Fixtures/ exclusions; phpstan.neon.dist loads
 * this file through bootstrapFiles so constant references resolve.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

const WP_ABILITIES_TEST_CONTENT_CATEGORY = array(
	'slug'        => 'content',
	'label'       => 'Content',
	'description' => 'Abilities that retrieve or manage posts and other content.',
);