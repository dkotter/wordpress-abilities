# Contributing

PR review enforces the rules in this document. They exist to keep abilities
**canonical** (one implementation per ability), **core-landing-ready** (no
consumer fingerprints in the code), and **safe to consume** (promises that
match the tier system in [NAME-LIFECYCLE.md](NAME-LIFECYCLE.md)).

## Review rules

### Coding standards

- Code follows the **WordPress core coding standards** (enforced by WPCS via
  `composer run lint:phpcs`).
- The text domain is **`default`** for every translatable string in an
  ability (labels, descriptions).
- Static analysis must pass `composer run lint:phpstan` (PHPStan +
  phpstan-wordpress).

### Abilities are written core-landing-ready

Abilities are self-contained classes with closures for callbacks, no plugin
helpers, no AI plumbing, no runtime dependencies — nothing that would need to
be unwound when the ability lands in core. Landing in core (and backporting
from it) should be a mechanical transform, not a port. A PR that bakes a
consumer's quirks into an ability's logic will not be merged; consumer
differences belong in the consumer.

### Canonical schema single-source

Consumers may never fork an ability's schema, and neither may this repo drift
for a consumer's sake: **a PR that changes a canonical ability's schema to
work around a consumer's (e.g. AI client model) incompatibility is rejected.**
Fix the AI client, or add a consumer-side adapter — never change the
canonical ability.

### Every `core/` ability links a ticket

Ability names are adopted with a paper trail: a `core/`-prefix or `core`-tier
ability must link a core trac/GitHub ticket, recorded in its manifest entry's
`proposal` field. Experimental (`wp/`) abilities don't need one yet; filing
it is part of promotion. Promotions and renames follow the promotion path and
rename protocol in [NAME-LIFECYCLE.md](NAME-LIFECYCLE.md).

### Per-ability test requirements

Every ability PR ships tests that:

- register through the `Registrar` and assert input/output schemas;
- cover **permission negative and positive on every gate path** — the
  request-level capability floor and any row/status-level checks — including
  fail-closed behavior on malformed or unknown input;
- test `execute` against a mocked data provider.

Write abilities additionally document their annotations (`readonly`,
`idempotent`, `destructive`) and their `required_capabilities` floor.

### Renames: no aliases, reviewers reject alias PRs

Renames follow the rename protocol: the new name enters the manifest in the
same release the old name leaves it, the entry records `renamed_from`, and
the CHANGELOG notes the break. PRs that add an alias, shim, or parallel
registration under the old name are **rejected** — no exceptions, ever.

## What CI checks

- `phpcs` — core standards, text domain `default`.
- `phpstan` — with phpstan-wordpress.
- `verify:manifest` + `ManifestTest` — tier/prefix rules, name uniqueness,
  class existence (`extends Abstract_Core_Ability`), `proposal` present where
  the tier requires it, and `composer.json` version in sync with
  `Manifest::VERSION`.
- PHPUnit on both environments — WordPress trunk (core-provided Abilities
  API) and latest stable (consumer-provided API via the test fixture), so
  core-skip and backport paths are both exercised.

## Local workflow

```bash
composer install
composer run lint:phpcs
composer run lint:phpstan
composer run verify:manifest
composer test
```