<?php

declare(strict_types=1);

namespace Perimetre\WpTools\RemoteLogin;

/**
 * Orchestrates the remote-login flow:
 *   1. Verify the token's HMAC + expiry locally with the stored apiKey.
 *   2. POST the jti back to the portal (Authorization: Bearer apiKey) to
 *      atomically claim it (single-use).
 *   3. Look up the WP user by the email the portal returned.
 *   4. Set the auth cookie and redirect to wp-admin.
 *
 * Any failure short-circuits to wp-login.php with no error leak — we never
 * tell the caller why the attempt failed.
 */
final class Auth
{
    public static function handle(string $token): void
    {
        if (! Settings::is_enabled()) {
            self::fail();
        }

        $api_key    = Settings::get_api_key();
        $portal_url = Settings::get_portal_url();
        if ($api_key === '' || $portal_url === '') {
            self::fail();
        }

        $payload = Token::verify($token, $api_key);
        if ($payload === null) {
            self::fail();
        }

        $email = self::consume_at_portal($portal_url, $api_key, $payload['jti']);
        if ($email === null) {
            self::fail();
        }

        $user = get_user_by('email', $email);
        if (! $user) {
            // No matching local user. Per spec, we never auto-create users.
            self::fail();
        }

        wp_clear_auth_cookie();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, self::is_secure_request());
        do_action('wp_login', $user->user_login, $user);

        wp_safe_redirect(admin_url());
        exit;
    }

    /**
     * Whether the auth cookie may be marked `Secure`.
     *
     * `is_ssl()` alone is not enough here. It reads `$_SERVER['HTTPS']` /
     * `SERVER_PORT`, so on a site behind a TLS-terminating proxy — Cloudflare, a
     * load balancer, most managed hosts — it returns false unless wp-config.php
     * has been set up to derive it from `X-Forwarded-Proto`. The cookie was then
     * issued without `Secure`, meaning the browser would attach a live admin
     * session to any plain-HTTP request to the site: one downgraded asset,
     * typed URL, or on-path redirect is enough to leak it.
     *
     * A remote login always originates from the portal over HTTPS, so the
     * forwarded header is trusted here the same way WordPress core trusts it
     * once `$_SERVER['HTTPS']` is set. Falling back to the site's own home URL
     * covers the proxy that forwards no protocol header at all.
     */
    private static function is_secure_request(): bool
    {
        if (is_ssl()) {
            return true;
        }

        $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        // A proxy chain can send a comma-separated list; the first entry is the
        // scheme the client actually used.
        if ($forwarded !== '' && str_starts_with($forwarded, 'https')) {
            return true;
        }

        if (strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''))) === 'on') {
            return true;
        }

        // Last resort: if the site itself is configured as https, a cookie
        // without Secure is wrong regardless of how this request arrived.
        return str_starts_with(strtolower((string) home_url()), 'https://');
    }

    /**
     * Calls the portal's /api/remote-login/consume endpoint, which atomically
     * marks the jti used and returns the associated email. Returns null on
     * any non-success response.
     */
    private static function consume_at_portal(
        string $portal_url,
        string $api_key,
        string $jti
    ): ?string {
        $body = wp_json_encode(['jti' => $jti]);
        if (! is_string($body)) {
            return null;
        }

        $response = wp_remote_post(
            $portal_url . '/api/remote-login/consume',
            [
                'method'      => 'POST',
                'headers'     => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'body'        => $body,
                'timeout'     => 5,
                'redirection' => 0,
                'blocking'    => true,
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($decoded) || ! isset($decoded['email']) || ! is_string($decoded['email'])) {
            return null;
        }
        return $decoded['email'];
    }

    /**
     * Fallthrough: redirect to wp-login.php with no error parameter. Using a
     * named method (not anonymous) per the plugin's no-anonymous-hooks rule.
     */
    private static function fail(): never
    {
        nocache_headers();
        wp_safe_redirect(wp_login_url());
        exit;
    }
}
