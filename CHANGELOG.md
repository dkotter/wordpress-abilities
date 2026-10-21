# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

_Nothing is released yet — the package has no published versions or tags.
The entries below describe the current prototype state._

### Added

- Repository scaffold and tooling: Composer contract for `wordpress/abilities`
  (type `library`, zero runtime dependencies, PSR-4 `WordPress\Abilities\` →
  `src/`), WPCS and PHPStan lint configs (`composer run lint:phpcs`,
  `composer run lint:phpstan`), a manifest verification script
  (`composer run verify:manifest`), and a CI workflow.
- Engine components under `src/`:
  - `Manifest` — the static inventory of shipped abilities (name, class,
    category, tier, version floors, required core features, rename lineage,
    proposal paper trail). What exists; when it's on is consumer policy.
  - `Registrar` — opt-in, idempotent, timing-aware registration through the
    Abilities API (`register_all()` / `register()`), with version gating,
    skipping of abilities core already provides, consumer-side selection
    args, and provenance meta stamping (`source`, `package_version`,
    `stability`). The package never registers itself.
  - `Abstract_Core_Ability` — the core-landing-ready base class all
    abilities extend (self-contained, closures, core coding style, text
    domain `default`).
- Initial abilities:
  - `core/content-query` — tier `core`: backport of the readonly content
    query ability as landed in WordPress core 7.2 (`core_since: 7.2`). Served
    by the package under the same name on older WordPress, skipped by the
    Registrar wherever core provides it; satisfies the `show_in_abilities`
    support feature via a polyfill where core lacks it.
- Test infrastructure: PHPUnit suites (`ManifestTest`, `RegistrarTest`,
  per-ability tests with permission negative/positive coverage on every gate
  path), and a dual wp-env matrix — WordPress trunk (core-provided Abilities
  API) and latest stable (consumer-provided API loaded via a mu-plugin test
  fixture, mirroring how consumers supply the API on WordPress < 7.2).
### Removed

- `wp/post-create` — the self-authored experimental write-ability prototype
  was dropped before any publish: the initial manifest carries only faithful
  ports of existing abilities. Went away together with review finding S2
  (an unchecked null `post_status` reaching `wp_insert_post`), which is
  removed with the file rather than fixed.
