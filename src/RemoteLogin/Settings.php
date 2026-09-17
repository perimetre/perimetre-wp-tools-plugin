<?php

declare(strict_types=1);

namespace Perimetre\WpTools\RemoteLogin;

use Perimetre\WpTools\Admin\Tabs;

/**
 * Adds the "Remote Login" tab to the Settings > Perimetre WP Tools admin
 * page (owned by Status\Settings).
 *
 * Saving the form is the single "do everything" action: WP persists the
 * options, then we call the portal on the same round-trip and surface the
 * result as an admin notice. There is no separate Connect button — that split
 * caused stale-value bugs where the button ran the handshake against the
 * previously stored credentials because the user hadn't saved the form yet.
 *
 * Two ways in, and the first is the one to use:
 *
 *   - **Enrollment key** — paste the portal-wide key from Helm's Portal
 *     settings and save. The site registers itself (`Enroll`), the portal hands
 *     back this site's own API key, and the pasted key is discarded. Works for
 *     a brand-new site and for reconnecting one that lost its credentials.
 *   - **API key** — paste a per-site key an admin copied out of the portal's
 *     Site record and save, which runs the original `Connect` handshake. Kept
 *     for sites connected before enrollment existed and as a manual fallback.
 *
 * The portal URL is NOT a setting: there is one Helm, and typing its address
 * into every site was a field to get wrong for no benefit. See `PORTAL_URL`.
 */
final class Settings
{
    public const OPTION_ENABLED        = 'perimetre_remote_login_enabled';
    public const OPTION_API_KEY        = 'perimetre_remote_login_api_key';
    public const OPTION_CONNECTED_AT   = 'perimetre_remote_login_connected_at';
    public const OPTION_ENROLLMENT_KEY = 'perimetre_remote_login_enrollment_key';

    /**
     * The Helm portal. Hardcoded because there is exactly one, forever: making
     * every site carry its address was a field an admin had to paste correctly
     * on every install, for no benefit, and getting it wrong produced a
     * connection failure that looked like a bad key.
     *
     * Overridable with `define('PERIMETRE_HELM_URL', 'http://localhost:3000')`
     * in wp-config.php — needed to develop against a local portal or a preview
     * deployment, and the only reason this isn't a bare constant.
     *
     * The legacy `perimetre_remote_login_portal_url` option is no longer read.
     * It is left in wp_options rather than deleted: it is inert, and an upgrade
     * that silently drops data an admin typed is worse than a stale row.
     */
    public const PORTAL_URL = 'https://helm.perimetre.co';

    /**
     * Slug used both as this tab's `do_settings_sections` page (lets
     * Status\Settings render this tab's fields in isolation) AND as its
     * dedicated option group. A per-tab option group is what keeps the two
     * tabs independent: submitting the Status tab can never null out the
     * Remote Login values (enabled checkbox, portal URL), and vice versa.
     */
    public const SECTION_PAGE = 'perimetre-wp-tools-remote-login';

    private const PAGE_SLUG       = 'perimetre-wp-tools';
    private const SECTION_ID      = 'perimetre_remote_login_section';
    private const AUTO_NOTICE_KEY = 'perimetre_remote_login_auto_notice';

    public static function register(): void
    {
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_init', [self::class, 'maybe_auto_connect'], 20);
        add_action('admin_notices', [self::class, 'maybe_render_notices']);
    }

    public static function register_settings(): void
    {
        add_settings_section(
            self::SECTION_ID,
            __('Remote Login', 'perimetre-wp-tools'),
            [self::class, 'render_section_description'],
            self::SECTION_PAGE
        );

        register_setting(self::SECTION_PAGE, self::OPTION_ENABLED, [
            'type'              => 'boolean',
            'default'           => false,
            'sanitize_callback' => [self::class, 'sanitize_bool'],
        ]);
        add_settings_field(
            self::OPTION_ENABLED,
            __('Enable remote login', 'perimetre-wp-tools'),
            [self::class, 'render_enabled_field'],
            self::SECTION_PAGE,
            self::SECTION_ID
        );

        register_setting(self::SECTION_PAGE, self::OPTION_ENROLLMENT_KEY, [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => [self::class, 'sanitize_enrollment_key'],
        ]);
        add_settings_field(
            self::OPTION_ENROLLMENT_KEY,
            __('Enrollment key', 'perimetre-wp-tools'),
            [self::class, 'render_enrollment_key_field'],
            self::SECTION_PAGE,
            self::SECTION_ID
        );

        register_setting(self::SECTION_PAGE, self::OPTION_API_KEY, [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => [self::class, 'sanitize_api_key'],
        ]);
        add_settings_field(
            self::OPTION_API_KEY,
            __('API key', 'perimetre-wp-tools'),
            [self::class, 'render_api_key_field'],
            self::SECTION_PAGE,
            self::SECTION_ID
        );

        add_settings_field(
            'perimetre_remote_login_connection_status',
            __('Connection status', 'perimetre-wp-tools'),
            [self::class, 'render_status_field'],
            self::SECTION_PAGE,
            self::SECTION_ID
        );
    }

