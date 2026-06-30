<?php

declare(strict_types=1);

namespace Perimetre\WpTools\RemoteLogin;

/**
 * Performs the portal handshake. Invoked exclusively by
 * `Settings::maybe_auto_connect` on the post-save admin page load. There is
 * deliberately no admin-post button: the only way to trigger this is to
 * save the settings form, which guarantees the handshake always runs
 * against currently persisted options (not stale ones).
 */
final class Connect
{
    /**
     * Posts to {portalUrl}/api/sites/connect with the API key as a Bearer
     * token. Returns the resulting notice key.
     *
     * @return 'connected'|'failed'|'missing'
     */
    public static function do_connect(): string
    {
        $portal_url = Settings::get_portal_url();
        $api_key    = Settings::get_api_key();
        if ($portal_url === '' || $api_key === '') {
            return 'missing';
        }

        $body = wp_json_encode([
            'siteUrl'   => home_url('/'),
            'wpVersion' => get_bloginfo('version'),
            'nonce'     => wp_generate_password(16, false),
            'ts'        => time(),
        ]);
        if (! is_string($body)) {
            return 'failed';
        }

        $response = wp_remote_post(
            $portal_url . '/api/sites/connect',
            [
                'method'      => 'POST',
                'headers'     => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'body'        => $body,
                'timeout'     => 10,
                'redirection' => 0,
                'blocking'    => true,
            ]
        );

        if (is_wp_error($response)) {
            error_log('[perimetre-wp-tools/remote-login] connect failed: ' . $response->get_error_message());
            return 'failed';
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log(sprintf(
                '[perimetre-wp-tools/remote-login] connect failed: HTTP %d body=%s',
                $code,
                substr((string) wp_remote_retrieve_body($response), 0, 500)
            ));
            return 'failed';
        }

        Settings::mark_connected();
        return 'connected';
    }
}
