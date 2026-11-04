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
composer test                          # Run the PHPUnit tests (installs the test tooling first)
```

**Tests** live in `tests/` with their own `composer.json`, never in the plugin's: the committed `vendor/` autoloader
is what a site runs, and Brain Monkey and Mockery register files that load on every request. Don't add test tools
to the root `composer.json`. Tests extend `Perimetre\WpTools\Tests\TestCase`, which sets up Brain Monkey; stub any
WordPress function a test needs. CI (`.github/workflows/ci.yml`) runs lint and tests on every pull request.

## Releases

Push a semver tag to trigger the GitHub Actions release workflow, which builds a plugin zip:

```bash
git tag v1.0.0 && git push origin v1.0.0
```

**When making changes that warrant a version bump**, update all three locations:

1. `Version:` header in `perimetre-wp-tools.php`
2. `PERIMETRE_WP_TOOLS_VERSION` constant in `perimetre-wp-tools.php`
3. **Current Version** + **Changelog** sections in `README.md`

**The release build's dependencies and actions are pinned.** It installs exactly what `composer.lock` records, and
every action in `.github/workflows/` is referenced by full commit SHA with its version in a comment. Change a
dependency or an action through a reviewed PR that updates the lock file or the SHA (Dependabot opens these weekly),
never by switching back to a version label like `@v3`. Workflows default to read-only permissions; a job that must
write declares it. A tag with a `-` (e.g. `v1.1.0-rc.1`) is published as a prerelease, which sites' update checker
ignores, so test releases are safe.

## Architecture

**Entry point:** `perimetre-wp-tools.php` — loads the autoloader, registers the GitHub update checker, then bootstraps both modules unconditionally (each module gates its own behaviour behind its enable toggle). Status' `Endpoint::activate/deactivate` are wired to the activation/deactivation hooks to flush rewrite rules.

**Admin surface** — a single **Settings → Perimetre WP Tools** menu entry (`options-general.php?page=perimetre-wp-tools`). `Status\Settings` owns the page and renders a two-tab strip (`Admin\Tabs`): **Status** and **Remote Login**. Both tabs submit to the same `perimetre-wp-tools` option group; each module registers its fields against its own internal section-page slug (`Status\Settings::SECTION_PAGE`, `RemoteLogin\Settings::SECTION_PAGE`) so `do_settings_sections()` only renders the active tab.

**`Status\Endpoint`** — when the endpoint is disabled (default) no rewrite rule is registered and no URL is claimed, so the plugin is a clean drop-in. Enabling the toggle flags rewrite rules for flushing on the next admin page load. `Status\HealthChecks` runs the DB-connection and object-cache probes for the authenticated payload.

**`RemoteLogin\Settings`** — adds the Remote Login tab: enable toggle, **enrollment key**, API key. There is no separate "Connect" button — saving the form is the single action, and the handshake fires automatically on the post-save admin page load (gated on `?tab=remote-login` so saving the Status tab never triggers one).

**The portal URL is hardcoded** (`Settings::PORTAL_URL`, `https://helm.perimetre.co`) and never read from the database. There is one Helm; making every install carry its address was a field an admin had to paste correctly on every site, for no benefit, and a typo surfaced as a connection failure that looked like a bad key. Override with `define('PERIMETRE_HELM_URL', '…')` in `wp-config.php` for local development — that escape hatch is the only reason it isn't a bare constant. The legacy `perimetre_remote_login_portal_url` option is left in `wp_options`, inert.

**Two ways in, and the enrollment key is the one to use.** `RemoteLogin\Enroll::do_enroll()` posts the portal-wide enrollment key plus this site's own `home_url()` / `blogname` to `POST {portal}/api/sites/enroll`; the portal creates (or re-registers) the Site and returns **this site's** `apiKey`, which the plugin stores in `OPTION_API_KEY`. Nothing has to exist in the portal first. Pasting the key on a site the portal already knows (matched on URL) re-registers it with a fresh key — the recovery path for a site whose stored key no longer matches, so the box deliberately stays available after the first connection. `RemoteLogin\Connect::do_connect()` is unchanged and still runs when no enrollment key was pasted and an API key is already stored.

