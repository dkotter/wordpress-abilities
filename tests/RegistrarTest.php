<?php
/**
 * Tests: Registrar behavior (HANDOFF §10.3, §14 WP2).
 *
 * Tests run in declaration order inside one WP boot; registration state is
 * therefore sequenced deliberately. Only one manifest name
 * (`core/content-query`) may itself be core-provided on trunk (the package
 * copy then self-skips before the gates below it run), so the gate paths
 * that need a package-owned, non-public vehicle — the version floor and
 * `public_only` selection — drive the synthetic `test/registrar-synthetic`
 * entry through the engine's own registration funnel. Its `test/` prefix
 * can never collide with real manifest growth (`core/` or `wp/` entries).
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use ReflectionMethod;
use ReflectionProperty;
use WordPress\Abilities\Abstract_Core_Ability;
use WordPress\Abilities\Manifest;
use WordPress\Abilities\Registrar;
use WP_Abilities_Registry;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Registrar contract tests.
 */
final class RegistrarTest extends TestCase {

	/** Synthetic ability name, outside every real manifest tier prefix. */
	private const SYNTHETIC_NAME = 'test/registrar-synthetic';

	/**
	 * Recorded _doing_it_wrong() calls: function => accumulated messages.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $wrong_usage = array();

	/**
	 * Saved original value of $GLOBALS['wp_version'] while the version floor
	 * is applied (null when no floor is active).
	 *
	 * @var string|null
	 */
	private static ?string $version_floor_saved = null;

	/**
	 * Result of registering the synthetic ability inside the reopened init
	 * window (null until the window has run).
	 *
	 * @var bool|null
	 */
	private static ?bool $synthetic_window_result = null;

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
	 * tested against the real get_bloginfo( 'version' ) pipeline. Trunk's
	 * get_bloginfo() reads the $wp_version global directly for this output,
	 * so the spoof point is the global itself: the pre_option_ wp_version
	 * filter never fires for it.
	 */
	private static function apply_version_floor(): void {
		global $wp_version;
		self::$version_floor_saved = $wp_version;
		$GLOBALS['wp_version']     = '5.0';
	}

	/**
	 * Removes the version floor.
	 */
	private static function clear_version_floor(): void {
		if ( null !== self::$version_floor_saved ) {
			$GLOBALS['wp_version']     = self::$version_floor_saved;
			self::$version_floor_saved = null;
		}
	}

	/**
	 * Reopens a fresh wp_abilities_api_init registration window.
	 *
	 * The real API seals registration once the lazy action has fired:
	 * wp_register_ability() requires doing_action( 'wp_abilities_api_init' ),
	 * and the action fires exactly once per boot. Tests that must exercise a
	 * later registration flow therefore replay a pre-init boot: reset the
	 * Registrar's static bookkeeping and the registry singleton, clear the
	 * fired-action counter, then let test code queue hooks and re-fire by
	 * calling Registry::get_instance() again. Only the abilities registry is
	 * reset; the categories registry singleton persists so the restored core
	 * categories are not double-registered.
	 */
	private static function open_fresh_init_window(): void {
		$hooked     = new ReflectionProperty( Registrar::class, 'hooked' );
		$registered = new ReflectionProperty( Registrar::class, 'registered' );
		$hooked->setValue( null, false );
		$registered->setValue( null, array() );

		$instance = new ReflectionProperty( WP_Abilities_Registry::class, 'instance' );
		$instance->setValue( null, null );

		$GLOBALS['wp_actions']['wp_abilities_api_init'] = 0;
	}

