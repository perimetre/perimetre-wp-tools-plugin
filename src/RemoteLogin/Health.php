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
 * A 200 proves the shared secret still matches (so a login would succeed for a
 * matching WP user); the payload's `enabled` flag lets the portal distinguish a
 * turned-off feature from a healthy one. A key mismatch yields 401; a missing
 * or inactive plugin yields a network error / 404 the portal reads as
 * unreachable.
 */
final class Health
{
    public const ROUTE = '/health';

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
     * Authorize the caller by comparing the Bearer token against the stored
     * API key. Both sides must be non-empty before the constant-time compare —
     * hash_equals('', '') is true, so an unconnected site (no key) would
     * otherwise authenticate an empty token. Mirrors Status\Endpoint.
     *
     * @return true|WP_Error
     */
    public static function check_auth(WP_REST_Request $request): bool|WP_Error
    {
        $header   = (string) $request->get_header('authorization');
        $provided = preg_match('/^\s*Bearer\s+(.+)$/i', $header, $matches) ? trim($matches[1]) : '';
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