    public static function render_section_description(): void
    {
        echo '<p>' .
            esc_html__(
                'Allow users registered in the Helm portal to sign in as their matching WP user. ' .
                'Tick Enable, paste the portal’s enrollment key below, then click Save — this site ' .
                'registers itself and there is nothing to set up in the portal first.',
                'perimetre-wp-tools'
            ) .
            '</p>';

        // Surfaced here rather than only in the README because this is the screen
        // where someone turns the feature on, and it changes the site's security
        // model: a portal login establishes the WP session directly, so it never
        // passes through the filters that login-hardening plugins hook.
        echo '<p><strong>' . esc_html__('Before enabling:', 'perimetre-wp-tools') . '</strong> ' .
            esc_html__(
                'a portal login signs the user in without going through this site’s login form. ' .
                'Two-factor prompts, login rate limiting, lockout rules and custom or hidden ' .
                'login URLs therefore do not apply to it — sign-in security for these users is ' .
                'enforced by the Helm portal instead, which is also where their accounts and ' .
                'per-site access are managed. Every remote login still fires the standard ' .
                'wp_login action, so audit-logging plugins record the session as usual.',
                'perimetre-wp-tools'
            ) .
            '</p>';
    }

    public static function render_enabled_field(): void
    {
        $enabled = self::is_enabled();
        printf(
            '<input type="checkbox" name="%s" value="1" %s />',
            esc_attr(self::OPTION_ENABLED),
            checked($enabled, true, false)
        );
    }

    public static function render_enrollment_key_field(): void
    {
        printf(
            '<input type="password" name="%s" value="" class="regular-text" autocomplete="off" placeholder="%s" />',
            esc_attr(self::OPTION_ENROLLMENT_KEY),
            esc_attr__('Paste the enrollment key from Helm', 'perimetre-wp-tools')
        );
        echo '<p class="description">' .
            esc_html__(
                'In Helm: Portal settings → Copy enrollment key. The same key works for every site. ' .
                'Paste it here to register this site — or to reconnect it if it has been ' .
                'disconnected. It is used once and then discarded; the API key below is filled in ' .
                'for you.',
                'perimetre-wp-tools'
            ) . '</p>';
        printf(
            '<p class="description">%s <code>%s</code></p>',
            esc_html__('Portal:', 'perimetre-wp-tools'),
            esc_html(self::get_portal_url())
        );
    }

    public static function render_api_key_field(): void
    {
        $stored = self::get_api_key();
        $placeholder = $stored === ''
            ? esc_attr__('Paste the API key shown by the portal', 'perimetre-wp-tools')
            : esc_attr__('(stored — leave blank to keep)', 'perimetre-wp-tools');
        printf(
            '<input type="password" name="%s" value="" class="regular-text" autocomplete="off" placeholder="%s" />',
            esc_attr(self::OPTION_API_KEY),
            $placeholder
        );
        echo '<p class="description">' .
            esc_html__(
                'Filled in automatically when you enroll. Sensitive, stored in wp_options, and ' .
                'unique to this site. Only paste one here if you are connecting the old way with ' .
                'a key copied from the portal’s Site record.',
                'perimetre-wp-tools'
            ) . '</p>';
    }

    public static function render_status_field(): void
    {
        $connected_at = self::get_connected_at();
        if ($connected_at !== '') {
            echo '<span style="color:#16a34a;font-size:14px;">● </span>';
            printf(
                /* translators: %s: timestamp the site was last connected */
                esc_html__('Connected. Last handshake: %s', 'perimetre-wp-tools'),
                esc_html($connected_at)
            );
            echo '<p class="description">' .
                esc_html__(
                    'Saving re-runs the handshake. If this site ever stops working — the portal ' .
                    'reports an API key mismatch, or these settings were lost — paste a fresh ' .
                    'enrollment key above and save to reconnect it.',
                    'perimetre-wp-tools'
                ) .
                '</p>';
        } else {
            echo '<span style="color:#6b7280;font-size:14px;">○ </span>';
            echo esc_html__(
                'Not yet connected. Paste an enrollment key above and click Save Changes.',
                'perimetre-wp-tools'
            );
        }
    }

