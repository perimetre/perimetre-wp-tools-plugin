<?php

declare(strict_types=1);

namespace Perimetre\WpTools\Status;

/**
 * Individual health checks for the status endpoint.
 */
final class HealthChecks
{
    /**
     * Run all checks and return the full payload array.
     *
     * @return array<string, mixed>
     */
    public static function run_all(): array
    {
        $failing = [];

        $db = self::check_db();
        if ($db !== 'ok') {
            $failing[] = 'db';
        }

        $cache = self::check_cache();
        if ($cache !== 'ok' && $cache !== 'disabled') {
            $failing[] = 'cache';
        }

        $payload = [
            'status' => $failing === [] ? 'ok' : 'error',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'db' => $db,
            'cache' => $cache,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugin_version' => PERIMETRE_WP_TOOLS_VERSION,
        ];

        if ($failing !== []) {
            $payload['failing'] = $failing;
        }

        return $payload;
    }

    public static function check_db(): string
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        return $wpdb->check_connection(false) ? 'ok' : 'error';
    }

    public static function check_cache(): string
    {
        if (! wp_using_ext_object_cache()) {
            return 'disabled';
        }

        $key = 'perimetre_status_probe_' . wp_rand();
        $value = 'probe_' . time();

        wp_cache_set($key, $value, 'perimetre_status');
        $retrieved = wp_cache_get($key, 'perimetre_status');
        wp_cache_delete($key, 'perimetre_status');

        return $retrieved === $value ? 'ok' : 'error';
    }
}
