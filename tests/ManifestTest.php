<?php
/**
 * Tests: manifest rules (HANDOFF §8.3) and version-sync.
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

namespace WordPress\Abilities\Tests;

use WordPress\Abilities\Abstract_Core_Ability;
use WordPress\Abilities\Manifest;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Asserts the exact rule list from HANDOFF.md §8.3.
 */
final class ManifestTest extends TestCase {

	/**
	 * Names are unique and match the full manifest keys.
	 */
	public function test_names_unique_and_match_manifest(): void {
		$names = Manifest::names();
		self::assertTrue( count( $names ) > 0, 'The manifest must not be empty.' );
		self::assertSame( array_keys( Manifest::all() ), $names );
		self::assertSame( $names, array_values( array_unique( $names ) ), 'Ability names must be unique.' );
	}

	/**
	 * Experimental tier ⇒ wp/ prefix, and neither core/ nor wordpress/.
	 */
	public function test_experimental_names_use_wp_prefix(): void {
		foreach ( Manifest::all() as $name => $entry ) {
			if ( Manifest::TIER_EXPERIMENTAL !== $entry['tier'] ) {
				continue;
			}
			self::assertTrue( str_starts_with( $name, 'wp/' ), sprintf( 'Experimental %s must start with wp/.', $name ) );
			self::assertFalse( str_starts_with( $name, 'core/' ) || str_starts_with( $name, 'wordpress/' ), sprintf( 'Experimental %s must not start with core/ or wordpress/.', $name ) );
		}
	}

	/**
	 * Stable tier ⇒ core/ or wordpress/ prefix; core/ implies proposal set.
	 */
	public function test_stable_names_and_core_proposal_rules(): void {
		foreach ( Manifest::all() as $name => $entry ) {
			if ( Manifest::TIER_STABLE === $entry['tier'] || Manifest::TIER_CORE === $entry['tier'] ) {
				self::assertTrue(
					str_starts_with( $name, 'core/' ) || str_starts_with( $name, 'wordpress/' ),
					sprintf( '%s (tier %s) must start with core/ or wordpress/.', $name, $entry['tier'] )
				);
			}
			if ( str_starts_with( $name, 'core/' ) && Manifest::TIER_CORE === $entry['tier'] ) {
				self::assertNotNull( $entry['core_since'], sprintf( 'Core %s must set core_since.', $name ) );
				self::assertNotNull( $entry['proposal'], sprintf( 'Core %s must set proposal.', $name ) );
			}
			if ( str_starts_with( $name, 'core/' ) && Manifest::TIER_STABLE === $entry['tier'] ) {
				self::assertNotNull( $entry['proposal'], sprintf( 'Stable core/ %s must set proposal.', $name ) );
			}
			self::assertTrue( ! empty( $entry['min_wp_version'] ), sprintf( '%s must set min_wp_version.', $name ) );
		}
	}

	/**
	 * renamed_from collides with no live name.
	 */
	public function test_renamed_from_collides_with_no_live_name(): void {
		$live = Manifest::names();
		foreach ( Manifest::all() as $name => $entry ) {
			if ( null === $entry['renamed_from'] ) {
				continue;
			}
			self::assertNotContains( $entry['renamed_from'], $live, sprintf( '%s: renamed_from must not collide with a live name.', $name ) );
			self::assertNotSame( $name, $entry['renamed_from'] );
		}
	}

	/**
	 * Every manifest class exists and extends Abstract_Core_Ability.
	 *
	 * Until WP3/WP4 land the real classes, tests/bootstrap.php provides
	 * concrete fixture doubles under the exact FQCNs, so both assertions are
	 * genuinely enforced today.
	 */
	public function test_classes_exist_and_extend_abstract_core_ability(): void {
		foreach ( Manifest::all() as $name => $entry ) {
			self::assertTrue( class_exists( $entry['class'] ), sprintf( '%s: class %s must exist.', $name, $entry['class'] ) );
			self::assertTrue( is_subclass_of( $entry['class'], Abstract_Core_Ability::class ), sprintf( '%s: class %s must extend Abstract_Core_Ability.', $name, $entry['class'] ) );
		}
	}

	/**
	 * Version-sync check.
	 *
	 * composer.json intentionally carries no version field (WP0 finding);
	 * Manifest::VERSION is the single source of truth and must match the
	 * newest git tag when any exist (CI-sync check), else the seed 0.1.0.
	 */
	public function test_version_matches_newest_git_tag_or_seed(): void {
		$raw     = shell_exec( 'git describe --tags --abbrev=0 2>/dev/null' );
		$tag     = is_string( $raw ) ? trim( $raw ) : '';
		$message = 'composer.json has no version field; Manifest::VERSION is the source of truth.';
		if ( '' === $tag ) {
			self::assertSame( '0.1.0', Manifest::VERSION, 'No git tags exist: ' . $message );
		} else {
			self::assertSame( $tag, Manifest::VERSION, 'Newest git tag must match Manifest::VERSION: ' . $message );
		}
	}
}
