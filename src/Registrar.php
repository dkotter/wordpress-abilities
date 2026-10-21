<?php
/**
 * Canonical WordPress abilities: opt-in ability registration.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities;

use WordPress\Abilities\Support\Features;

/**
 * Registers the package's manifest abilities with the Abilities API.
 *
 * The package never ships the Abilities API and never bootstraps itself.
 * A consumer decides whether and when to register, on its own gating and
 * timing. The Registrar is idempotent, timing-aware, version-gated, and
 * stamps provenance meta; every gate skips silently on failure. It is safe
 * for multiple consumers, or a stale bundle copy, to call it again: another
 * consumer's autoloader may have loaded a newer copy than the caller's, so
 * nothing here assumes exact-package-version equality.
 */
final class Registrar {

	/**
	 * Whether a deferred registration hook was already queued.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Abilities this process has registered, keyed by name.
	 *
	 * @var array<string, true>
	 */
	private static $registered = array();

	/**
	 * Static-only class.
	 */
	private function __construct() {}

	/**
	 * Registers all eligible manifest abilities.
	 *
	 * Safe to call before or after the `wp_abilities_api_init` action: when
	 * the action has fired, registration happens immediately; otherwise it
	 * is deferred to the action, guarded by a static flag so repeated calls
	 * queue it only once. Double-consumption by two host plugins is safe.
	 *
	 * @param array<string, mixed> $args {
	 *     Optional. Consumer-side selection policy.
	 *
	 *     @type bool           $public_only Optional. Register only abilities
	 *                                        whose `public` meta is true, for
	 *                                        MCP-tool style exposure. Default false.
	 *     @type callable|null  $filter      Optional. Selection policy as
	 *                                        `fn( string $name, array $entry ): bool`;
	 *                                        return false to skip an ability.
	 * }
	 * @return void
	 */
	public static function register_all( array $args = array() ): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: package name. */
					__( 'The Abilities API was not found when the %s package attempted to register abilities. The API is provided by WordPress 6.9 or later, or must be loaded by the consuming plugin before registration. The package never ships the API itself.' ),
					'wordpress/abilities'
				),
				'6.9.0'
			);
			return;
		}

		if ( did_action( 'wp_abilities_api_init' ) ) {
			self::register_eligible( $args );
			return;
		}

		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;

		add_action(
			'wp_abilities_api_init',
			static function () use ( $args ): void {
				self::register_eligible( $args );
			}
		);
	}

	/**
	 * Registers a single manifest ability by name.
	 *
	 * Also timing-aware: when the API action has not fired yet, the
	 * registration is deferred to it and false is returned for the current
	 * call. The return value describes availability, not authorship: true
	 * when the ability is in the registry afterwards, including when core
	 * already provides the same name and the package copy self-skips.
	 *
	 * @param string               $name The manifest ability name.
	 * @param array<string, mixed> $args The same selection policy as register_all(). Optional.
	 * @return bool Whether the ability is available in the registry afterwards,
	 *              or will be when the deferred registration runs.
	 */
	public static function register( string $name, array $args = array() ): bool {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: package name. */
					__( 'The Abilities API was not found when the %s package attempted to register abilities. The API is provided by WordPress 6.9 or later, or must be loaded by the consuming plugin before registration. The package never ships the API itself.' ),
					'wordpress/abilities'
				),
				'6.9.0'
			);
			return false;
		}

		$entry = Manifest::get( $name );
		if ( null === $entry ) {
			return false;
		}

		if ( did_action( 'wp_abilities_api_init' ) ) {
			return self::try_register( $name, $entry, $args );
		}

		add_action(
			'wp_abilities_api_init',
			static function () use ( $name, $entry, $args ): void {
				self::try_register( $name, $entry, $args );
			}
		);

		return false;
	}

	/**
	 * Reports whether a manifest ability is registered.
	 *
	 * True when this process registered the ability, or when the ability
	 * already exists in the core registry under the same name (core itself
	 * provides the backported trio on 7.2+). Before the API action fires,
	 * only in-process registrations count.
	 *
	 * @param string $name The manifest ability name.
	 * @return bool
	 */
	public static function is_registered( string $name ): bool {
		if ( isset( self::$registered[ $name ] ) ) {
			return true;
		}

		return did_action( 'wp_abilities_api_init' )
			&& function_exists( 'wp_has_ability' )
			&& wp_has_ability( $name );
	}

	/**
	 * Registers every manifest ability that passes the selection policy.
	 *
	 * @param array<string, mixed> $args Consumer-side selection policy, see register_all().
	 * @return void
	 */
	private static function register_eligible( array $args ): void {
		foreach ( Manifest::all() as $name => $entry ) {
			self::try_register( $name, $entry, $args );
		}
	}

	/**
	 * Gates and registers one ability.
	 *
	 * The gates run in order and skip silently on failure. Only the missing
	 * API guard logs via _doing_it_wrong(); everything else is a non-event.
	 *
	 * 1. Registered in this process already: idempotence.
	 * 2. The ability class must be loadable: a stale bundle copy missing a
	 *    class that the manifest knows simply registers nothing.
	 * 3. Consumer `filter` selection policy.
	 * 4. `wp_has_ability()`: core already provides the name (the core_since
	 *    mechanism; on 7.2+ the backported trio self-skips).
	 * 5. WordPress version vs. the entry's min_wp_version support floor.
	 * 6. `public_only`: probe the ability's own `public` meta.
	 * 7. Support-surface `requires`: every feature must be available,
	 *    attempting a polyfill load when one exists.
	 * 8. Registration via the core `ability_class` mechanism, merging
	 *    provenance meta (stability tier, renamed_from lineage).
	 *
	 * @param string               $name  The ability name.
	 * @param array<string, mixed> $entry The manifest entry.
	 * @param array<string, mixed> $args  Consumer-side selection policy.
	 * @return bool Whether the ability is available in the registry afterwards.
	 */
	private static function try_register( string $name, array $entry, array $args ): bool {
		// Gate 1: registered in this process already.
		if ( isset( self::$registered[ $name ] ) ) {
			return true;
		}

		// Gate 2: the class must be loadable (version-skew awareness).
		if ( ! class_exists( $entry['class'] ) ) {
			return false;
		}

		// Gate 3: consumer selection policy.
		$filter = isset( $args['filter'] ) && is_callable( $args['filter'] ) ? $args['filter'] : null;
		if ( null !== $filter && ! (bool) $filter( $name, $entry ) ) {
			return false;
		}

		// Gate 4: core already provides this ability under the same name.
		if ( wp_has_ability( $name ) ) {
			return true;
		}

		// Gate 5: package support floor.
		if ( version_compare( get_bloginfo( 'version' ), $entry['min_wp_version'], '<' ) ) {
			return false;
		}

		// Gate 6: public-only selection probes the ability's own meta.
		if ( ! empty( $args['public_only'] ) ) {
			$probe = new $entry['class']();
			if ( true !== $probe->get_meta_item( 'public', false ) ) {
				return false;
			}
		}

		// Gate 7: support-surface features, with polyfill attempt.
		foreach ( (array) $entry['requires'] as $feature ) {
			if ( ! Features::is_available( $feature ) && ! Features::load_polyfill( $feature ) ) {
				return false;
			}
		}

		// Gate 8: register via the core `ability_class` mechanism, merging
		// provenance meta (stability, lineage).
		$meta = array(
			'stability' => $entry['tier'],
		);
		if ( null !== $entry['renamed_from'] ) {
			$meta['renamed_from'] = $entry['renamed_from'];
		}

		$ability = wp_register_ability(
			$name,
			array(
				'ability_class' => $entry['class'],
				'category'      => $entry['category'],
				'meta'          => $meta,
			)
		);

		if ( null === $ability ) {
			// Core rejected the registration and already logged why; stay silent.
			return false;
		}

		self::$registered[ $name ] = true;

		return true;
	}
}
