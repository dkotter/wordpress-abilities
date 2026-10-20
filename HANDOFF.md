# wordpress/abilities — Prototype Implementation Spec & Handoff

**Status:** Approved design → implementation handoff. All architectural decisions below are **settled**; do not relitigate them, execute them.
**Audience:** An orchestrator agent, delegating work packages (WP0–WP7, §14) to lighter worker instances. Each WP is written to be handed to a worker as-is (WP section + the spec sections it references). Workers should not need this whole document.
**Repo to scaffold:** `/Users/nik/Documents/Goose/wordpress-abilities/` (this doc's folder). `git init` here. Human reviews before anything is published to GitHub/Packagist.
**Package version at scaffold:** `0.1.0`.

---

## Suggested skills

The orchestrator should call the Skill tool for:

- **`implement`** — dispatching the work packages in §14 as delegated tasks.
- **`tdd`** — for WP1 (Registrar), WP3, WP4 (write ability needs permission-negative-first tests).
- **`code-review`** — once WP1–WP4 are green, before handing back to the human.
- **`domain-modeling`** *(optional)* — if terminology questions arise mid-build; a short CONTEXT.md capturing the tier vocabulary would help future contributors.

Worker instances generally need none of these — their WP sections carry the required context inline.

---

## 1. Mission

Build `wordpress/abilities`: a **Composer library package, not a plugin**, where the WordPress Core AI team develops **canonical core WordPress Abilities** (functional primitives defined via the WP Abilities API) for consumption by plugins (AI experiments plugin `WordPress/ai`, MCP adapter, others).

**Why this package (validated against team evidence):**

1. **Product demand (Ovi, MCP adapter maintainer):** the standalone MCP adapter gives users "no tools to actually change my site." The adapter team wants to incubate experimental core abilities — especially **write** abilities — test them against real tasks, and propose moving them into core when ready. They explicitly asked for compatibility expectations that allow fast iteration.
2. **Process demand (Greg, Abilities API lead):** three readonly abilities (`core/content-query`, `core/users-query`, `core/settings-get`) landed in trunk, and keeping the AI plugin's drifted copies in sync is manual churn (sync PRs WordPress/ai #1206/#1207/#1208). Schema forks made for WP AI Client model incompatibilities broke sync and forced alias removal.

This package is the resolution of both: **one canonical implementation per ability**, in a neutral core-team-governed repo. Incubation happens here (`wp/` tier), core landing becomes a mechanical pipeline (`coreify`), and consumers stop forking schemas.

**Scope:** any core WP abilities — anything facing entities from core WordPress software (posts, users, settings, comments, terms, media), read **and** write. Consumer plugins layer their own prefixed abilities on top (`mcp-adapter/list-mcp-servers`, `ai/generate-alt-text`) but all consume this core package.

---

## 2. Settled decisions (do not relitigate)

| # | Decision | Rationale (one line) |
|---|----------|----------------------|
| D1 | Composer library, type `library`, GPL-2.0-or-later, `php >= 7.4` | Abilities are classes + registration data; no UI/activation of their own. |
| D2 | **Zero runtime dependencies.** Package operates only within the bounds of the WP Abilities API; it never ships the API itself. | Consumer loading/consumption mechanism is entirely consumer policy. |
| D3 | PSR-4 only: `WordPress\Abilities\` ⇒ `src/`. **No `files` autoload entries, no side effects at autoload time, helpers are static methods, not function files.** | Safety when multiple plugins bundle the package. |
| D4 | **The package has NO autoloader opinion.** No suggestion of Jetpack Autoloader, no shipped `load.php`, no `suggest` entries. README consumer contract states *policy* (§11), not mechanism. | A Composer library never decides how it's autoloaded; no Automattic/Jetpack-branded dependency. |
| D5 | Registration is **opt-in, idempotent, timing-aware** via a `Registrar`. Package never bootstraps itself. | Makes double-consumption safe; each host plugin decides whether/when to register. |
| D6 | Name agility via **tier-prefixed naming** (§7): `wp/` experimental (zero promises), `core/` stable core-destined (adopted at proposal filing, then frozen), `wordpress/` stable package-owned (rare). | Lets the team iterate without committing names "for the entire internet forever." |
| D7 | **One ability, one name, ever. No aliases.** Renames unregister the old name in the same release; lineage via `meta['renamed_from']` + changelog. | Alias bridges caused real breakage (WordPress/ai sync PRs). |
| D8 | **Canonical schema single-source rule:** consumers may never fork an ability's schema. If an AI client can't handle a schema, fix the AI client or add a consumer-side adapter — never change the canonical ability. | The drift lesson from Greg's sync PRs. |
| D9 | Abilities are written **core-landing-ready**: self-contained classes with closures, core coding style, text domain `default`, no plugin helpers, no AI plumbing. | `coreify` (§9) is then a *mechanical* transform, both directions. |
| D10 | The three trunk abilities enter the package as **tier-core backports** (`core_since: 7.2`) so consumers get them on WP 6.9–7.1 under the *same names*. Same-name-overlap on 7.2+ is a feature; guarded by provenance meta + `wp_has_ability()` skip. | Retires Greg's sync PRs; gives the adapter tools on current stable WP. |
| D11 | First experimental **write** abilities incubate here: `wp/post-create`, `wp/post-update`, `wp/post-delete`. Prototype ships `wp/post-create` as the proof of the experimental pipeline. | Writes are the team's stated priority; readonly trio is already core. |
| D12 | Governance: PR review follows **core coding standards**; every new ability requires a **linked core trac/GitHub ticket**; canonical-schema rule is a review rule (CONTRIBUTING). | Promotion always has a paper trail. |
| D13 | Semver, package stays **0.x** (aligned with adapter's 0.x); tiers are per-ability, with a documented carve-out (§7.4). | 0.x + tier-scoped promises = agility without chaos. |

---

## 3. Verification reading list (read before coding; verify, don't assume)

Details of the core API below are **intentionally unverified in this spec** where marked. Workers must read the actual trunk files before finalizing signatures.

- Abilities API core: https://github.com/WordPress/wordpress-develop/tree/trunk/src/wp-includes/abilities-api
  - `class-wp-ability.php` — exact constructor signature/arg keys (D-marked below).
  - `class-wp-abilities-registry.php` — `wp_register_ability()`, `ability_class` registration arg, `wp_abilities_api_init` hook timing.
- Landed canonical abilities (the model for rigor and the coreify source):
  - `src/wp-includes/abilities/class-wp-abilities-content.php` (`core/content-query`)
  - `src/wp-includes/abilities/class-wp-abilities-users.php` (`core/users-query`)
  - `src/wp-includes/abilities/class-wp-abilities-settings.php` (`core/settings-get`)
- AI plugin (for the authoring-surface ergonomics we're dieting, and for what must **stay behind** in the plugin): https://github.com/WordPress/ai — `includes/Abstracts/Abstract_Ability.php` (extends `WP_Ability`; abstract `input_schema()`/`output_schema()`/`execute_callback()`/`permission_callback()`/`meta()`; per-ability `system-instruction.php`; guideline injection; `set_provider_model_preference`).
- MCP adapter (consumer #1): https://github.com/WordPress/mcp-adapter — already uses Jetpack Autoloader; that's its business, not the package's.

---

## 4. Package layout (prototype target)

```
wordpress-abilities/
├── composer.json                  # exact contract, §5
├── composer.lock                  # committed
├── LICENSE                        # GPL-2.0-or-later text
├── README.md                      # §11
├── NAME-LIFECYCLE.md              # §7
├── CONTRIBUTING.md                # §12
├── CHANGELOG.md                   # Keep a Changelog; starts at [Unreleased]
├── .gitignore                     # vendor/, .wp-env/, node_modules/, .phpunit.result.cache
├── .editorconfig
├── phpcs.xml.dist                 # WPCS, ruleset per §10
├── phpstan.neon.dist              # + phpstan-wordpress
├── .github/workflows/ci.yml       # §10
├── .wp-env.trunk.json             # test env: core = wordpress-develop#trunk
├── .wp-env.stable.json            # test env: latest stable + API shim mu-plugin
├── bin/verify-manifest.php        # tier/prefix rule enforcement (§8.3) — plain PHP CLI
├── tools/coreify.php              # §9 — mechanical core landing / backport generation
├── src/
│   ├── Abstract_Core_Ability.php # §6.1
│   ├── Manifest.php               # §6.2
│   ├── Registrar.php              # §6.3
│   └── Support/
│       ├── Features.php           # feature detection (e.g. show_in_abilities)
│       └── polyfills/
│           └── show-in-abilities.php  # registrar-loaded ONLY when core lacks it (§6.4)
└── tests/
    ├── bootstrap.php
    ├── ManifestTest.php
    ├── RegistrarTest.php
    ├── Abilities/
    │   ├── ContentQueryTest.php
    │   └── PostCreateTest.php
    └── Fixtures/
        └── mu-plugins/load-abilities-api.php   # simulates consumer-provided API on WP < 7.2 (§10.2)
```

Ability classes live in area subdirectories: `src/Content/Content_Query.php` → `WordPress\Abilities\Content\Content_Query`.

---

## 5. composer.json (final agreed contract — create exactly this)

```json
{
    "name": "wordpress/abilities",
    "type": "library",
    "description": "Canonical WordPress Abilities, consumable by plugins via the Abilities API.",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=7.4"
    },
    "require-dev": {
        "wp-coding-standards/wpcs": "^3.4",
        "phpstan/phpstan": "^2.2",
        "szepeviktor/phpstan-wordpress": "^2.0",
        "wp-phpunit/wp-phpunit": "^7.1",
        "yoast/phpunit-polyfills": "^4.0"
    },
    "autoload": {
        "psr-4": {
            "WordPress\\Abilities\\": "src/"
        }
    },
    "scripts": {
        "lint:phpcs": "phpcs",
        "lint:phpstan": "phpstan analyse",
        "test": "phpunit",
        "verify:manifest": "php bin/verify-manifest.php"
    },
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true
        }
    }
}
```

Notes:

- The `config.allow-plugins` block is a **permitted adjustment** to the previously agreed spec: WPCS pulls in the dealerdirect installer, which is a Composer plugin. It is dev-tooling only and does not violate the zero-runtime-dependency policy (D2/D3). No other runtime `require` entries may ever be added.
- Package version lives in **two places that must stay in sync**: `composer.json` and `Manifest::VERSION`. A CI test asserts they match (§8.3).
- PHP floor `>= 7.4` is a flagged open item (§13) — align with the core 7.2 requirements discussion when it lands; a bump is a normal release change, not an architecture change.

---

## 6. Core engine components

### 6.1 `src/Abstract_Core_Ability.php`

Dieted base class. `Abstract_Ability` in the AI plugin keeps all AI plumbing (prompt files, guideline injection, model preferences, `wpai_*` filters) — the package's base keeps only the ergonomic authoring surface over `WP_Ability` plus safety helpers.

```php
<?php
namespace WordPress\Abilities;

