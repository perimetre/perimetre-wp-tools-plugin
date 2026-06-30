<?php

declare(strict_types=1);

namespace Perimetre\WpTools;

/**
 * Top-level plugin bootstrap helpers.
 */
final class Plugin
{
    public static function load_textdomain(): void
    {
        load_plugin_textdomain('perimetre-wp-tools', false, dirname(plugin_basename(PERIMETRE_WP_TOOLS_FILE)) . '/languages');
    }
}
