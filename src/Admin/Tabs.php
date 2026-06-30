<?php

declare(strict_types=1);

namespace Perimetre\WpTools\Admin;

/**
 * Renders the shared tab strip that ties the Perimetre WP Tools admin
 * surface together. Two tabs:
 *
 *   - status        → options-general.php?page=perimetre-wp-tools (default tab)
 *   - remote-login  → options-general.php?page=perimetre-wp-tools&tab=remote-login
 */
final class Tabs
{
    public const TAB_STATUS       = 'status';
    public const TAB_REMOTE_LOGIN = 'remote-login';

    public static function render(string $active): void
    {
        $tabs = [
            self::TAB_STATUS => [
                'label' => __('Status', 'perimetre-wp-tools'),
                'url'   => admin_url('options-general.php?page=perimetre-wp-tools'),
            ],
            self::TAB_REMOTE_LOGIN => [
                'label' => __('Remote Login', 'perimetre-wp-tools'),
                'url'   => admin_url('options-general.php?page=perimetre-wp-tools&tab=remote-login'),
            ],
        ];

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key => $tab) {
            $class = 'nav-tab' . ($key === $active ? ' nav-tab-active' : '');
            printf(
                '<a href="%s" class="%s">%s</a>',
                esc_url($tab['url']),
                esc_attr($class),
                esc_html($tab['label'])
            );
        }
        echo '</h2>';
    }
}
