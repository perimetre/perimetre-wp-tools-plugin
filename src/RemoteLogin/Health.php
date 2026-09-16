<?php

declare(strict_types=1);

namespace Perimetre\WpTools\RemoteLogin;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Authenticated health-check REST route the portal polls to confirm remote
 * login is still available on a connected site.
 *
 *   GET /wp-json/perimetre-wp-tools/v1/health
 *   Authorization: Bearer <api key>
 *
 * Sites sitting behind an HTTP Basic auth gate (a staging `.htpasswd`) cannot
 * use that header: the web server demands `Authorization: Basic …` of its own,
 * and a request carries only one `Authorization`. Those callers send the key in
 * `X-Perimetre-Wp-Tools-Key` instead (see {@see self::API_KEY_HEADER}).
 *
 * A 200 proves the shared secret still matches (so a login would succeed for a
 * matching WP user); the payload's `enabled` flag lets the portal distinguish a
 * turned-off feature from a healthy one. A key mismatch yields 401; a missing
 * or inactive plugin yields a network error / 404 the portal reads as
 * unreachable.
 */
final class Health
{
    public const ROUTE = '/health';

    /**
     * Fallback header carrying the API key when `Authorization` is already
     * spoken for by an HTTP Basic auth gate in front of WordPress.
     *
     * The gate is enforced by the web server, before PHP runs, and it insists on
     * `Authorization: Basic …`. There is no second `Authorization` to put the
     * Bearer token in, so the portal moves the key here and lets the edge keep
     * the standard header. Accepted unconditionally rather than only when Basic
     * credentials are present: it carries the same secret, compared the same way,
     * so gating it on the request's shape would add a branch without adding a
     * check.
     */
    public const API_KEY_HEADER = 'X-Perimetre-Wp-Tools-Key';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route(
            Endpoint::NAMESPACE,
            self::ROUTE,
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [self::class, 'handle'],
                'permission_callback' => [self::class, 'check_auth'],
            ]
        );
    }

    /**
     * Read the presented API key from either accepted location: the usual
     * `Authorization: Bearer <key>`, or {@see self::API_KEY_HEADER} when the
     * standard header has been taken over by an HTTP Basic auth gate.
     *
     * Bearer wins when both are present, so nothing changes for a site without a
     * gate. Returns '' when neither carries a usable value; the caller treats
     * that as a failed comparison rather than as an empty match.
     */
    private static function extract_api_key(WP_REST_Request $request): string
    {
        $header = (string) $request->get_header('authorization');
        if (preg_match('/^\s*Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        return trim((string) $request->get_header(self::API_KEY_HEADER));
    }

    /**
     * Authorize the caller by comparing the presented key against the stored
     * API key. Both sides must be non-empty before the constant-time compare —
     * hash_equals('', '') is true, so an unconnected site (no key) would
     * otherwise authenticate an empty token.
     *
     * Note this runs only if the request reached WordPress at all: a Basic auth
     * gate answers 401 by itself, before PHP, and the portal reads that as a
     * separate status from the one returned here.
     *
     * @return true|WP_Error
     */
    public static function check_auth(WP_REST_Request $request): bool|WP_Error
    {
        $provided = self::extract_api_key($request);
        $stored   = Settings::get_api_key();

        if ($stored === '' || $provided === '' || ! hash_equals($stored, $provided)) {
            return new WP_Error(
                'perimetre_wp_tools_unauthorized',
                __('Invalid API key.', 'perimetre-wp-tools'),
                ['status' => 401]
            );
        }

        return true;
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Prevent a CDN/proxy from serving a stale `enabled` state.
        nocache_headers();

        return new WP_REST_Response(
            [
                'ok'             => true,
                'enabled'        => Settings::is_enabled(),
                'connected_at'   => Settings::get_connected_at(),
                'wp_version'     => get_bloginfo('version'),
                'plugin_version' => PERIMETRE_WP_TOOLS_VERSION,
            ],
            200
        );
    }
}
