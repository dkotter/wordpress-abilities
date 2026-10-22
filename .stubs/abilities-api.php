<?php
/**
 * PHPStan stubs for the WordPress Abilities API.
 *
 * The API is new in core trunk and not yet covered by
 * szepeviktor/phpstan-wordpress's stubs. This file is dev tooling only:
 * referenced from phpstan.neon.dist stubFiles, never autoloaded, never
 * shipped or listed in composer.json autoload entries.
 *
 * Signatures mirror the Abilities API as shipped in WordPress core since
 * 6.9 ( wordpress-develop trunk as of 2026-10-20: WP_Ability,
 * WP_Abilities_Registry, WP_Ability_Categories_Registry,
 * WP_Ability_Category, and the global API functions; the former test
 * fixture shim files these were once mirrored against are removed).
 * Once phpstan-wordpress ships Abilities API stubs, delete this file and its
 * stubFiles entry.
 *
 * @package WordPress\Abilities
 */

namespace {

	/**
	 * Ability object. Trunk signature summary used by this package.
	 *
	 * @since 6.9.0
	 */
	class WP_Ability {

		/**
		 * Constructor.
		 *
		 * @since 6.9.0
		 *
		 * @param string               $name The ability name, with its namespace prefix.
		 * @param array<string, mixed> $args {
		 *     Ability properties.
		 *     @type string                        $label                 Required.
		 *     @type string                        $description           Required.
		 *     @type string                        $category              Required.
		 *     @type callable                      $execute_callback      Optional for custom ability_class.
		 *     @type callable                      $permission_callback   Optional when required_capabilities set.
		 *     @type list<string>                  $required_capabilities Optional.
		 *     @type array<string, mixed>          $input_schema          Optional.
		 *     @type array<string, mixed>          $output_schema         Optional.
		 *     @type array<string, mixed>          $meta                  Optional.
		 *     @type string                        $ability_class         Optional, unset before instantiation.
		 * }
		 */
		public function __construct( string $name, array $args = array() ) {}

		/**
		 * Retrieves the ability name.
		 *
		 * @return string
		 */
		public function get_name() {}

		/**
		 * Retrieves a single meta item.
		 *
		 * @param string $key           The meta key.
		 * @param mixed  $default_value Optional default.
		 * @return mixed
		 */
		public function get_meta_item( string $key, $default_value = null ) {}
	}

	/**
	 * Ability category object.
	 *
	 * @since 6.9.0
	 */
	class WP_Ability_Category {

		/**
		 * Constructor.
		 *
		 * @param string               $slug The category slug.
		 * @param array<string, mixed> $args Category properties.
		 */
		public function __construct( string $slug, array $args = array() ) {}
	}

	/**
	 * Ability categories registry.
	 *
	 * @since 6.9.0
	 * @access private
	 */
	final class WP_Ability_Categories_Registry {

		/**
		 * Retrieves the main categories registry instance.
		 *
		 * @return WP_Ability_Categories_Registry|null
		 */
		public static function get_instance() {}
	}

	/**
	 * Abilities registry.
	 *
	 * @since 6.9.0
	 * @access private
	 */
	final class WP_Abilities_Registry {

		/**
		 * Registers an ability.
		 *
		 * @param string               $name The ability name.
		 * @param array<string, mixed> $args The ability args, see WP_Ability::__construct().
		 * @return WP_Ability|null
		 */
		public function register( string $name, array $args ) {}

		/**
		 * Checks whether an ability is registered.
		 *
		 * @param string $name The ability name.
		 * @return bool
		 */
		public function is_registered( string $name ) {}

		/**
		 * Retrieves the main registry instance.
		 *
		 * @return WP_Abilities_Registry|null
		 */
		public static function get_instance() {}
	}

	/**
	 * Registers an ability.
	 *
	 * @since 6.9.0
	 *
	 * @param string               $name The ability name, e.g. 'my-plugin/my-ability'.
	 * @param array<string, mixed> $args The ability args, see WP_Ability::__construct().
	 * @return WP_Ability|null The registered ability instance on success, null on failure.
	 */
	function wp_register_ability( string $name, array $args ) {
		return null;
	}

	/**
	 * Unregisters an ability.
	 *
	 * @since 6.9.0
	 *
	 * @param string $name The ability name.
	 * @return WP_Ability|null
	 */
	function wp_unregister_ability( string $name ) {
		return null;
	}

	/**
	 * Checks whether an ability is registered.
	 *
	 * @since 6.9.0
	 *
	 * @param string $name The ability name.
	 * @return bool
	 */
	function wp_has_ability( string $name ) {
		return false;
	}

	/**
	 * Registers an ability category.
	 *
	 * @since 6.9.0
	 *
	 * @param string               $slug The category slug.
	 * @param array<string, mixed> $args Category properties.
	 * @return WP_Ability_Category|null
	 */
	function wp_register_ability_category( string $slug, array $args ) {
		return null;
	}

	/**
	 * Checks whether an ability category is registered.
	 *
	 * @since 6.9.0
	 *
	 * @param string $slug The category slug.
	 * @return bool
	 */
	function wp_has_ability_category( string $slug ) {
		return false;
	}
}
