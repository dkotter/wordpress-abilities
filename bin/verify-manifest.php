<?php
/**
 * Manifest rule enforcement for the wordpress/abilities package.
 *
 * Implements the manifest rules from HANDOFF.md 8.3 at the CLI, mirroring
 * tests/ManifestTest.php (same rule set, CLI runtime): tier/prefix rules,
 * name uniqueness, rename-lineage collisions, class existence and
 * inheritance, category single-sourcing, and version sync. On success it
 * exits 0 with a per-rule pass summary; on violation it exits 1 and lists
 * every failure (entry name + rule + what is wrong) so CI logs stay
 * readable.
 *
 * Dev tooling: plain PHP with no WordPress bootstrap. The script is never
 * autoloaded (D3: no `files` entries; src/ never imports bin/) and stays in
 * bin/. It loads the package through the Composer autoloader, guarded with
 * a clear error when vendor/ is missing (CI installs dependencies before
 * running it).
 *
 * @package WordPress\Abilities
 */

declare( strict_types = 1 );

use WordPress\Abilities\Abstract_Core_Ability;
use WordPress\Abilities\Categories;
use WordPress\Abilities\Manifest;

// CLI only — dev-time gate, never invoked from a web request.
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

// No WordPress bootstrap: resolve the package through Composer's autoloader.
$verify_manifest_autoloader = __DIR__ . '/../vendor/autoload.php';
if ( ! is_file( $verify_manifest_autoloader ) ) {
	fwrite( STDERR, "bin/verify-manifest.php: vendor/autoload.php is missing. Run `composer install` first.\n" );
	exit( 1 );
}
require_once $verify_manifest_autoloader;

/*
 * Manifest entries carry FQCNs whose ancestry bottoms out at WordPress'
 * WP_Ability, which does not exist outside WordPress. A minimal shim lets
 * the real ability classes be declared so existence and inheritance can be
 * asserted statically; the classes are only loaded, never instantiated, so
 * the shim needs no internals. The declaration is conditional so the real
 * WP_Ability wins whenever one is already loaded.
 */
if ( ! class_exists( 'WP_Ability' ) ) {
	class WP_Ability {
	}
}

/**
 * The entry keys every manifest entry must provide.
 *
 * @return list<string>
 */
function verify_manifest_expected_keys(): array {
	return array(
		'name',
		'class',
		'category',
		'tier',
		'min_wp_version',
		'core_since',
		'requires',
		'renamed_from',
		'proposal',
	);
}

/**
 * Whether an entry is well-formed enough for the per-entry value rules.
 *
 * Malformed entries are reported by the structure suite; the value suites
 * skip them instead of crashing on missing keys.
 *
 * @param mixed $entry A manifest entry.
 * @return bool True when the entry provides every expected key.
 */
function verify_manifest_is_complete_entry( $entry ): bool {
	if ( ! is_array( $entry ) ) {
		return false;
	}

	foreach ( verify_manifest_expected_keys() as $key ) {
		if ( ! array_key_exists( $key, $entry ) ) {
			return false;
		}
	}

	return true;
}

/**
 * PHP 7.4-safe prefix test (str_starts_with is PHP 8+).
 *
 * @param string $haystack The string to test.
 * @param string $needle   The required prefix.
 * @return bool True when $haystack starts with $needle.
 */
function verify_manifest_starts_with( string $haystack, string $needle ): bool {
	return '' !== $needle && 0 === strpos( $haystack, $needle );
}

/**
 * Names unique and matching the manifest keys (mirrors
 * ManifestTest::test_names_unique_and_match_manifest).
 *
 * @return list<string> Violations.
 */
function verify_manifest_structure(): array {
	$violations = array();

	$names = Manifest::names();
	if ( 0 === count( $names ) ) {
		$violations[] = 'The manifest must not be empty.';
	}

	if ( Manifest::names() !== array_keys( Manifest::all() ) ) {
		$violations[] = 'Manifest::names() must return the manifest keys, in manifest order.';
	}

	$counted = array_count_values( $names );
	foreach ( $counted as $name => $count ) {
		if ( $count > 1 ) {
			$violations[] = $name . ': ability names must be unique (declared ' . $count . ' times).';
		}
	}

	foreach ( Manifest::all() as $name => $entry ) {
		if ( ! is_array( $entry ) ) {
			$violations[] = $name . ': entry is malformed — expected an array of manifest fields.';
			continue;
		}

		$missing = array_diff( verify_manifest_expected_keys(), array_keys( $entry ) );
		if ( count( $missing ) > 0 ) {
			$violations[] = $name . ': entry is malformed — missing key(s): ' . implode( ', ', $missing ) . '.';
		}
	}

	return $violations;
}