use WP_Ability;

/**
 * Base class for canonical WordPress abilities shipped in this package.
 *
 * Subclasses are written core-landing-ready: self-contained, closures for
 * callbacks, no plugin helpers, no AI plumbing, text domain 'default'.
 */
abstract class Abstract_Core_Ability extends WP_Ability {

	abstract protected function ability_name(): string;              // full name incl. prefix, e.g. 'wp/post-create'
	abstract protected function label(): string;
	abstract protected function description(): string;
	abstract protected function category_slug(): string;
	abstract protected function input_schema(): array;
	abstract protected function output_schema(): array;
	abstract protected function make_execute_callback(): callable;
	abstract protected function make_permission_callback(): callable;
	abstract protected function ability_meta(): array;              // readonly/destructive/idempotent/stability/renamed_from…
	abstract protected function required_capabilities(): array;      // static, request-level floor

	protected function package_meta(): array {                       // DO NOT override; Registrar merges provenance
		return array(
			'source'          => 'wordpress/abilities',
			'package_version' => Manifest::VERSION,
		);
	}

	final public function __construct() { /* wire abstracts into parent WP_Ability args */ }
}
```

- **Verify against trunk first (§3):** the exact `WP_Ability` constructor arg keys, the meta key set (`readonly`, `destructive`, `idempotent`, `public`, `show_in_rest`), and how `class-wp-abilities-content.php` exposes itself to the registry (`ability_class` mechanism). Finalize constructor wiring and method naming so that `coreify`'s transforms (§9) remain mechanical — prefer mirroring the naming pattern core already uses in `WP_Abilities_Content`.
- Safety helpers on the base (small, static, side-effect-free): fail-closed filter normalization for the same style core uses in `WP_Abilities_Content` (malformed filter arrays ⇒ reject, never widen).

### 6.2 `src/Manifest.php`

Static inventory. No logic beyond validation-friendly accessors; the manifest is the single source of truth for *what exists* and *what tier it is*. Consumers own *when it's on*.

```php
final class Manifest {
	public const VERSION = '0.1.0';