	/**
	 * Builds the synthetic manifest-shaped entry.
	 *
	 * Same shape as a Manifest::all() entry: experimental tier, no required
	 * features, no lineage. It exercises the Registrar's gate chain and
	 * provenance stamping without claiming a real manifest slot.
	 *
	 * @return array<string, mixed>
	 */
	private static function synthetic_entry(): array {
		static $entry = null;
		if ( null === $entry ) {
			/*
			 * Anonymous on purpose: the sniff rules allow one object
			 * structure per file, and the vehicle never needs a file of its
			 * own. PHP registers anonymous classes in the class table under
			 * a generated name, so the engine's `new $entry['class']()` probe
			 * and registration paths run verbatim. Authors public=false, the
			 * vehicle for the public_only selection gate.
			 */
			$ability = new class() extends Abstract_Core_Ability {

				/**
				 * The full ability name, including its tier prefix. Must
				 * match SYNTHETIC_NAME; the constructor name-match notice
				 * and the suite's no-wrong-usage assertions keep the pair
				 * in sync.
				 */
				protected function ability_name(): string {
					return 'test/registrar-synthetic';
				}

				/**
				 * The human-readable ability label.
				 */
				protected function label(): string {
					return 'Registrar test synthetic';
				}

				/**
				 * A detailed description of what the ability does.
				 */
				protected function description(): string {
					return 'Synthetic non-public ability exercising the Registrar gates in the test suite. Never ships in the manifest.';
				}

				/**
				 * The ability category slug.
				 */
				protected function category_slug(): string {
					return 'content';
				}

				/**
				 * The JSON Schema definition for the ability's input.
				 */
				protected function input_schema(): array {
					return array();
				}

				/**
				 * The JSON Schema definition for the ability's output.
				 */
				protected function output_schema(): array {
					return array();
				}

				/**
				 * Builds the execute callback.
				 */
				protected function make_execute_callback(): callable {
					return static function (): array {
						return array();
					};
				}

				/**
				 * Builds the permission callback.
				 */
				protected function make_permission_callback(): callable {
					return static function (): bool {
						return true;
					};
				}

				/**
				 * The ability meta: authors public=false, the vehicle for
				 * the public_only selection gate.
				 */
				protected function ability_meta(): array {
					return array( 'public' => false );
				}

				/**
				 * The request-level capability floor: the permission
				 * callback fully owns authorization for the synthetic.
				 */
				protected function required_capabilities(): array {
					return array();
				}
			};

			$entry = array(
				'name'           => self::SYNTHETIC_NAME,
				'class'          => get_class( $ability ),
				'category'       => 'content',
				'tier'           => Manifest::TIER_EXPERIMENTAL,
				'min_wp_version' => '6.9',
				'core_since'     => null,
				'requires'       => array(),
				'renamed_from'   => null,
				'proposal'       => null,
			);
		}

		return $entry;
	}

	/**
	 * Drives the synthetic entry through the engine's own registration
	 * funnel — the same private Registrar::try_register() every public entry
	 * point calls — so the gate chain runs exactly as it would for a real
	 * manifest entry.
	 *
	 * @param array<string, mixed> $args Consumer-side selection policy.
	 * @return bool The try_register() result.
	 */
	private static function try_register_synthetic( array $args = array() ): bool {
		$method = new ReflectionMethod( Registrar::class, 'try_register' );
		$method->setAccessible( true ); // No-op on PHP 8.1+, required on 7.4.
		return (bool) $method->invoke( null, self::SYNTHETIC_NAME, self::synthetic_entry(), $args );
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

		self::assertFalse( Registrar::register( 'core/content-query' ), 'Pre-init registration defers: false.' );
		self::assertFalse( Registrar::is_registered( 'core/content-query' ), 'Nothing is registered before the action fires.' );

		// P6: a repeated pre-init call for the same name defers again without
		// queueing a second closure.
		$before = array_sum( array_map( 'count', $GLOBALS['wp_filter']['wp_abilities_api_init']->callbacks ) );
		self::assertFalse( Registrar::register( 'core/content-query' ), 'Repeat pre-init call defers: false.' );
		$after = array_sum( array_map( 'count', $GLOBALS['wp_filter']['wp_abilities_api_init']->callbacks ) );
		self::assertSame( $before, $after, 'Repeat pre-init call queues no second closure (per-name dedupe).' );

		self::apply_version_floor(); // Active for the deferred callbacks' first run.
	}

