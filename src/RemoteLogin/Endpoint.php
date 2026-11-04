<?php

declare(strict_types=1);

namespace Perimetre\WpTools\RemoteLogin;

use WP_REST_Request;
use WP_REST_Server;

/**
 * Registers the public REST route the portal redirects users to.
 *
 *   GET /wp-json/perimetre-wp-tools/v1/remote-login?token=<signed token>
 *
 * It only hands the token on to the Site's login page (LoginPage), where
 * Auth::handle finishes the login.
 */
final class Endpoint
{
    public const NAMESPACE = 'perimetre-wp-tools/v1';
    public const ROUTE     = '/remote-login';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            self::ROUTE,
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [self::class, 'handle'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'token' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ]
        );
    }

    /**
     * Hands the token to the Site's login page, where the login finishes
     * (see LoginPage). Logs nobody in here: a 2FA plugin needs the login page
     * to show its screen. Only the token is forwarded, never other request
     * parameters, so the route can't be used to redirect anywhere else.
     */
    public static function handle(WP_REST_Request $request): never
    {
        $token = (string) $request->get_param('token');

        nocache_headers();
        wp_safe_redirect(LoginPage::url($token));
        exit;
    }
}
