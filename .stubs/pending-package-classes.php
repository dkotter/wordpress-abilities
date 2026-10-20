<?php
/**
 * TEMPORARY PHPStan stubs for package ability classes that land in WP3/WP4.
 *
 * Manifest.php references them via ::class constants, which is legal PHP
 * without the classes existing, but PHPStan cannot autoload them yet.
 * Dev tooling only: never autoloaded, never shipped (D2/D3).
 *
 * Loaded by PHPStan via the `bootstrapFiles` entry in phpstan.neon.dist
 * (dev tooling: never composer-autoloaded, never shipped — D2/D3).
 *
 * REMOVE both classes below and the bootstrapFiles entry for this file in
 * phpstan.neon.dist when the real classes land (WP3/WP4).
 *
 * @package WordPress\Abilities
 */

namespace WordPress\Abilities\Content;

/**
 * Stub for the `core/content-query` backport class (lands in WP3).
 */
abstract class Content_Query {
}

/**
 * Stub for the `wp/post-create` experimental class (lands in WP4).
 */
abstract class Post_Create {
}
