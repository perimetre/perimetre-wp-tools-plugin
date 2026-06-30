# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Perimetre WP Tools is the operational/management companion to [Perimetre Core](https://github.com/perimetre/perimetre-core-wp-plugin). It provides two opt-in, inert-by-default features that are useful on **any** WordPress site (ours or a client's, standard or headless): a **status / health-check endpoint** and **Helm portal remote login**.

It was split out of Perimetre Core (which kept the dev framework: block registration, GraphQL conventions, webhooks). WP Tools deliberately has **no ACF and no WPGraphQL dependency** — both features use only the WordPress Settings API and `wp_options` — so it is a clean drop-in anywhere.

## Development Commands

```bash
composer install                       # Install dependencies + autoloader
composer dump-autoload --optimize      # Regenerate autoloader after adding classes
composer lint                          # Run phpcs (PSR-12)
composer lint:fix                      # Auto-fix lint errors
```

## Releases

Push a semver tag to trigger the GitHub Actions release workflow, which builds a plugin zip:

```bash
git tag v1.0.0 && git push origin v1.0.0
```

**When making changes that warrant a version bump**, update all three locations:

1. `Version:` header in `perimetre-wp-tools.php`
2. `PERIMETRE_WP_TOOLS_VERSION` constant in `perimetre-wp-tools.php`
3. **Current Version** + **Changelog** sections in `README.md`

## Architecture

**Entry point:** `perimetre-wp-tools.php` — loads the autoloader, registers the GitHub update checker, then bootstraps both modules unconditionally (each module gates its own behaviour behind its enable toggle). Status' `Endpoint::activate/deactivate` are wired to the activation/deactivation hooks to flush rewrite rules.

**Admin surface** — a single **Settings → Perimetre WP Tools** menu entry (`options-general.php?page=perimetre-wp-tools`). `Status\Settings` owns the page and renders a two-tab strip (`Admin\Tabs`): **Status** and **Remote Login**. Both tabs submit to the same `perimetre-wp-tools` option group; each module registers its fields against its own internal section-page slug (`Status\Settings::SECTION_PAGE`, `RemoteLogin\Settings::SECTION_PAGE`) so `do_settings_sections()` only renders the active tab.

**`Status\Endpoint`** — when the endpoint is disabled (default) no rewrite rule is registered and no URL is claimed, so the plugin is a clean drop-in. Enabling the toggle flags rewrite rules for flushing on the next admin page load. `Status\HealthChecks` runs the DB-connection and object-cache probes for the authenticated payload.

**`RemoteLogin\Settings`** — adds the Remote Login tab: enable toggle, portal URL, API key. There is no separate "Connect" button — saving the form is the single action, and `RemoteLogin\Connect::do_connect()` fires automatically on the post-save admin page load (gated on `?tab=remote-login` so saving the Status tab never triggers a handshake). The REST route `/wp-json/perimetre-wp-tools/v1/remote-login` is registered by `RemoteLogin\Endpoint`; HMAC verification (`RemoteLogin\Token`), the single-use callback to the portal, and `wp_set_auth_cookie` live in `RemoteLogin\Auth`. Single-use is enforced server-side by the portal — the plugin never trusts the signature alone.

> **Portal contract:** the portal builds the remote-login callback URL from the `perimetre-wp-tools/v1` REST namespace (`RemoteLogin\Endpoint::NAMESPACE`). Changing it requires a coordinated portal update and a re-save/reconnect on any already-connected site.

## Coding Standards

- **PSR-12** style, **PSR-4** autoloading under the `Perimetre\WpTools\` namespace, mapped to `src/`
- `declare(strict_types=1)` in every PHP file
- `vendor/` is committed (contains only the generated autoloader)
- No anonymous functions on WordPress hooks — use named methods or class methods

## Relationship to Perimetre Core

These two plugins are independent and can be installed together (e.g. on a custom headless build) or separately. They share no code and no option group. Option *keys* (`perimetre_status_*`, `perimetre_remote_login_*`) are identical to those Core used through v1.15.0, so settings migrate automatically when swapping combined-Core for Core + WP Tools.
