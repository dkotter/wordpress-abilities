# KNOWN-ISSUES.md

Known deviations, deferred work, and provenance notes for the
`wordpress/abilities` package, recorded at publish time (2026-10-21).
Everything here is a conscious, documented decision — none of it is a
regression to fix silently.

## 1. Registrar deviations and gaps (post-publish fixes)

- **P6 — `Registrar::register()` lacks a `$hooked` guard. — RESOLVED
  (2026-10-21).** Each pre-init call queued another
  `wp_abilities_api_init` closure calling `try_register()`. Registration
  itself remained idempotent (Gate 1), so the observable outcome was
  unchanged; the duplicate closures were waste, not a correctness bug.
  Fixed by a per-name `static $deferred` guard in `register()`, mirroring
  `register_all()`'s `$hooked` flag — repeat pre-init calls for the same
  name queue no second closure; different names defer independently.
  No observable behavior change. Both suites green (57 tests, 504
  assertions, 1 skipped on trunk and stable).
- **P7 — Gate order deviates from spec §6.3 bullet 3:** the `class_exists`
  gate (2) and the consumer `filter` gate (3) run *before* the
  `wp_has_ability()` core-skip gate (4). Consequence: the consumer filter can
  veto core-provided ability names. Documented as-is; fix in a follow-up.
- **P8 — the Registrar does not ensure manifest-declared categories exist.**
  Manifest abilities declare `category => 'content'`, which core provides
  only from 7.2. On WP 7.1.x hosts (real pre-7.2 API; only `site` and `user`
  categories exist), `wp_register_ability()` rejects every package
  registration and Gate 8 returns false silently — every consumer must
  backfill the category during `wp_abilities_api_categories_init`. The test
  bootstrap and the `tools/wordpress-abilities-bridge` consumer plugin both
  carry the same guarded backfill today; owning category declarations is
  part of the package's promise, so the fix belongs in the Registrar:
  derive required categories from the manifest, hook them at priority 20
  from `register_all()`/`register()`, single-source the definitions in
  `src/` (today they live in `tests/Fixtures/content-category.php`, which
  composer does not ship), add an opt-out filter, and make the Gate 8
  rejection loud (`_doing_it_wrong()` naming the missing category) instead
  of silent. No-op on 7.2+ hosts and wherever a trunk-snapshot API
  provides the full category set. Discovered in consumer testing on
  WP 7.1.3 (premise in §3). Post-publish fix.

## 2. Deferred tooling

- **P1 — `bin/verify-manifest.php` is a placeholder enforcing nothing**:
  CI is green regardless of manifest contents.
- **P2 — the `coreify` round-trip check is not wired into CI**; it is a
  local gate (`php tools/coreify.php --check`) only.
- **P3 — the §10.4 CI job enforces nothing beyond `ManifestTest`.**

## 3. Stable-environment premise

The spec assumed WP 7.1.3 (stable) shipped no Abilities API, with the test
fixture shim providing one. In reality, stable 7.1.3 ships a real pre-7.2
API, so the fixture shim is dormant in the stable env. The API-absence guard
test self-skips on live environments with a real API present.

## 4. Provenance

- **`wp/post-create` (self-authored) was dropped pre-publish** (commit
  `cc442a5`). Its S2 finding (unchecked null `post_status` reaching
  `wp_insert_post`) is removed together with the file.
- **The content-write trio (`core/content-query` + write pair) is a faithful
  port of WordPress/ai PR #1025 by @jorgefilipecosta** (commit `7b1035b`),
  with credit, adapted to this package's dotted field paths (`title.raw`,
  `content.raw`, `excerpt.raw`, `author.slug`, `date.local`, `date.gmt`).
- **Tier is `stable`** (proposal filed, `core_since: null`) per HANDOFF §7
  and §8.3, and flips to `core` when the PR lands — this tier call awaits
  maintainer sign-off.

## 5. Ported-as-is PR behaviors (documented, not fixed)

Behaviors inherited verbatim from the upstream PR; intentional for parity
until core decides otherwise:

- `has_explicit_edit_fields()` is an exact match: submitting `fields` as
  `['title']` does NOT count as an edit request.
- `db_insert_error` and `db_update_error` are asymmetric (500 vs 400).
- Drafts' slugs are uniquified against `publish`.
- Deletion formats the post before deleting it.
- `get_post_by_slug` is unreachable via the write schemas — a dead path kept
  for parity with core.