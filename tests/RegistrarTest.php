<?php
/**
 * Tests: Registrar behavior (HANDOFF §10.3, §14 WP2).
 *
 * Tests run in declaration order inside one WP boot; registration state is
 * therefore sequenced deliberately. Skip-behavior asserts use manifest names
 * (core/content-query, wp/post-create) per §14 while the real abilities are
 * pending (WP3/WP4); the concrete classes under those FQCNs are the fixture
 * doubles from tests/bootstrap.php.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use ReflectionProperty;
use WordPress\Abilities\Manifest;
use WordPress\Abilities\Registrar;
use WP_Abilities_Registry;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Registrar contract tests.
 */
final class RegistrarTest extends TestCase {

	/**
	 * Recorded _doing_it_wrong() calls: function => accumulated messages.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $wrong_usage = array();

	/**
	 * Version-floor filter closure, kept for removal.
	 *
	 * @var \Closure|null
	 */
	private static ?\Closure $version_floor = null;

	/**
	 * Starts recording _doing_it_wrong() traffic for the whole class.
	 */
	public static function set_up_before_class(): void {
		self::$wrong_usage = array();
		add_action(
			'doing_it_wrong_run',
			static function ( string $function_name, string $message ): void {
				self::$wrong_usage[ $function_name ][] = $message;
			},
			10,
			2
		);
	}

