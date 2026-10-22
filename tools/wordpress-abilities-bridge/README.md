# WordPress Abilities Bridge (test consumer plugin)

A minimal bridge plugin that gets the `wordpress/abilities` package's
abilities registered on a WordPress site, so any consumer plugin (one that
exposes registered abilities, e.g. an MCP adapter) can use them. Local
testing only.

## Install

**Option A — symlink (live-edit loop; package changes show up instantly):**

```bash
ln -s /path/to/wordpress-abilities/tools/wordpress-abilities-bridge \
      ~/LocalWP/YOUR-SITE/app/public/wp-content/plugins/wordpress-abilities-bridge
```

**Option B — copy:**

```bash
cp -R /path/to/wordpress-abilities/tools/wordpress-abilities-bridge \
      ~/LocalWP/YOUR-SITE/app/public/wp-content/plugins/wordpress-abilities-bridge
```

Then activate **WordPress Abilities Bridge** in wp-admin → Plugins.

## How it wires up (package README, "Consuming this package")

1. **Package path** — resolved in priority order:
   - Filter: `add_filter( 'wp_abilities_bridge_package_path', fn() => '/path' )`
   - Constant: `define( 'WP_ABILITIES_PACKAGE_PATH', '/path' )` (e.g. wp-config.php)
   - Default: resolved relative to the plugin folder when it lives inside a
     checkout of the package repo (symlinked installs included); otherwise
     the path must be set via the constant or filter above
2. **Autoload** — registers a PSR-4 autoloader (`WordPress\Abilities\` →
   `<package>/src/`) unless another copy of the package is already loaded.
3. **Abilities API** — if `wp_register_ability()` is missing on this WP
   (pre-7.1 sites), loads the package's trunk-snapshot test fixture
   (`tests/Fixtures/abilities-api/`) as the API, mirroring the package's own
   test mu-plugin. On WP 7.1+/7.2+ core provides the API and this is a no-op.
   Opt out: `add_filter( 'wp_abilities_bridge_provide_api', '__return_false' );`
4. **Registration** — `Registrar::register_all()` on `wp_abilities_api_init`
   at priority 20 (after core's own ability registrations at 10, so
   core-provided names self-skip). The registry is initialized eagerly on
   `init`, so abilities are always registered no matter who touches the
   registry first.

## Share it with other testers

```bash
cd /path/to/wordpress-abilities/tools
zip -r wordpress-abilities-bridge.zip wordpress-abilities-bridge
```

Send the zip. Recipients install it via wp-admin → Plugins → Add New →
Upload Plugin (or unzip it into `wp-content/plugins/`) and activate. They
also need a checkout of this package on their machine: easiest is to symlink
the plugin folder from inside their checkout (then the package path
resolves automatically), or set `WP_ABILITIES_PACKAGE_PATH` / the
`wp_abilities_bridge_package_path` filter.

## Verify it worked

Log in as an admin: a "WordPress Abilities Bridge" admin notice reports the
API provider, the autoloader state, and the registration state of each
manifest ability (`core/content-query`, `core/content-create`,
`core/content-update`, `core/content-delete`). Then use your abilities
consumer plugin as usual.

## Notes

- The fixture fallback loads **test scaffolding** from the package repo
  (explicitly not shipped package code) — never use this bridge in
  production; it exists to make any WP version testable locally.
- This plugin lives in the package repo at `tools/wordpress-abilities-bridge/`
  (outside the gitignored `tools/out/` working area), so it is visible to git
  and can be committed and shared.
- Abilities whose version floor exceeds the site's WP version register
  nothing (the Registrar's Gate 5 skips silently).