	public const TIER_EXPERIMENTAL = 'experimental';
	public const TIER_STABLE      = 'stable';
	public const TIER_CORE        = 'core';

	/** @return array<string, array<string,mixed>> name => entry */
	public static function all(): array {}
	public static function get( string $name ): ?array {}
	public static function names(): array {}
}
```

**Entry schema:**

| Key | Type | Notes |
|-----|------|-------|
| `name` | string | Full ability name incl. tier prefix. |
| `class` | class-string | FQCN under `WordPress\Abilities\`, extends `Abstract_Core_Ability`. |
| `category` | string | Category slug; must be a category the Abilities API registers (verify core's default category slugs in §3 reading). |
| `tier` | `experimental` \| `stable` \| `core` | |
| `min_wp_version` | string | Package support floor for this ability (initially `6.9` for all — see §13). |
| `core_since` | ?string | WP version where **core** provides this ability. Non-null ⇒ Registrar must skip when core has it. |
| `requires` | string[] | Core support-surface features the ability needs beyond the API itself, e.g. `['show_in_abilities']` (§6.4). |
| `renamed_from` | ?string | Prior name, set forever once a rename happens; also merged into ability meta. |
| `proposal` | ?string | Required for `stable` + `core/` and for `core` tiers: trac ticket or GitHub issue URL. |

**Initial entries (prototype):**

```php
'core/content-query' => array(
	'class'          => Content\Content_Query::class,
	'category'       => 'content',
	'tier'           => self::TIER_CORE,
	'min_wp_version' => '6.9',
	'core_since'     => '7.2',
	'requires'       => array( 'show_in_abilities' ),
	'renamed_from'   => null,
	'proposal'       => '<trac or GitHub ticket for core/content-query>', // verify + fill from core history
),
'wp/post-create' => array(
	'class'          => Content\Post_Create::class,
	'category'       => 'content',
	'tier'           => self::TIER_EXPERIMENTAL,
	'min_wp_version' => '6.9',
	'core_since'     => null,
	'requires'       => array(),
	'renamed_from'   => null,
	'proposal'       => null,
),
```

### 6.3 `src/Registrar.php`

The package's **only** world is the Abilities API. Opt-in, idempotent, timing-aware, version-gated, provenance-stamping.

```php
final class Registrar {
	private static bool $hooked     = false;
	private static array $registered = array();

