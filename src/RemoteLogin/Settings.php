<?php

declare(strict_types=1);

namespace Perimetre\WpTools\RemoteLogin;

use Perimetre\WpTools\Admin\Tabs;

/**
 * Adds the "Remote Login" tab to the Settings > Perimetre WP Tools admin
 * page (owned by Status\Settings).
 *
 * Saving the form is the single "do everything" action: WP persists the
 * options, then we POST to the portal's /api/sites/connect on the same
 * round-trip and surface the result as an admin notice. There is no
 * separate Connect button — that split caused stale-value bugs where the
 * button ran the handshake against the previously stored credentials
 * because the user hadn't saved the form yet.
 */
final class Settings
{
    public const OPTION_ENABLED      = 'perimetre_remote_login_enabled';
    public const OPTION_PORTAL_URL   = 'perimetre_remote_login_portal_url';
    public const OPTION_API_KEY      = 'perimetre_remote_login_api_key';
    public const OPTION_CONNECTED_AT = 'perimetre_remote_login_connected_at';

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

        register_setting(self::SECTION_PAGE, self::OPTION_PORTAL_URL, [
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => [self::class, 'sanitize_url'],
        ]);
        add_settings_field(
            self::OPTION_PORTAL_URL,
            __('Portal URL', 'perimetre-wp-tools'),
            [self::class, 'render_portal_url_field'],
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
                'Create a Site in the portal, copy the API key it shows, paste it here, then click Save. ' .
                'The plugin will connect to the portal automatically.',
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

    public static function render_portal_url_field(): void
    {
        $value = self::get_portal_url();
        printf(
            '<input type="url" name="%s" value="%s" class="regular-text" placeholder="https://helm.example.com" />',
            esc_attr(self::OPTION_PORTAL_URL),
            esc_attr($value)
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
                'Sensitive. Stored in wp_options. The portal shows the key exactly once on Site creation — regenerate the Site in the portal if lost.',
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
                esc_html__('Saving will re-run the handshake automatically.', 'perimetre-wp-tools') .
                '</p>';
        } else {
            echo '<span style="color:#6b7280;font-size:14px;">○ </span>';
            echo esc_html__('Not yet connected. Click Save Changes to connect.', 'perimetre-wp-tools');
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
        if (self::get_portal_url() === '' || self::get_api_key() === '') {
            set_transient(self::AUTO_NOTICE_KEY, 'missing', 60);
            return;
        }

        $result = Connect::do_connect();
        set_transient(self::AUTO_NOTICE_KEY, $result, 60);
    }

    public static function maybe_render_notices(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->id !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }

        $stored = get_transient(self::AUTO_NOTICE_KEY);
        if (! is_string($stored) || $stored === '') {
            return;
        }
        delete_transient(self::AUTO_NOTICE_KEY);

        $messages = [
            'connected' => [
                'class' => 'notice-success',
                'text'  => __('Connected to the Helm portal.', 'perimetre-wp-tools'),
            ],
            'failed'    => [
                'class' => 'notice-error',
                'text'  => __('Connection to the Helm portal failed. Check the portal URL and API key, then save again.', 'perimetre-wp-tools'),
            ],
            'missing'   => [
                'class' => 'notice-warning',
                'text'  => __('Remote login is enabled but the portal URL or API key is missing.', 'perimetre-wp-tools'),
            ],
        ];
        if (! isset($messages[$stored])) {
            return;
        }
        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr($messages[$stored]['class']),
            esc_html($messages[$stored]['text'])
        );
    }

    public static function sanitize_bool(mixed $value): bool
    {
        return (bool) $value;
    }

    public static function sanitize_url(mixed $value): string
    {
        $url = trim((string) $value);
        return $url === '' ? '' : esc_url_raw($url);
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

    public static function get_portal_url(): string
    {
        return rtrim((string) get_option(self::OPTION_PORTAL_URL, ''), '/');
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
