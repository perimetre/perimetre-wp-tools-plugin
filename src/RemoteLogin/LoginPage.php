<?php

declare(strict_types=1);

namespace Perimetre\WpTools\RemoteLogin;

use WP_Error;

/**
 * Where a portal login finishes: a dedicated action on the Site's own login
 * page (`wp-login.php?action=perimetre_remote_login&token=…`).
 *
 * Finishing here, rather than inside the REST route, is what lets a 2FA plugin
 * that hooks `wp_login` show its code screen: the login page is an HTML page
 * with WordPress's login styles, where a REST response is JSON.
 */
final class LoginPage
{
    public const ACTION = 'perimetre_remote_login';

    /** Query argument carrying why a portal login was refused (see TwoFactorGate). */
    public const REFUSED_ARG = 'perimetre_remote_login_refused';

    public static function register(): void
    {
        add_action('login_form_' . self::ACTION, [self::class, 'handle']);
        add_filter('wp_login_errors', [self::class, 'add_refusal_message']);
    }

    /**
     * The login-page action. Everything is decided by Auth::handle, which always
     * ends in a redirect (or in a 2FA plugin's screen).
     */
    public static function handle(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the signed, single-use token is the credential
        $token = isset($_GET['token']) && is_string($_GET['token']) ? wp_unslash($_GET['token']) : '';
        Auth::handle($token);
    }

    /** The login-page URL for a token. */
    public static function url(string $token): string
    {
        return self::login_url_with(['action' => self::ACTION, 'token' => $token]);
    }

    /** The login page, showing why a portal login was refused. */
    public static function refused_url(string $reason): string
    {
        return self::login_url_with([self::REFUSED_ARG => $reason]);
    }

    /**
     * Shows the refusal on the login page. Only reasons TwoFactorGate knows
     * produce a message, so nothing from the URL is ever echoed.
     */
    public static function add_refusal_message(WP_Error $errors): WP_Error
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag
        $reason = isset($_GET[self::REFUSED_ARG]) && is_string($_GET[self::REFUSED_ARG])
            ? sanitize_key($_GET[self::REFUSED_ARG])
            : '';
        if ($reason === '') {
            return $errors;
        }

        $message = TwoFactorGate::describe($reason);
        if ($message !== null) {
            $errors->add(self::REFUSED_ARG, esc_html($message));
        }

        return $errors;
    }

    /**
     * Built on `wp_login_url()`, so plugins that rename or hide the login page
     * keep working; their URL may already carry a query string.
     *
     * @param array<string, string> $args
     */
    private static function login_url_with(array $args): string
    {
        $url = wp_login_url();
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
    }
}