	/** Register all eligible manifest abilities. */
	public static function register_all( array $args = array() ): void {}
	// $args: 'public_only' => bool (register only abilities whose meta['public'] is true — the MCP-tool use case),
	//        'filter'     => ?callable( string $name, array $entry ): bool (consumer-side selection policy).

	/** Register a single manifest ability by name. */
	public static function register( string $name ): bool {}

	public static function is_registered( string $name ): bool {}
}
```

Behavior, in order:

1. **API-present guard:** if `! function_exists( 'wp_register_ability' )`, fire `_doing_it_wrong()` and return. The package **never** ships the Abilities API; on WP < 7.2 the consumer must provide it (that's exactly how the MCP adapter works).
2. **Timing-aware:** if `did_action( 'wp_abilities_api_init' )`, register immediately; otherwise `add_action( 'wp_abilities_api_init', … )` guarded by the static `$hooked` flag (idempotent across repeated calls — double-consumption by two plugins must be safe).
3. **Per-ability gates, in order — skip silently (log via `doing_it_wrong` only for the API guard):**
   - in-process `$registered` check,
   - `wp_has_ability( $name )` → core already provides it (this is the `core_since` mechanism; on WP 7.2+ the three backports self-skip),
   - `version_compare( get_bloginfo('version'), $entry['min_wp_version'], '>=' )`,
   - support-surface: every `requires` feature available via `Support\Features::is_available()`, attempting a polyfill load when one exists (§6.4).
4. **Registration:** pass the class through the core `ability_class` mechanism (verify in §3 reading) with the ability's name/category/schemas/capabilities and **provenance meta merged in**: `meta['source'] = 'wordpress/abilities'`, `meta['package_version']`, `meta['stability'] = <tier>` (consumers like the adapter surface `stability` in tool descriptions), plus `meta['renamed_from']` when set.

Version-skew awareness: another consumer's autoloader may load a **newer copy** of the package than the caller's bundle. Everything above is idempotent and per-ability, so a stale manifest simply registers nothing new; never assume exact-package-version equality.

### 6.4 `src/Support/` — feature detection & polyfills

- `Features::is_available( string $feature ): bool` — small detection map. `show_in_abilities` ⇒ the post-type exposure mechanism exists (7.2 core feature; verify the actual core implementation and its post-type default during WP3 — the default matters for the polyfill).
- `polyfills/show-in-abilities.php` — **loaded via `require_once` from the Registrar only** when core lacks the feature and an ability requires it. Never autoloaded (D3). Minimal: mirror core's semantics for post-type exposure on 6.9–7.1. If a required feature is missing and no polyfill exists, the ability skips.
- The polyfill is the canonical example of the backport support-surface rule: `core/content-query` on 6.9 needs `show_in_abilities`-style post-type gating; the package declares it in `requires` and satisfies it behind a version gate rather than diverging the ability's logic.

---

## 7. Name lifecycle (content of `NAME-LIFECYCLE.md`)

### 7.1 Tiers

| Prefix | Tier | Meaning | Promises |
|--------|------|---------|----------|
| `wp/` | experimental | Incubation. Where **all** new abilities — including every write ability — start. | **None.** Name AND schema may change or vanish in any release. |
| `core/` | stable | Core-destined. Adopted **at proposal filing**, after iteration is done. Then frozen: schema BC from that moment. | Semver applies. |
| `wordpress/` | stable | Package-owned, not core-destined. Rare. | Semver applies. |
| (tier flips to) | core | Landed in actual core. Package copy is a **backport only**, mechanically generated, never diverges. | Identical to core's. |

### 7.2 Promotion path

`wp/post-create` → iterate/test through the MCP adapter against real tasks → ready → **file the core proposal (trac/GitHub ticket)** → same release: rename to `core/post-create`, tier → `stable`, entry gains `proposal` URL, schema frozen. When it ships in a core release: tier → `core`, `core_since` set.

### 7.3 Rename protocol (no aliases, ever)

1. The new name enters the manifest in the **same release** the old name leaves it. There is no alias period.
2. Entry gains `renamed_from` + `meta['renamed_from']`.
3. CHANGELOG gains a breaking entry mapping old → new, and consumers update in the same cycle.

### 7.4 Semver carve-out

BC promises are **per-ability-tier, not per-package-version**. Removing or changing an experimental (`wp/`) ability or its schema is allowed in any release and is a documented CHANGELOG breaking-for-experimental entry. Breaking changes to `stable`/`core` tiers force a major bump once 1.0+. Package stays 0.x while the adapter is 0.x; that's fine because tiers carry the promises.

---

## 8. Prototype abilities

### 8.1 `core/content-query` backport (proves the core pipeline)

- Take `class-wp-abilities-content.php` from trunk (§3). Port to `WordPress\Abilities\Content\Content_Query extends Abstract_Core_Ability`. **Logic must stay byte-close** — tier-core package copies never diverge from trunk; the `coreify` round-trip check (§9, §10) enforces this.
- Manifest entry exactly as §6.2. Rigor to preserve: fail-closed on malformed filters, per-row permission checks on top of the request-level gate, `show_in_abilities` post-type exposure (7.2) satisfied via polyfill on older WP.
- Tests (§10): on **trunk** env — Registrar must *skip* it (core provides it) and the ability must not double-register; on **stable** env — registers via Registrar, schema assertions, permission negative/positive, execute with a mocked data set.

### 8.2 `wp/post-create` (proves the experimental pipeline)

- Tier experimental — iterate freely; that's the tier's job. `WordPress\Abilities\Content\Post_Create`.
- **Write-ability standards (the bar for all writes):**
  - Annotations on the ability: `readonly=false`, `idempotent=false`, `destructive=false` (create is not destructive; `wp/post-update` and `wp/post-delete` will differ — delete is destructive, update idempotent-ish).
  - `required_capabilities` as the preferred static permission mechanism — floor `edit_posts`; `permission_callback` handles row/status-level checks: status `publish` ⇒ `current_user_can( 'publish_posts' )`, post-type-specific caps via the same mapping core's edit flow uses, **fail-closed** on unknown post types/statuses/malformed input.
  - Permission-negative **and** positive tests on every gate path.
- Draft input schema (iterate in experimental tier): `post_type`, `title`, `content?`, `excerpt?`, `status?` (`draft|publish|pending`), `slug?`. Defer `meta` input until a later iteration — risky surface.
- Draft output: `id`, `link`, `status`. Execute wraps `wp_insert_post` with validation; **no AI calls anywhere in the package**.

### 8.3 Manifest rule enforcement (CI, `bin/verify-manifest.php` + a phpunit test)

- `tier experimental` ⇒ name starts `wp/`, and starts with neither `core/` nor `wordpress/`.
- `tier stable` ⇒ name starts `core/` or `wordpress/`.
- `tier core` ⇒ name starts `core/` **and** `core_since` is non-null **and** `proposal` is set.
- `tier stable` + `core/` prefix ⇒ `proposal` is set (paper trail at filing).
- Names unique; `renamed_from` doesn't collide with any live name; every `class` exists and extends `Abstract_Core_Ability`.
- `composer.json` version === `Manifest::VERSION`.

---

## 9. `tools/coreify.php`

A dev-time CLI script (plain PHP, no runtime deps — dev tooling is exempt from D2/D3 runtime rules but must not be autoloaded).

- **Forward mode** (package ability → core file): strip `WordPress\Abilities\` namespace; mangle class names to core style (`WP_Abilities_*`); unwrap `Abstract_Core_Ability` authoring surface back into core's class shape; verify text domain `default`.
- **`--reverse` mode** (trunk file → package backport): add namespace, wrap into `Abstract_Core_Ability` shape, emit manifest entry skeleton with `tier: core` + `core_since`.
- **Round-trip invariant (testable):** for tier-core entries, `coreify --reverse` of the trunk file followed by `coreify` forward must produce code functionally identical to the trunk original (modulo whitespace). The CI check for §8.1 uses this. The prototype hand-port may be done first (WP3); `coreify` is then proven by reproducing it (WP5).

---

## 10. Testing & CI

### 10.1 Environments (wp-env + wp-phpunit)

Two test environments, both run in CI:

- **Trunk** (7.2-dev): `.wp-env.trunk.json` with core = `https://github.com/WordPress/wordpress-develop.git#trunk`. Validates forward compat: core provides the three backported abilities, Registrar skips them, `wp/*` abilities still register.
- **Latest stable** (7.1): `.wp-env.stable.json`. Validates the backport path: package abilities register and execute under a **consumer-provided** Abilities API, version gates skip core-provided abilities correctly.