/**
 * Tier and prefix rules (mirrors ManifestTest's experimental/stable tests).
 *
 * experimental ⇒ name starts wp/, and starts with neither core/ nor
 * wordpress/. stable|core ⇒ name starts core/ or wordpress/. core + core/
 * ⇒ core_since and proposal set. stable + core/ ⇒ proposal set. Every
 * entry sets a non-empty min_wp_version. Unknown tier values are flagged;
 * without this the tier rules would silently skip mistyped tiers.
 *
 * @return list<string> Violations.
 */
function verify_manifest_tier_prefix_rules(): array {
	$violations = array();

	foreach ( Manifest::all() as $name => $entry ) {
		if ( ! verify_manifest_is_complete_entry( $entry ) ) {
			continue;
		}

		$tier = $entry['tier'];
		if ( ! is_string( $tier ) || ! in_array(
			$tier,
			array(
				Manifest::TIER_EXPERIMENTAL,
				Manifest::TIER_STABLE,
				Manifest::TIER_CORE,
			),
			true
		) ) {
			$violations[] = $name . ': unknown tier ' . var_export( $tier, true ) . ' — expected experimental, stable, or core.';
			continue;
		}

		if ( Manifest::TIER_EXPERIMENTAL === $tier ) {
			if ( ! verify_manifest_starts_with( $name, 'wp/' ) ) {
				$violations[] = $name . ' (tier experimental): must start with wp/.';
			}
			if ( verify_manifest_starts_with( $name, 'core/' ) || verify_manifest_starts_with( $name, 'wordpress/' ) ) {
				$violations[] = $name . ' (tier experimental): must not start with core/ or wordpress/.';
			}
		}

		if ( Manifest::TIER_STABLE === $tier || Manifest::TIER_CORE === $tier ) {
			if ( ! verify_manifest_starts_with( $name, 'core/' ) && ! verify_manifest_starts_with( $name, 'wordpress/' ) ) {
				$violations[] = $name . ' (tier ' . $tier . '): must start with core/ or wordpress/.';
			}
		}

		if ( verify_manifest_starts_with( $name, 'core/' ) && Manifest::TIER_CORE === $tier ) {
			if ( null === $entry['core_since'] ) {
				$violations[] = $name . ' (tier core): must set core_since (the WordPress version that provides the ability).';
			}
			if ( null === $entry['proposal'] ) {
				$violations[] = $name . ' (tier core): must set proposal (a linked trac ticket or GitHub issue URL).';
			}
		}

		if ( verify_manifest_starts_with( $name, 'core/' ) && Manifest::TIER_STABLE === $tier ) {
			if ( null === $entry['proposal'] ) {
				$violations[] = $name . ' (tier stable): must set proposal before carrying the core/ prefix.';
			}
		}

		if ( empty( $entry['min_wp_version'] ) ) {
			$violations[] = $name . ': must set min_wp_version.';
		}
	}

	return $violations;
}

/**
 * Category single-sourcing (mirrors
 * ManifestTest::test_manifest_categories_are_single_sourced).
 *
 * @return list<string> Violations.
 */
function verify_manifest_categories(): array {
	$violations = array();

	$required = Categories::required_slugs();
	if ( 0 === count( $required ) ) {
		$violations[] = 'The manifest must declare at least one category.';
	}

	if ( array_values( array_unique( $required ) ) !== $required ) {
		$violations[] = 'Derived category slugs must be unique.';
	}

	foreach ( $required as $slug ) {
		if ( null === Categories::definition( $slug ) ) {
			$violations[] = 'Manifest category ' . $slug . ' has no single-sourced definition in Categories.';
		}
	}

	return $violations;
}

/**
 * Rename lineage (mirrors
 * ManifestTest::test_renamed_from_collides_with_no_live_name).
 *
 * @return list<string> Violations.
 */
function verify_manifest_rename_lineage(): array {
	$violations = array();
	$live       = Manifest::names();

	foreach ( Manifest::all() as $name => $entry ) {
		if ( ! verify_manifest_is_complete_entry( $entry ) ) {
			continue;
		}

		if ( null === $entry['renamed_from'] ) {
			continue;
		}

		if ( $name === $entry['renamed_from'] ) {
			$violations[] = $name . ': must not rename to itself (renamed_from).';
		}

		if ( in_array( $entry['renamed_from'], $live, true ) ) {
			$violations[] = $name . ': renamed_from value ' . $entry['renamed_from'] . ' collides with a live ability name.';
		}
	}

	return $violations;
}

/**
 * Class existence and inheritance (mirrors
 * ManifestTest::test_classes_exist_and_extend_abstract_core_ability).
 *
 * @return list<string> Violations.
 */
