# Ability Name Lifecycle

Every ability name is governed by its **tier**. Tiers exist so the team can
iterate in public without having to commit names — or break consumers'
integrations — for good.

## Tiers

| Prefix | Tier | Meaning | Promises |
|---|---|---|---|
| `wp/` | experimental | Incubation. Where **all** new abilities — including every write ability — start. | **None.** Name AND schema may change or vanish in any release. |
| `core/` | stable | Core-destined. Adopted **at proposal filing**, after iteration is done. From then on it is frozen: schema backwards compatibility applies immediately. | Semver applies. |
| `wordpress/` | stable | Package-owned, not core-destined. Rare. | Semver applies. |
| *(tier flips to)* — | core | Landed in actual core. The package copy is a **backport only**, mechanically generated, and never diverges from core's implementation. | Identical to core's. |

### Promotion path

Iteration happens under `wp/`; landing in core is a mechanical pipeline, not a
rewrite. Worked example, `wp/post-create`:

1. **Incubate:** `wp/post-create` iterates and gets tested through the MCP
   adapter against real tasks. Names and schema may still change freely.
2. **Ready:** file the core proposal (a trac ticket or GitHub issue).
3. **Same release:** rename to `core/post-create` — tier becomes `stable`,
   the manifest entry gains its `proposal` URL, and the schema is frozen
   (backwards compatible from this moment).
4. **Core ships it:** tier flips to `core` and `core_since` is set. From then
   on the package copy exists only as a backport for older WordPress
   (core provides the ability itself on newer versions, and the Registrar
   skips the package copy there).

## Rename protocol (no aliases, ever)

One ability, one name, forever — there is no alias period and no deprecation
shim:

1. The new name enters the manifest in the **same release** the old name
   leaves it. The old name stops working in that release.
2. The manifest entry gains `renamed_from`, which is also merged into the
   ability's meta (`meta['renamed_from']`) — recorded forever.
3. The CHANGELOG gains a breaking entry mapping old → new, and consumers
   update in the same cycle.

Reviewers **reject alias PRs** — see [CONTRIBUTING.md](CONTRIBUTING.md).

## Semver carve-out

Backwards-compatibility promises are **per-ability-tier, not
per-package-version**:

- Removing or changing an experimental (`wp/`) ability or its schema is fine
  in any release; it is documented as a CHANGELOG breaking-for-experimental
  entry.
- Breaking changes to `stable` (`core/`, `wordpress/`) or `core` tier
  abilities force a major package bump once 1.0+.
- The package stays 0.x for now; that's fine, because the tiers carry the
  promises.

## Enforcement

Tier/prefix rules are machine-checked, not aspirational: `ManifestTest` and
`composer run verify:manifest` enforce that experimental names start with
`wp/` (and neither `core/` nor `wordpress/`), stable names start with `core/`
or `wordpress/`, `core`-tier entries have `core_since` and a `proposal` set,
`core/`-prefix stable entries have a `proposal`, names are unique,
`renamed_from` never collides with a live name, and every `class` exists and
extends `Abstract_Core_Ability`.