    /**
     * Fires on the admin page that loads after a successful settings save
     * (WordPress redirects to ?settings-updated=true). Runs the portal
     * handshake against the now-persisted options and parks the result in
     * a transient for `maybe_render_notices` to display.
     *
     * Priority 20 so it runs after `register_settings` (default priority 10).
     */
    public static function maybe_auto_connect(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flag, no state mutation based on user input
        if (! isset($_GET['page']) || $_GET['page'] !== self::PAGE_SLUG) {
            return;
        }
        if (! isset($_GET['settings-updated']) || $_GET['settings-updated'] !== 'true') {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : '';
        if ($tab !== Tabs::TAB_REMOTE_LOGIN) {
            // Saving the Status tab leaves _wp_http_referer without tab=remote-login,
            // so we skip the handshake to avoid reconnecting on unrelated saves.
            return;
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if (! current_user_can('manage_options')) {
            return;
        }
        if (! self::is_enabled()) {
            return;
        }

        // An enrollment key wins when one was just pasted: it is the path that
        // works whether or not this site already has credentials, and it is the
        // only way to recover a site whose stored key no longer matches.
        $enrollment_key = self::take_enrollment_key();
        if ($enrollment_key !== '') {
            set_transient(self::AUTO_NOTICE_KEY, Enroll::do_enroll($enrollment_key), 60);
            return;
        }

        if (self::get_api_key() === '') {
            set_transient(self::AUTO_NOTICE_KEY, ['code' => 'missing', 'message' => ''], 60);
            return;
        }

        set_transient(
            self::AUTO_NOTICE_KEY,
            ['code' => Connect::do_connect(), 'message' => ''],
            60
        );
    }

    /**
     * Reads the just-saved enrollment key and deletes it in the same breath.
     *
     * It is a live credential and it is single-purpose: once the registration
     * request has been made it has no further use, so it should not sit in
     * wp_options where a database dump or an options-editor plugin would carry
     * it. Deleted whether or not the attempt succeeds — re-copying it from Helm
     * is one click, and leaving a failed key behind would silently retry it on
     * every later save.
     */
    private static function take_enrollment_key(): string
    {
        $key = trim((string) get_option(self::OPTION_ENROLLMENT_KEY, ''));
        if ($key !== '') {
            delete_option(self::OPTION_ENROLLMENT_KEY);
        }
        return $key;
    }

    public static function maybe_render_notices(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->id !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }

        $stored = get_transient(self::AUTO_NOTICE_KEY);
        if (! is_array($stored) || ! isset($stored['code']) || ! is_string($stored['code'])) {
            return;
        }
        delete_transient(self::AUTO_NOTICE_KEY);

        $code   = $stored['code'];
        $detail = isset($stored['message']) && is_string($stored['message']) ? $stored['message'] : '';

        $messages = [
            'enrolled'     => [
                'class' => 'notice-success',
                'text'  => __('Registered with the Helm portal.', 'perimetre-wp-tools'),
            ],
            'reconnected'  => [
                'class' => 'notice-success',
                'text'  => __(
                    'Reconnected to the Helm portal. This site already had a record there; ' .
                    'its API key has been replaced.',
                    'perimetre-wp-tools'
                ),
            ],
            'enroll_failed' => [
                'class' => 'notice-error',
                'text'  => __('Enrollment failed.', 'perimetre-wp-tools'),
            ],
            'connected'    => [
                'class' => 'notice-success',
                'text'  => __('Connected to the Helm portal.', 'perimetre-wp-tools'),
            ],
            'failed'       => [
                'class' => 'notice-error',
                'text'  => __(
                    'Connection to the Helm portal failed. Paste an enrollment key and save ' .
                    'to reconnect this site.',
                    'perimetre-wp-tools'
                ),
            ],
            'missing'      => [
                'class' => 'notice-warning',
                'text'  => __(
                    'Remote login is enabled but this site has no credentials yet. ' .
                    'Paste an enrollment key and save.',
                    'perimetre-wp-tools'
                ),
            ],
        ];
        if (! isset($messages[$code])) {
            return;
        }

        // The portal's own wording is appended for the failure cases — it says
        // what is actually wrong ("must use https", "not a WordPress site"),
        // which a fixed string here cannot.
        $text = $messages[$code]['text'];
        if ($detail !== '' && $code === 'enroll_failed') {
            $text .= ' ' . $detail;
        }

        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr($messages[$code]['class']),
            esc_html($text)
        );
    }

    public static function sanitize_bool(mixed $value): bool
    {
        return (bool) $value;
    }

    public static function sanitize_enrollment_key(mixed $value): string
    {
        return trim((string) $value);
    }

    /**
     * Treats an empty submitted value as "keep the existing key" so admins
     * can save unrelated settings without re-entering it.
     */
    public static function sanitize_api_key(mixed $value): string
    {
        $submitted = trim((string) $value);
        if ($submitted === '') {
            return (string) get_option(self::OPTION_API_KEY, '');
        }
        return $submitted;
    }

    public static function is_enabled(): bool
    {
        return (bool) get_option(self::OPTION_ENABLED, false);
    }

    /**
     * The portal this site talks to. Hardcoded, with a wp-config override for
     * local development — see `PORTAL_URL`. Never read from the database, so a
     * stale option from an older install can't point a site at the wrong host.
     */
    public static function get_portal_url(): string
    {
        $url = defined('PERIMETRE_HELM_URL') ? (string) constant('PERIMETRE_HELM_URL') : self::PORTAL_URL;
        return rtrim(trim($url), '/');
    }

    public static function get_api_key(): string
    {
        return (string) get_option(self::OPTION_API_KEY, '');
    }

    public static function get_connected_at(): string
    {
        return (string) get_option(self::OPTION_CONNECTED_AT, '');
    }

    public static function mark_connected(): void
    {
        update_option(self::OPTION_CONNECTED_AT, current_time('mysql'));
    }
}