function verify_manifest_classes(): array {
	$violations = array();

	foreach ( Manifest::all() as $name => $entry ) {
		if ( ! verify_manifest_is_complete_entry( $entry ) ) {
			continue;
		}

		$class = $entry['class'];
		if ( ! is_string( $class ) || '' === $class ) {
			$violations[] = $name . ': class must be an ability class-string resolvable by the Composer autoloader.';
			continue;
		}

		if ( ! class_exists( $class ) ) {
			$violations[] = $name . ': class ' . $class . ' must exist (Composer-autoloaded).';
			continue;
		}

		if ( ! is_subclass_of( $class, Abstract_Core_Ability::class ) ) {
			$violations[] = $name . ': class ' . $class . ' must extend Abstract_Core_Ability.';
		}
	}

	return $violations;
}

/**
 * Version sync (mirrors
 * ManifestTest::test_version_matches_newest_git_tag_or_seed).
 *
 * composer.json intentionally carries no version field (WP0 finding):
 * Manifest::VERSION is the single source of truth and must match the
 * newest git tag when any exist, else the 0.1.0 seed. Should a version
 * field ever be (re)introduced in composer.json, it must match
 * Manifest::VERSION so drift cannot ship silently.
 *
 * @return list<string> Violations.
 */
function verify_manifest_version_sync(): array {
	$violations = array();

	$composer_raw = file_get_contents( __DIR__ . '/../composer.json' );
	if ( false === $composer_raw ) {
		$violations[] = 'composer.json must exist next to bin/ for the version-sync rule.';
	} else {
		$composer = json_decode( $composer_raw, true );
		if ( ! is_array( $composer ) ) {
			$violations[] = 'composer.json must be valid JSON for the version-sync rule.';
		} elseif ( isset( $composer['version'] ) && is_string( $composer['version'] ) && Manifest::VERSION !== $composer['version'] ) {
			$violations[] = 'composer.json version ' . $composer['version'] . ' must match Manifest::VERSION ' . Manifest::VERSION . '.';
		}
	}

	$tag = verify_manifest_newest_git_tag();
	if ( '' === $tag ) {
		if ( '0.1.0' !== Manifest::VERSION ) {
			$violations[] = 'No git tags exist: Manifest::VERSION ' . Manifest::VERSION . ' must stay at the 0.1.0 seed until the first tag.';
		}
	} elseif ( Manifest::VERSION !== $tag ) {
		$violations[] = 'Newest git tag ' . $tag . ' must match Manifest::VERSION ' . Manifest::VERSION . '.';
	}

	return $violations;
}

/**
 * The newest git tag for the package, or '' when none exist.
 *
 * @return string The tag name (e.g. '0.1.0'), or '' when no tags exist.
 */
function verify_manifest_newest_git_tag(): string {
	$cmd = 'git -C ' . escapeshellarg( dirname( __DIR__ ) ) . ' describe --tags --abbrev=0 2>/dev/null';
	$raw = shell_exec( $cmd );

	return is_string( $raw ) ? trim( $raw ) : '';
}

/*
 * Runner: each suite gets one summary line; violations print indented,
 * one per line, so CI logs stay readable.
 */
$verify_manifest_suites = array(
	'Manifest structure and name uniqueness' => verify_manifest_structure(),
	'Tier and prefix rules (8.3)'            => verify_manifest_tier_prefix_rules(),
	'Category single-sourcing'               => verify_manifest_categories(),
	'Rename lineage (renamed_from)'          => verify_manifest_rename_lineage(),
	'Class existence and inheritance'        => verify_manifest_classes(),
	'Version sync'                           => verify_manifest_version_sync(),
);

$verify_manifest_total    = count( $verify_manifest_suites );
$verify_manifest_failing  = 0;
$verify_manifest_position = 0;

echo 'verify:manifest — manifest rule enforcement (HANDOFF 8.3; mirrors tests/ManifestTest.php)' . "\n";
echo 'Auditing ' . count( Manifest::names() ) . ' manifest entries.' . "\n";

foreach ( $verify_manifest_suites as $verify_manifest_label => $verify_manifest_violations ) {
	++$verify_manifest_position;

	if ( 0 === count( $verify_manifest_violations ) ) {
		echo sprintf( '[%1$d/%2$d] %3$s: PASS', $verify_manifest_position, $verify_manifest_total, $verify_manifest_label ) . "\n";
		continue;
	}

	++$verify_manifest_failing;
	echo sprintf(
		'[%1$d/%2$d] %3$s: FAILED (%4$d violation%5$s)',
		$verify_manifest_position,
		$verify_manifest_total,
		$verify_manifest_label,
		count( $verify_manifest_violations ),
		1 === count( $verify_manifest_violations ) ? '' : 's'
	) . "\n";

	foreach ( $verify_manifest_violations as $verify_manifest_violation ) {
		echo '    - ' . $verify_manifest_violation . "\n";
	}
}

if ( $verify_manifest_failing > 0 ) {
	echo 'verify:manifest FAILED — ' . $verify_manifest_failing . ' of ' . $verify_manifest_total . ' rule suites report violations.' . "\n";
	exit( 1 );
}

echo 'verify:manifest OK — all ' . $verify_manifest_total . ' rule suites clean.' . "\n";
exit( 0 );
