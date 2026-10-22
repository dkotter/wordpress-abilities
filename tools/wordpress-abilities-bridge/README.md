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
3. **Abilities API** — core-provided since WordPress 6.9 (this plugin's
   `Requires at least` floor). The bridge does not provide the API
   itself: on a WordPress that predates the core Abilities API it
   records a clear error and nothing registers.
4. **Registration** — `Registrar::register_all()` at plugin load, before
   anything boots the registry: this queues the Registrar's category
   declarations (only valid while `wp_abilities_api_categories_init`
   fires) and defers the ability registrations itself. A
   `wp_abilities_api_init` priority-20 hook remains as the fallback for APIs
   provided later in the boot; the registry is initialized eagerly on
   `init` so registration happens deterministically.
5. **Categories** — the Registrar declares the manifest-declared categories
   (`content`) on `wp_abilities_api_categories_init` at priority 20; no
   category backfill is carried here. To own category registration yourself:
   `add_filter( 'wordpress_abilities_declare_categories', '__return_false' )`,
   then register them during the categories action.
6. **Status**: an admin notice (manage_options) shows the resolved package
   path, the API provider, and which manifest abilities registered.

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

- This bridge is **test scaffolding** (explicitly not shipped package
  code) — never use it in production; it exists for local testing of the
  package on sites running WordPress 6.9 or later (the core-provided
  Abilities API is required; the bridge provides none).
- This plugin lives in the package repo at `tools/wordpress-abilities-bridge/`
  (outside the gitignored `tools/out/` working area), so it is visible to git
  and can be committed and shared.
- Abilities whose version floor exceeds the site's WP version register
  nothing (the Registrar's Gate 5 skips silently).