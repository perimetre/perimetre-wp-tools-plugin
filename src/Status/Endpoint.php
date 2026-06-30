<?php

declare(strict_types=1);

namespace Perimetre\WpTools\Status;

/**
 * Registers the rewrite rule and handles requests for the status endpoint.
 */
final class Endpoint
{
    public const QUERY_VAR = 'perimetre_status';

    public static function register(): void
    {
        // Only claim the URL path when the endpoint is enabled.
        if (Settings::is_enabled()) {
            add_action('init', [self::class, 'add_rewrite_rule']);
            add_filter('query_vars', [self::class, 'add_query_var']);
        }

        add_action('template_redirect', [self::class, 'handle_request']);
        add_action('admin_init', [self::class, 'maybe_flush_rewrite_rules']);
    }

    /**
     * Flush rewrite rules on plugin activation if the endpoint is enabled.
     * Fresh installs default to disabled, so this is a no-op until the
     * setting is turned on (which sets the flush flag itself).
     */
    public static function activate(): void
    {
        if (! Settings::is_enabled()) {
            return;
        }

        // Rewrite rule isn't registered yet at activation time,
        // so register it before flushing.
        self::add_rewrite_rule();
        flush_rewrite_rules();
    }

    public static function add_rewrite_rule(): void
    {
        $slug = Settings::get_slug();
        add_rewrite_rule(
            '^' . preg_quote($slug, '/') . '/?$',
            'index.php?' . self::QUERY_VAR . '=1',
            'top'
        );
    }

    /**
     * @param list<string> $vars
     * @return list<string>
     */
    public static function add_query_var(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function handle_request(): void
    {
        if (! get_query_var(self::QUERY_VAR)) {
            return;
        }

        if (! Settings::is_enabled()) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
            echo esc_html__('404 Not Found', 'perimetre-wp-tools');
            exit;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public endpoint, no nonce
        $provided_token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $stored_token = Settings::get_token();

        $authenticated = $stored_token !== '' && $provided_token !== '' && hash_equals($stored_token, $provided_token);

        if ($authenticated) {
            $payload = HealthChecks::run_all();
            $status_code = $payload['status'] === 'ok' ? 200 : 500;
        } else {
            $payload = ['status' => 'ok'];
            $status_code = 200;
        }

        nocache_headers();
        status_header($status_code);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Flush rewrite rules when needed. Runs on every admin page load.
     *
     * Two triggers:
     *   1. The explicit flag set when the enable toggle or slug changes.
     *   2. Self-heal: the endpoint is enabled but its rule is missing from the
     *      persisted rewrite table. This covers cases the one-shot flag misses —
     *      enabling via WP-CLI/`update_option` (no flag set), or a flag consumed
     *      on a request where the rule wasn't yet registered — which otherwise
     *      leave `/{slug}/` returning a 404 until a manual permalink flush.
     */
    public static function maybe_flush_rewrite_rules(): void
    {
        $needs_flush = (bool) get_option('perimetre_status_flush_rewrite');

        if (! $needs_flush && Settings::is_enabled() && ! self::rule_is_registered()) {
            $needs_flush = true;
        }

        if ($needs_flush) {
            delete_option('perimetre_status_flush_rewrite');
            flush_rewrite_rules();
        }
    }

    /**
     * Whether the current status rewrite rule is present in the persisted
     * rewrite table (so we don't flush on every admin request once it is).
     */
    private static function rule_is_registered(): bool
    {
        $rules = get_option('rewrite_rules');
        if (! is_array($rules)) {
            return false;
        }
        $pattern = '^' . preg_quote(Settings::get_slug(), '/') . '/?$';

        return isset($rules[$pattern]);
    }

    /**
     * Clean up on plugin deactivation.
     */
    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }
}