> The enrollment key is read and `delete_option`'d in the same request (`Settings::take_enrollment_key`), success or failure: it is a live credential with no further use, and a failed one left behind would silently retry on every later save. The *success* response body carries an API key — nothing on that path may be logged.
 The REST route `/wp-json/perimetre-wp-tools/v1/remote-login` is registered by `RemoteLogin\Endpoint`, which only redirects to the Site's login page (`RemoteLogin\LoginPage`, `wp-login.php?action=perimetre_remote_login&token=…`, built on `wp_login_url()`). The login finishes there in `RemoteLogin\Auth`: HMAC verification (`RemoteLogin\Token`), the single-use callback to the portal, the user lookup, the 2FA gate (`RemoteLogin\TwoFactorGate`), then `wp_login` and `wp_set_auth_cookie`. Single-use is enforced server-side by the portal — the plugin never trusts the signature alone.

**`RemoteLogin\Health`** — registers the authenticated `/wp-json/perimetre-wp-tools/v1/health` route (`WP_REST_Server::READABLE`) on the same `Endpoint::NAMESPACE`. Its `permission_callback` compares the presented key to `Settings::get_api_key()` with `hash_equals`. The key is read from `Authorization: Bearer <key>`, or — as of v1.0.8 — from `X-Perimetre-Wp-Tools-Key` when the standard header has been claimed by an HTTP Basic auth gate in front of WordPress (a request carries only one `Authorization`, and the web server's `Basic` credentials have to travel in it). Bearer is read first and wins when both are present, so nothing changes for an ungated site (both sides must be non-empty — `hash_equals('', '')` is `true`, so an unconnected site would otherwise authenticate an empty token). A `200` returns `{ ok, enabled, connected_at, wp_version, plugin_version }` so the portal can tell a healthy connection from an unauthorized (401), disabled (`enabled:false`), or unreachable (network error / 404) site. Version details stay behind the auth check.

> **Portal contract:** the portal builds the remote-login callback URL from the `perimetre-wp-tools/v1` REST namespace (`RemoteLogin\Endpoint::NAMESPACE`). Changing it requires a coordinated portal update and a re-save/reconnect on any already-connected site.

**Remote login respects the Site's two-factor authentication, and never asks for a password.** There is no local credential, so the plugin doesn't go through `wp_authenticate` (don't route it there). Instead:

- **The login finishes on the Site's login page,** so a 2FA plugin can show its screen there. Consequence: IP rules on `wp-login.php` apply to portal logins; "hide login" plugins keep working because the URL comes from `wp_login_url()`.
- **`TwoFactorGate` runs before any session exists** and refuses the portal login, with a message on the login page, when a known 2FA plugin that isn't in `SUPPORTED` is active, or when any plugin objects in WordPress's `authenticate` filter for this person (run with their username; there is no password to pass). Security suites with optional 2FA (Wordfence, Solid Security) are deliberately not on the known list: they're judged per person. CAPTCHA and lockout plugins on that filter refuse portal logins too — the fail-safe choice.
- **`wp_login` fires before the session cookie, on purpose** (core does the reverse). A supported 2FA plugin (WP Umbrella) takes over during the event and creates the session itself after a valid code; a plugin that clears the cookie during the event gets no session; one that crashes leaves none. Audit-logging plugins still record the login through this event.
- **Adding support for another 2FA plugin** means adding its folder to `TwoFactorGate::SUPPORTED` after checking it end to end: its code screen appears after a portal login, and only a valid code creates a session.

The tradeoff is stated on the Remote Login settings tab, since that's the screen where a site owner turns the feature on.

The auth cookie's `Secure` flag comes from `Auth::is_secure_request()`, not bare `is_ssl()` — see the docblock there; `is_ssl()` is false behind a TLS-terminating proxy unless wp-config.php derives it from `X-Forwarded-Proto`, which silently produced non-Secure session cookies before v1.0.7.

## Coding Standards

- **PSR-12** style, **PSR-4** autoloading under the `Perimetre\WpTools\` namespace, mapped to `src/`
- `declare(strict_types=1)` in every PHP file
- `vendor/` is committed (contains only the generated autoloader)
- No anonymous functions on WordPress hooks — use named methods or class methods

## Relationship to Perimetre Core

These two plugins are independent and can be installed together (e.g. on a custom headless build) or separately. They share no code and no option group. Option *keys* (`perimetre_status_*`, `perimetre_remote_login_*`) are identical to those Core used through v1.15.0, so settings migrate automatically when swapping combined-Core for Core + WP Tools.
