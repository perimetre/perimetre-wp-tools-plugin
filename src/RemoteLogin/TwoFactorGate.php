<?php

declare(strict_types=1);

namespace Perimetre\WpTools\RemoteLogin;

use WP_User;

/**
 * Decides, before any session exists, whether a portal login may go ahead on
 * this Site given its two-factor setup. Fails closed: a 2FA the portal login
 * can't honour refuses the login rather than skipping the second factor.
 *
 * Two checks:
 *
 *  1. **Known 2FA plugins.** A plugin on the list but not supported refuses
 *     the login whenever it's active. Supported plugins run their 2FA on the
 *     `wp_login` event, which Auth fires before creating the session, so the
 *     person gets the plugin's own code screen.
 *  2. **The `authenticate` check, per person.** Security suites whose 2FA is an
 *     optional module (Wordfence, Solid Security) aren't on the list. Those
 *     that check the code in WordPress's `authenticate` filter (Wordfence) only
 *     do so for people who turned 2FA on. Running that filter on the
 *     already-identified user, with their real username, lets them object.
 *     Core's own checks pass an existing user through, so any error (2FA code,
 *     CAPTCHA, lockout) means some plugin wants more than identity, and the
 *     login is refused. Limit: there is no password to pass, so a plugin that
 *     only checks when a password is present won't object here.
 *
 * Suites and unlisted plugins that run their 2FA on the `wp_login` event are
 * handled by Auth: it fires that event before creating the session, and
 * creates none if a plugin clears the auth cookie during it.
 */
final class TwoFactorGate
{
    /**
     * Reason given when a plugin objected in the `authenticate` check.
     */
    public const LOGIN_CHECK = 'login_check';

    /**
     * Plugins that provide two-factor login, keyed by plugin folder.
     */
    public const KNOWN_2FA_PLUGINS = [
        'wp-health'                          => 'WP Umbrella',
        'wordfence-login-security'           => 'Wordfence Login Security',
        'two-factor'                         => 'Two Factor',
        'wp-2fa'                             => 'WP 2FA',
        'miniorange-2-factor-authentication' => 'miniOrange 2-Factor Authentication',
        'two-factor-authentication'          => 'Two Factor Authentication',
        'google-authenticator'               => 'Google Authenticator',
    ];

    /**
     * Known 2FA plugins whose 2FA works with a portal login. Adding one here
     * means it was checked end to end: its code screen appears on the login
     * page after a portal login, and only a valid code creates a session.
     */
    public const SUPPORTED = ['wp-health'];

    /**
     * Why the portal login must be refused, or null when it may go ahead.
     */
    public static function refusal(WP_User $user): ?string
    {
        foreach (self::active_plugin_folders() as $folder) {
            if (isset(self::KNOWN_2FA_PLUGINS[$folder]) && ! in_array($folder, self::SUPPORTED, true)) {
                return $folder;
            }
        }

        $checked = apply_filters('authenticate', $user, $user->user_login, '');
        if (! $checked instanceof WP_User || $checked->ID !== $user->ID) {
            return self::LOGIN_CHECK;
        }

        return null;
    }

    /**
     * The message shown on the login page for a refusal reason, or null for a
     * reason this class didn't produce.
     */
    public static function describe(string $reason): ?string
    {
        if ($reason === self::LOGIN_CHECK) {
            return __(
                'Helm can’t sign you in on this site: a plugin here asks for an extra login step, such as two-factor authentication, that Helm logins don’t support yet.', // phpcs:ignore Generic.Files.LineLength.TooLong -- translatable string kept whole for extraction
                'perimetre-wp-tools'
            );
        }

        if (isset(self::KNOWN_2FA_PLUGINS[$reason])) {
            return sprintf(
                /* translators: %s: name of the site's two-factor authentication plugin */
                __(
                    'Helm can’t sign you in on this site: it uses %s for two-factor authentication, which Helm logins don’t support yet.', // phpcs:ignore Generic.Files.LineLength.TooLong -- translatable string kept whole for extraction
                    'perimetre-wp-tools'
                ),
                self::KNOWN_2FA_PLUGINS[$reason]
            );
        }

        return null;
    }

    /**
     * Folders of the active plugins, including network-activated ones.
     *
     * @return list<string>
     */
    private static function active_plugin_folders(): array
    {
        $basenames = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $basenames = array_merge($basenames, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }

        $folders = [];
        foreach ($basenames as $basename) {
            $folders[] = explode('/', (string) $basename)[0];
        }

        return $folders;
    }
}
