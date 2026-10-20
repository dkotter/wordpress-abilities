<?php
/**
 * TEST FIXTURE — runtime test doubles for the two manifest ability classes
 * that have not landed yet (WP3: Content_Query, WP4: Post_Create).
 *
 * These are concrete stand-ins under the exact manifest FQCNs, extending
 * Abstract_Core_Ability (which extends the Abilities API's WP_Ability), so
 * the Registrar positive path and the §8.3 manifest rules — including the
 * "extends Abstract_Core_Ability" assertion — are genuinely exercisable
 * before the real classes exist. They implement no real behavior.
 *
 * Self-removing: tests/bootstrap.php requires this file only when
 * class_exists( 'WordPress\Abilities\Content\Content_Query' ) is false, and
 * that call autoloads the real class once it exists at the PSR-4 path
 * (src/Content/Content_Query.php). NOTE: WP3/WP4 must therefore land the
 * real classes at src/Content/*.php for the removal to be automatic.
 *
 * Loaded only when the Abilities API's WP_Ability class exists (after the WP
 * test suite bootstraps); when the API itself is absent (API-off guard runs)
 * the .stubs placeholder shim loads instead.
 * Excluded from WPCS/PHPStan; see phpcs.xml.dist and phpstan.neon.dist.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Content;

use WordPress\Abilities\Abstract_Core_Ability;

/**
 * Test double for the `core/content-query` backport class (lands in WP3).
 */
final class Content_Query extends Abstract_Core_Ability {

	/**
	 * The full ability name, including its tier prefix.
	 *
	 * @return string
	 */
	protected function ability_name(): string {
		return 'core/content-query';
	}

	/**
	 * The human-readable ability label.
	 *
	 * @return string
	 */
	protected function label(): string {
		return 'Content Query (test double)';
	}

	/**
	 * A detailed description of what the ability does and when to use it.
	 *
	 * @return string
	 */
	protected function description(): string {
		return 'Test double for the core/content-query ability; no real behavior.';
	}

	/**
	 * The ability category slug.
	 *
	 * @return string
	 */
	protected function category_slug(): string {
		return 'content';
	}

	/**
	 * The JSON Schema definition for the ability's input.
	 *
	 * @return array<string, mixed>
	 */
	protected function input_schema(): array {
		return array();
	}

	/**
	 * The JSON Schema definition for the ability's output.
	 *
	 * @return array<string, mixed>
	 */
	protected function output_schema(): array {
		return array();
	}

	/**
	 * The execute callback.
	 *
	 * @return callable(mixed): (mixed|\WP_Error)
	 */
	protected function make_execute_callback(): callable {
		return static fn ( mixed $input ): bool => true;
	}

	/**
	 * The permission callback.
	 *
	 * @return callable(mixed): (bool|\WP_Error)
	 */
	protected function make_permission_callback(): callable {
		return static fn ( mixed $input ): bool => true;
	}

	/**
	 * The ability meta authored by this ability.
	 *
	 * @return array<string, mixed>
	 */
	protected function ability_meta(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
			'public'      => true,
		);
	}

	/**
	 * The capabilities the current user must have to run the ability.
	 *
	 * @return list<string>
	 */
	protected function required_capabilities(): array {
		return array();
	}
}

/**
 * Test double for the `wp/post-create` experimental class (lands in WP4).
 *
 * Deliberately NOT public: keeps the Public-only selection gate testable
 * before the real class lands.
 */
final class Post_Create extends Abstract_Core_Ability {

	/**
	 * The full ability name, including its tier prefix.
	 *
	 * @return string
	 */
	protected function ability_name(): string {
		return 'wp/post-create';
	}

	/**
	 * The human-readable ability label.
	 *
	 * @return string
	 */
	protected function label(): string {
		return 'Post Create (test double)';
	}

	/**
	 * A detailed description of what the ability does and when to use it.
	 *
	 * @return string
	 */
	protected function description(): string {
		return 'Test double for the wp/post-create ability; no real behavior.';
	}

	/**
	 * The ability category slug.
	 *
	 * @return string
	 */
	protected function category_slug(): string {
		return 'content';
	}

	/**
	 * The JSON Schema definition for the ability's input.
	 *
	 * @return array<string, mixed>
	 */
	protected function input_schema(): array {
		return array();
	}

	/**
	 * The JSON Schema definition for the ability's output.
	 *
	 * @return array<string, mixed>
	 */
	protected function output_schema(): array {
		return array();
	}

	/**
	 * The execute callback.
	 *
	 * @return callable(mixed): (mixed|\WP_Error)
	 */
	protected function make_execute_callback(): callable {
		return static fn ( mixed $input ): array => array( 'created' => false );
	}

	/**
	 * The permission callback.
	 *
	 * @return callable(mixed): (bool|\WP_Error)
	 */
	protected function make_permission_callback(): callable {
		return static fn ( mixed $input ): bool => true;
	}

	/**
	 * The ability meta authored by this ability.
	 *
	 * @return array<string, mixed>
	 */
	protected function ability_meta(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
			'public'      => false,
		);
	}

	/**
	 * The capabilities the current user must have to run the ability.
	 *
	 * @return list<string>
	 */
	protected function required_capabilities(): array {
		return array();
	}
}