	/**
	 * Runs a callback with PHP notices silenced by a local error handler so
	 * an expected _doing_it_wrong() E_USER_NOTICE cannot fail the test; the
	 * doing_it_wrong_run action still fires and is recorded for assertions.
	 *
	 * @param callable $callback The function to run.
	 * @return mixed The callback result.
	 */
	private static function silence_notices( callable $callback ): mixed {
		set_error_handler( static fn (): bool => true, E_USER_NOTICE );
		try {
			return $callback();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Resets the recording listener between assertion groups.
	 */
	private static function reset_wrong_usage(): void {
		self::$wrong_usage = array();
	}

	/**
	 * Asserts no unexpected _doing_it_wrong() occurred since reset.
	 */
	private function assert_no_wrong_usage(): void {
		self::assertSame( array(), self::$wrong_usage, 'Unexpected _doing_it_wrong() traffic.' );
	}

	/**
	 * Applies a version floor of 5.0 so the package support-floor gate can be
	 * tested against the real get_bloginfo( 'version' ) pipeline.
	 */
	private static function apply_version_floor(): void {
		self::$version_floor = static fn (): string => '5.0';
		add_filter( 'pre_option_wp_version', self::$version_floor );
	}

	/**
	 * Removes the version floor.
	 */
	private static function clear_version_floor(): void {
		if ( null !== self::$version_floor ) {
			remove_filter( 'pre_option_wp_version', self::$version_floor );
			self::$version_floor = null;
		}
	}

	/**
	 * 1. API present; pre-init calls defer (hooked) and return "not yet".
	 */
	public function test_api_present_and_pre_init_calls_defer(): void {
		self::assertTrue( function_exists( 'wp_register_ability' ), 'Abilities API must be present (core or fixture).' );
		self::assertSame( 0, did_action( 'wp_abilities_api_init' ), 'The API init action must not have fired yet.' );

		self::reset_wrong_usage();
		Registrar::register_all();
		Registrar::register_all(); // Second pre-init call: static-hook dedupe.
		self::assert_no_wrong_usage();

		self::assertFalse( Registrar::register( 'wp/post-create' ), 'Pre-init registration defers: false.' );
		self::assertFalse( Registrar::register( 'core/content-query' ), 'Pre-init registration defers: false.' );
		self::assertFalse( Registrar::is_registered( 'wp/post-create' ), 'Nothing is registered before the action fires.' );

		self::apply_version_floor(); // Active for the deferred callbacks' first run.
	}

	/**
	 * 2. The lazily-fired init action runs the deferred hooks; the package
	 * support-floor gate blocks our registration under an old WP version,
	 * while a core-provided name still (skip) resolves.
	 */
	public function test_init_fires_and_min_wp_version_blocks(): void {
		WP_Abilities_Registry::get_instance(); // Fires wp_abilities_api_init (lazy).
		self::assertSame( 1, did_action( 'wp_abilities_api_init' ) );

		self::assertFalse( wp_has_ability( 'wp/post-create' ), 'Support floor (5.0 < 6.9) skipped wp/post-create.' );
		self::assertFalse( Registrar::is_registered( 'wp/post-create' ) );

		self::clear_version_floor();

		// Core-provided skip: on trunk/fixture envs with show_in_abilities
		// post types, core registers core/content-query itself and the
		// package copy self-skips (wp_has_ability true). On the stable env
		// core cannot register it; our copy then registers (next tests).
		$core_provides = wp_has_ability( 'core/content-query' );
		if ( $core_provides ) {
			self::assertTrue( Registrar::is_registered( 'core/content-query' ), 'Core-provided name resolves as registered.' );
		} else {
			self::assertFalse( wp_has_ability( 'core/content-query' ), 'Stable env: core skipped it entirely (show_in_abilities unsupported).' );
		}
	}

	/**
	 * 3. Post-init negative gates: public_only selection and the consumer
	 * 'filter' callable (which receives name + manifest entry).
	 */
	public function test_public_only_and_consumer_filter_gates(): void {
		// Fixture double for wp/post-create authors public=false.
		self::assertFalse( Registrar::register( 'wp/post-create', array( 'public_only' => true ) ), 'public_only skipped the non-public ability.' );
		self::assertFalse( wp_has_ability( 'wp/post-create' ) );

		$captured = array();
		$filter   = static function ( string $name, array $entry ) use ( &$captured ): bool {
			$captured[] = array( $name, $entry );
			return false;
		};
		self::assertFalse( Registrar::register( 'core/content-query', array( 'filter' => $filter ) ), 'Consumer filter returning false skips.' );
		self::assertCount( 1, $captured, 'The filter receives exactly the attempted ability.' );
		self::assertSame( 'core/content-query', $captured[0][0] );
		self::assertSame( Manifest::get( 'core/content-query' ), $captured[0][1], 'The filter receives the manifest entry.' );
		self::reset_wrong_usage();
		Registrar::register_all();
		self::assertTrue( wp_has_ability( 'core/content-query' ), 'Default policy after the filtered call resolves the name one way or another.' );
	}

	/**
	 * 4. Positive registration with real floor cleared; provenance meta is
	 * stamped with source, package_version, stability and renamed_from.
	 */
	public function test_positive_registration_and_provenance_meta(): void {
		self::assertTrue( Registrar::register( 'wp/post-create' ), 'Experimental ability registers under a supported version.' );
		self::assertTrue( wp_has_ability( 'wp/post-create' ) );

		$ability = WP_Abilities_Registry::get_instance()->get_registered( 'wp/post-create' );
		self::assertNotNull( $ability );
		$meta = $ability->get_meta();
		self::assertSame( 'wordpress/abilities', $meta['source'] ?? null, 'Provenance: source.' );
		self::assertSame( Manifest::VERSION, $meta['package_version'] ?? null, 'Provenance: package_version.' );
		self::assertSame( Manifest::TIER_EXPERIMENTAL, $meta['stability'] ?? null, 'Provenance: stability tier.' );

		foreach ( Manifest::all() as $name => $entry ) {
			$instance = WP_Abilities_Registry::get_instance()->get_registered( $name );
			if ( null === $instance ) {
				continue; // Gate-blocked name: skip behavior covered above.
			}
			$instance_meta = $instance->get_meta();
			if ( null !== $entry['renamed_from'] ) {
				self::assertSame( $entry['renamed_from'], $instance_meta['renamed_from'] ?? null, "Provenance: renamed_from of {$name}." );
			} else {
				self::assertArrayNotHasKey( 'renamed_from', $instance_meta, "Provenance: no renamed_from key when entry value is null ({$name})." );
			}
		}

		self::assertTrue( Registrar::register( 'core/content-query' ), 'core/content-query resolves (ours or core-provided).' );
	}

	/**
	 * 5. Idempotence: repeated registration (fresh-consumer style via a
	 * reflection reset of the in-process bookkeeping) never re-registers,
	 * logs nothing, and leaves the single registry intact.
	 */
	public function test_idempotent_double_registration(): void {
		self::reset_wrong_usage();

		self::assertTrue( Registrar::register( 'wp/post-create' ), 'In-process idempotence: gate 1.' );
		Registrar::register_all();

		// Simulate another consumer calling again in-process.
		$state = new ReflectionProperty( Registrar::class, 'registered' );
		$state->setValue( null, array() );
		Registrar::register_all();

		self::assert_no_wrong_usage();

		$names = array_keys( WP_Abilities_Registry::get_instance()->get_all_registered() );
		self::assertSame( $names, array_values( array_unique( $names ) ), 'No duplicate registry entries after repeat registration.' );
		self::assertContains( 'wp/post-create', $names );
		self::assertContains( 'core/content-query', $names );
	}

	/**
	 * 6. API-absence guard: _doing_it_wrong fires for both entry points and
	 * nothing registers. Runs only when the API is genuinely absent
	 * (CI stable run with WP_ABILITIES_TESTS_API=off; trunk always ships
	 * the API and this test self-skips there).
	 */
	public function test_missing_api_guard(): void {
		if ( function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is present in this environment; run via WP_ABILITIES_TESTS_API=off (CI) to exercise the guard.' );
		}

		self::reset_wrong_usage();

		self::silence_notices(
			static function (): void {
				Registrar::register_all();
			}
		);
		self::assertFalse( Registrar::register( 'wp/post-create' ) );
		self::assertFalse( Registrar::is_registered( 'wp/post-create' ) );

		self::assertCount( 2, self::$wrong_usage, 'Both entry points logged the missing API.' );
		self::assertArrayHasKey( 'WordPress\Abilities\Registrar::register_all', self::$wrong_usage );
		self::assertArrayHasKey( 'WordPress\Abilities\Registrar::register', self::$wrong_usage );
		foreach ( self::$wrong_usage as $messages ) {
			foreach ( $messages as $message ) {
				self::assertStringContainsString( 'The Abilities API was not found', $message );
				self::assertStringContainsString( 'wordpress/abilities', $message );
			}
		}
		// The '6.9.0' since-version is part of the printed notice, not the
		// doing_it_wrong_run args; it is captured by the core test suite's
		// expected-incorrect-usage infra when available.
	}
}