### 10.2 The stable-env API shim (important)

On WP < 7.2 the Abilities API does not exist in core. The package must not ship it (D2) — so the test env simulates a consumer that does: `tests/Fixtures/mu-plugins/load-abilities-api.php` loads the abilities-api classes copied from trunk into the fixture dir as a mu-plugin. This mirrors the MCP adapter's real-world model, and the fixture copies are documented as manually refreshed from trunk (they are test fixtures, not shipped code).

### 10.3 Suites

- `ManifestTest` — §8.3 rules.
- `RegistrarTest` — API-present guard, idempotence under double `register_all()`, timing (hooked vs immediate), core-skip on trunk, version gate, `public_only` filter, provenance meta present.
- Per-ability suites — register via Registrar, assert schemas, permission negative/positive, execute with mocked provider. `PostCreateTest` writes permission-negative tests first (tdd).
- MCP adapter as a "real tasks" acceptance harness is a **later phase** (§12), not prototype CI.

### 10.4 CI (`.github/workflows/ci.yml`)

Matrix `wp: [trunk, stable]`; jobs: `phpcs` (WPCS core ruleset, text domain `default`), `phpstan` (with phpstan-wordpress), `verify-manifest` + `ManifestTest`, `phpunit` per env. Use `yoast/phpunit-polyfills` for 7.4 compat. Workers should verify wp-env matrix specifics against current wp-env docs rather than guessing config keys.

