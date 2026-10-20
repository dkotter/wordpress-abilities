# Ability Name Lifecycle

_(Skeleton placeholder authored in WP0; the full name-lifecycle documentation
is written by the documentation work package.)_

Planned contents (from the approved spec):

- Tiers and prefixes: `wp/` experimental, `core/` (core-destined, frozen at proposal filing), `wordpress/` (stable, package-owned), and the tier-`core` backport case.
- Promotion path: incubate under `wp/`, file the core proposal, rename to `core/` in the same release, set `core_since` when core ships.
- Rename protocol: the old name leaves the manifest in the same release the new name enters it. No aliases, ever.
- Semver carve-out: backward-compatibility promises are per-ability-tier, not per-package-version.