	/**
	 * 2. The lazily-fired init action runs the deferred hooks; the package
	 * support-floor gate blocks registration under an old WP version, while
	 * a core-provided name still (skip) resolves.
	 */
	public function test_init_fires_and_min_wp_version_blocks(): void {
		WP_Abilities_Registry::get_instance(); // Fires wp_abilities_api_init (lazy).
		self::assertSame( 1, did_action( 'wp_abilities_api_init' ) );

		/*
		 * Floor gate via the synthetic entry: unambiguous on both envs. The
		 * manifest name cannot carry this assertion on trunk, where core may
		 * provide core/content-query during the action, so the package copy
		 * resolves via the wp_has_ability() gate before the version floor is
		 * ever consulted for it.
		 */
		self::assertFalse( self::try_register_synthetic(), 'Support floor (5.0 < 6.9) skipped the synthetic ability.' );
		self::assertFalse( wp_has_ability( self::SYNTHETIC_NAME ), 'The floor-blocked ability never reached the registry.' );
		self::assertFalse( Registrar::is_registered( self::SYNTHETIC_NAME ) );

		self::clear_version_floor();

		// Core-provided skip: on trunk envs with show_in_abilities post
		// types, core registers core/content-query itself and the package
		// copy self-skips (wp_has_ability true). On the stable env core
		// cannot register it and the deferred copy was floor-blocked above;
		// our copy then registers (next tests).
		$core_provides = wp_has_ability( 'core/content-query' );
		if ( $core_provides ) {
			self::assertTrue( Registrar::is_registered( 'core/content-query' ), 'Core-provided name resolves as registered.' );
		} else {
			self::assertFalse( wp_has_ability( 'core/content-query' ), 'Stable env: core skipped it entirely (show_in_abilities unsupported).' );
			self::assertFalse( Registrar::is_registered( 'core/content-query' ), 'Stable env: the floor-blocked copy is not registered.' );
		}
	}

	/**
	 * 3. Post-init negative gates: public_only selection and the consumer
	 * 'filter' callable (which receives name + manifest entry). Per the
	 * HANDOFF §6.3 gate order, the core-provided skip precedes consumer
	 * selection: a filter can never veto a core-provided name, and is
	 * never even consulted for one.
	 */
	public function test_public_only_and_consumer_filter_gates(): void {
		// The synthetic ability authors public=false.
		self::assertFalse( self::try_register_synthetic( array( 'public_only' => true ) ), 'public_only skipped the non-public ability.' );
		self::assertFalse( wp_has_ability( self::SYNTHETIC_NAME ) );

		$captured = array();
		$filter   = static function ( string $name, array $entry ) use ( &$captured ): bool {
			$captured[] = array( $name, $entry );
			return false;
		};

		/*
		 * The consumer filter veto, driven through a name core never
		 * provides: only `core/content-query` can be core-provided (on
		 * trunk), so this path is deterministic on both envs — and the
		 * first init window ran under the version floor, so the name is
		 * not in-process registered here either.
		 */
		self::assertFalse( Registrar::register( 'core/content-create', array( 'filter' => $filter ) ), 'Consumer filter returning false skips.' );
		self::assertCount( 1, $captured, 'The filter receives exactly the attempted ability.' );
		self::assertSame( 'core/content-create', $captured[0][0] );
		self::assertSame( Manifest::get( 'core/content-create' ), $captured[0][1], 'The filter receives the manifest entry.' );

		/*
		 * P7 core-skip precedence: on a host where core itself provides
		 * `core/content-query` (trunk), the skip gate resolves the name
		 * before consumer selection runs — the veto filter above is never
		 * consulted for it.
		 */
		if ( wp_has_ability( 'core/content-query' ) ) {
			self::assertTrue( Registrar::register( 'core/content-query', array( 'filter' => $filter ) ), 'A consumer filter cannot veto a core-provided name.' );
			self::assertCount( 1, $captured, 'The filter is never consulted for a core-provided name.' );
		}
		self::reset_wrong_usage();

		// Reopen the init window; drive a full positive registration of the
		// synthetic through the same funnel the deferred manifest hooks use.
		self::open_fresh_init_window();
		add_action(
			'wp_abilities_api_init',
			static function (): void {
				self::$synthetic_window_result = self::try_register_synthetic();
			}
		);
		Registrar::register_all(); // Defers: did_action() reads 0 again.
		WP_Abilities_Registry::get_instance(); // Re-fires wp_abilities_api_init.
		self::assertTrue( wp_has_ability( 'core/content-query' ), 'Default policy after the filtered call resolves the name one way or another.' );
	}

