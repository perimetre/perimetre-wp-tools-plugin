# Perimetre WP Tools

Operational tooling for Perimetre WordPress sites. Safe to drop into **any** WordPress site — standard or headless, ours or a client's — because every feature is opt-in and defaults to inert.

**Repository:** `perimetre-wp-tools-plugin`
**WordPress plugin slug:** `perimetre-wp-tools`

This plugin was split out of [Perimetre Core](https://github.com/perimetre/perimetre-core-wp-plugin). Core keeps the dev framework (block registration, GraphQL conventions, webhooks) for custom headless builds; WP Tools keeps the operational features that are useful everywhere.

---

## Requirements

- PHP 8.1+
- WordPress 6.4+

No ACF, no WPGraphQL. Both features use only the WordPress Settings API and `wp_options`, so the plugin has zero hard dependencies.

---

## What This Plugin Does

- **Status / health-check endpoint** — a configurable public URL (DB + object cache probes) for uptime monitoring.
- **Remote Login** — lets users registered in the Helm portal SSO into their matching WP user by email.

Both are disabled by default and do nothing until configured under **Settings → Perimetre WP Tools**.

---

## Status Endpoint

Enable it on the **Status** tab, set a URL slug (default `status`) and a secret token.

- `GET /{slug}/` → `{"status":"ok"}` (shallow liveness check, always safe to expose).
- `GET /{slug}/?token=<secret>` → full health payload (DB connection, object cache probe, WP/PHP/plugin versions). Returns HTTP 500 if any check fails.

When the endpoint is disabled no rewrite rule is registered, so the plugin stays a clean drop-in.

---

## Remote Login

Enable it on the **Remote Login** tab. Create a Site in the Helm portal, copy the API key it shows once, paste it in, set the portal URL, and click **Save** — saving is the single action that both persists settings and runs the portal handshake.

- REST route: `GET /wp-json/perimetre-wp-tools/v1/remote-login?token=<signed token>`
- Tokens are compact-JWT-style, HMAC-SHA256 signed with the site's API key.
- Single-use is enforced **server-side by the portal** (the plugin POSTs the `jti` back to claim it); the plugin never trusts the signature alone.
- No matching local user → silent redirect to `wp-login.php`. Users are never auto-created.

> **Portal contract:** the portal builds the callback URL from the `perimetre-wp-tools/v1` REST namespace. If you change it, update the portal in lockstep.

---

## Development

```bash
composer install                       # Install dependencies + autoloader
composer dump-autoload --optimize      # Regenerate autoloader after adding classes
composer lint                          # Run phpcs (PSR-12)
composer lint:fix                      # Auto-fix lint errors
```

`vendor/` is committed (it contains only the generated autoloader).

## Releases

Push a semver tag to trigger the GitHub Actions release workflow, which builds a plugin zip:

```bash
git tag v1.0.0 && git push origin v1.0.0
```

When bumping the version, update all three locations:

1. `Version:` header in `perimetre-wp-tools.php`
2. `PERIMETRE_WP_TOOLS_VERSION` constant in `perimetre-wp-tools.php`
3. **Current Version** + **Changelog** sections below

---

## Current Version

**1.0.4**

## Changelog

### 1.0.4

- **The status endpoint no longer shadows existing content at its path.** When the configured slug already belongs to a published page or a public taxonomy term (e.g. a real `/status/` page on a site using `/%category%/%postname%/` permalinks), the endpoint now **yields** — it skips registering its `top`-priority rewrite rule instead of hijacking the URL — and the Status settings tab shows an inline warning prompting you to choose a different slug. On upgrade, any previously claimed-but-conflicting rule is automatically flushed out, restoring the shadowed page. The slug remains configurable and defaults to `status`.

### 1.0.3

- **Fixed the status endpoint returning a 404 after being enabled.** The rewrite rule relied on a one-shot flush flag that could be missed (e.g. enabling via WP-CLI, or the flag being consumed on a request before the rule was registered), leaving `/{slug}/` un-routed until a manual permalink flush. The flush logic now self-heals: on any admin page load, if the endpoint is enabled but its rule is absent from the rewrite table, it flushes automatically.

### 1.0.2

- **Fixed a fatal error when this plugin and Perimetre Core are both active.** Both bundled an identically-named Composer autoloader bootstrap class (a side effect of the split), so whichever activated second hit `Cannot declare class ComposerAutoloaderInit…`. This plugin now uses a unique autoloader suffix (`config.autoloader-suffix`).

### 1.0.1

- Maintenance release. Bumped the release workflow's `action-gh-release` to v3 (Node 24 runtime). No functional or plugin-code changes.

### 1.0.0
- Initial release. Status endpoint and Remote Login extracted from Perimetre Core (where they shipped through v1.15.0). Option keys are unchanged, so existing settings carry over.
- REST namespace renamed from `perimetre-core/v1` to `perimetre-wp-tools/v1` (requires a coordinated portal update; already-connected sites must re-save the Remote Login tab to reconnect).
- Admin page is now standalone at **Settings → Perimetre WP Tools** with Status and Remote Login tabs.