---

## 11. README — "Consuming this package" (policy, not mechanism)

Three rules, verbatim intent:

1. **Load it however your plugin loads Composer code** — plain Composer autoloader, a shared autoloader (e.g. Jetpack Autoloader), or a guarded `require`. If multiple active plugins may bundle this package, **ensure only one copy loads** (a `class_exists( 'WordPress\Abilities\Registrar' )` guard or a shared autoloader — your choice). The package has no opinion.
2. **Register on your own gating and timing** — `Registrar::register_all()`, or per-ability via `Registrar::register( $name )`, on `wp_abilities_api_init` or later; the Registrar is idempotent and timing-aware, so late registration is safe. What exists is the manifest; **when it's on is your policy**.
3. **Ship `vendor/` in your distribution** — don't require end-users to run Composer.

Plus: the Abilities API must be present at registration time (core in 7.2+; consumer-provided on older WP), the tier table summary (§7.1), and the current ability list with tiers.

## 12. CONTRIBUTING — review rules

- Core coding standards; text domain `default`; abilities written core-landing-ready (D9).
- **Canonical schema single-source (D8):** a PR that changes a canonical ability's schema to work around a consumer's model incompatibility is rejected. Fix the AI client or add a consumer-side adapter.
- Every new ability links a core trac/GitHub ticket; promotions follow §7.2 (the `proposal` manifest field is the paper trail).
- Per-ability test requirements: permission negative + positive on every gate path.
- Rename protocol per §7.3 — reviewers reject alias PRs.