	/**
	 * 4. Positive registration with real floor cleared; provenance meta is
	 * stamped with source, package_version, stability and renamed_from.
	 */
	public function test_positive_registration_and_provenance_meta(): void {
		self::assertNotNull( self::$synthetic_window_result, 'The reopened init window ran the synthetic registration.' );
		self::assertTrue( self::$synthetic_window_result, 'The synthetic ability registered inside the init window.' );
		self::assertTrue( wp_has_ability( self::SYNTHETIC_NAME ) );
		self::assertTrue( Registrar::is_registered( self::SYNTHETIC_NAME ) );

		$ability = WP_Abilities_Registry::get_instance()->get_registered( self::SYNTHETIC_NAME );
		self::assertNotNull( $ability );
		$meta = $ability->get_meta();
		self::assertSame( 'wordpress/abilities', $meta['source'] ?? null, 'Provenance: source.' );
		self::assertSame( Manifest::VERSION, $meta['package_version'] ?? null, 'Provenance: package_version.' );
		self::assertSame( Manifest::TIER_EXPERIMENTAL, $meta['stability'] ?? null, 'Provenance: stability tier.' );
		self::assertArrayNotHasKey( 'renamed_from', $meta, 'Provenance: no renamed_from key when entry value is null (synthetic).' );

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

		self::assertTrue( self::try_register_synthetic(), 'In-process idempotence: gate 1.' );
		self::assertTrue( Registrar::register( 'core/content-query' ), 'Manifest-name idempotence: gate 1 or core-provided.' );
		Registrar::register_all();

		// Simulate another consumer calling again in-process.
		$state = new ReflectionProperty( Registrar::class, 'registered' );
		$state->setValue( null, array() );
		Registrar::register_all();

		self::assert_no_wrong_usage();

		$names = array_keys( WP_Abilities_Registry::get_instance()->get_all_registered() );
		self::assertSame( $names, array_values( array_unique( $names ) ), 'No duplicate registry entries after repeat registration.' );
		self::assertContains( self::SYNTHETIC_NAME, $names );
		self::assertContains( 'core/content-query', $names );
	}

	/**
	 * 6. P7 core-skip precedence (HANDOFF §6.3 bullet 3): the
	 * core-provided skip gate precedes consumer selection, so a `filter`
	 * can never veto a name the registry already provides. Simulates the
	 * WP 7.2+ host state (core registered `core/content-query`; the
	 * package's in-process bookkeeping is empty, fresh-consumer style as
	 * in test 5): the name must resolve as available and the filter must
	 * never be consulted.
	 */
	public function test_consumer_filter_cannot_veto_core_provided_name(): void {
		self::assertTrue( wp_has_ability( 'core/content-query' ), 'Precondition: the name is registry-resident (registered earlier in this boot).' );

		// Fresh-consumer bookkeeping: registry-resident, not in-process.
		$state = new ReflectionProperty( Registrar::class, 'registered' );
		$state->setValue( null, array() );

		self::reset_wrong_usage();

		$captured = array();
		$filter   = static function ( string $name, array $entry ) use ( &$captured ): bool {
			$captured[] = array( $name, $entry );
			return false;
		};

		self::assertTrue( Registrar::register( 'core/content-query', array( 'filter' => $filter ) ), 'A consumer filter cannot veto a name the registry already provides.' );
		self::assertCount( 0, $captured, 'The filter is never consulted for a registry-resident name.' );
		self::assertTrue( Registrar::is_registered( 'core/content-query' ), 'The core-provided name resolves as available (availability, not authorship).' );

		self::assert_no_wrong_usage();
	}

	/**
	 * 7. API-absence guard: _doing_it_wrong fires for both entry points and
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
		self::assertFalse( Registrar::register( 'core/content-query' ) );
		self::assertFalse( Registrar::is_registered( 'core/content-query' ) );

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
