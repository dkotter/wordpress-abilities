<?php
/**
 * Placeholder enforcement script for the ability manifest rules.
 *
 * The real tier/prefix rule enforcement (manifest name-prefix and tier
 * consistency rules, version sync, class existence checks) is delivered by a
 * later work package, together with its PHPUnit test suite. This placeholder
 * exists so the composer `verify:manifest` script succeeds from the scaffold
 * onward. It performs no checks and exits 0.
 *
 * @package WordPress\Abilities
 */

// CLI only.
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

// Placeholder only — real rule enforcement arrives in a later work package.
echo "verify:manifest — placeholder script (no rules enforced yet).\n";

exit( 0 );