---

## 13. Out of scope for the prototype / later phases / open flags

**Later phases (do not build now):** AI plugin migration (move `includes/Vendor` → standard `vendor/` + autoloader of choice, `.distignore` keeps vendor/, bridge package hooks to `wpai_*` filters, drop drifted `Posts`/`Users`/`Settings`/`Content` utility copies and deprecated `ai/get-post-*` aliases, keep AI-backed abilities plugin-local under `ai/`); MCP adapter consuming via `Registrar::register_all()`; adapter "real tasks" harness in CI; `wp/post-update` + `wp/post-delete`; Packagist publishing.

**Open flags for the human's review:**

1. PHP floor `>= 7.4` — align with core 7.2 requirements discussion (may move; normal release change).
2. Package support floor `6.9` for backports — matches consumer plugins' matrix; confirm.
3. `show_in_abilities` polyfill semantics — verify core's actual post-type default on trunk before WP3 finalizes.
4. Category slugs — verify core's registered categories during WP1; manifest entries carry real slugs.
5. Confirm `proposal` ticket URLs for the three landed abilities from core history.

---

## 14. Work packages (delegate to worker instances)

Dependency graph: `WP0 → WP1 → WP2 → {WP3, WP4} → WP5 → WP7`; `WP6` parallelizes any time after WP0.

