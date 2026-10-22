# KNOWN-ISSUES.md

Known deviations, deferred work, and provenance notes for the
`wordpress/abilities` package. Everything here is a conscious, documented
decision — none of it is a regression to fix silently.

This file tracks open items only: an entry is removed once fixed. The
closing commit and changelog carry the resolution; git history is the
archive of resolved entries.

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