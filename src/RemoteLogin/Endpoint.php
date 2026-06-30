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
 * Handled entirely by Auth::handle.
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

    public static function handle(WP_REST_Request $request): void
    {
        $token = (string) $request->get_param('token');
        Auth::handle($token);
    }
}