### WP0 — Repo scaffold & tooling configs
**Deps:** none. **Files:** §4 top level (all except `src/`, `tests/` contents): composer.json (§5 exact), LICENSE, README/CONTRIBUTING/NAME-LIFECYCLE/CHANGELOG skeletons, .gitignore, .editorconfig, phpcs.xml.dist, phpstan.neon.dist, empty CI workflow, `git init` + initial commit.
**Done when:** `composer install` clean; `composer run lint:phpcs`/`lint:phpstan` green on stub files; repo committed.

### WP1 — Engine trio
**Deps:** WP0. **Files:** `src/Abstract_Core_Ability.php` (§6.1), `src/Manifest.php` with the two initial entries (§6.2), `src/Registrar.php` (§6.3), `src/Support/*` (§6.4).
**Steps:** read §3 verification files first; finalize signatures; wire constructor to verified `WP_Ability` args; implement gates in §6.3 order.
**Done when:** phpstan + phpcs green; Manifest accessor behavior matches §6.2; Registrar logic reviewed against every §6.3 bullet.

### WP2 — Test infrastructure
**Deps:** WP1. **Files:** `.wp-env.trunk.json`, `.wp-env.stable.json`, `tests/bootstrap.php`, `tests/Fixtures/mu-plugins/load-abilities-api.php`, CI workflow functional.
**Done when:** both envs boot; `RegistrarTest` + `ManifestTest` pass on trunk (skip-behavior assertions can use manifest names before WP3 lands); CI matrix runs end-to-end.

### WP3 — `core/content-query` backport
**Deps:** WP1, WP2. **Files:** `src/Content/Content_Query.php`, Support polyfill finalization (§6.4), `tests/Abilities/ContentQueryTest.php`, manifest entry finalized.
**Done when:** trunk env: skipped by Registrar, core's own ability intact; stable env: registers, schemas match trunk's, permission neg/pos pass, execute works; round-trip diff vs trunk is logic-identical (byte-close modulo namespace/wrapping).

### WP4 — `wp/post-create` experimental
**Deps:** WP1, WP2 (tdd; permission-negative tests first). **Files:** `src/Content/Post_Create.php`, `tests/Abilities/PostCreateTest.php`.
**Done when:** §8.2 standards all met (annotations, capabilities floor, fail-closed callbacks, neg/pos tests, execute happy path); registers under `wp/` prefix; manifest rules green.

### WP5 — `coreify` tool
**Deps:** WP3 (round-trip target). **Files:** `tools/coreify.php`.
**Done when:** forward + `--reverse` modes work on `core/content-query`; round-trip invariant holds (§9); diff between tool-generated backport and the WP3 hand-port is empty or trivially cosmetic.

### WP6 — Docs
**Deps:** WP0 only. **Files:** README consuming-section (§11), NAME-LIFECYCLE.md (§7), CONTRIBUTING (§12), CHANGELOG Unreleased entry.
**Done when:** documents match the implemented components; no mechanism-creep in the consumer contract (no autoloader opinions).

### WP7 — (Optional, second wave) `core/users-query` + `core/settings-get` backports
**Deps:** WP3 pattern proven. Same acceptance shape as WP3, using `coreify --reverse`.

---

## 15. Review checklist for the human (before publishing)

- [ ] Package name `wordpress/abilities` and repo location confirmed.
- [ ] composer.json matches §5 (with the noted allow-plugins adjustment).
- [ ] No autoloader opinion anywhere in package or docs (D4).
- [ ] No aliases, no `files` autoload, no runtime deps, no side effects (D2/D3/D7).
- [ ] Tier/prefix rules enforced in CI (§8.3) and documented (§7).
- [ ] Backports byte-close to trunk; round-trip check wired.
- [ ] Write-ability standards visible in `wp/post-create` (§8.2).
- [ ] Open flags in §13 triaged.