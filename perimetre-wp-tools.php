<?php

/**
 * Plugin Name: Perimetre WP Tools
 * Description: Status / health-check endpoint and Helm portal remote login for Perimetre WordPress sites.
 * Version: 1.0.0
 * Author: Perimetre
 * Author URI: https://perimetre.co
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: perimetre-wp-tools
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('PERIMETRE_WP_TOOLS_VERSION', '1.0.0');
define('PERIMETRE_WP_TOOLS_FILE', __FILE__);
define('PERIMETRE_WP_TOOLS_PATH', plugin_dir_path(__FILE__));
define('PERIMETRE_WP_TOOLS_URL', plugin_dir_url(__FILE__));

require_once PERIMETRE_WP_TOOLS_PATH . 'vendor/autoload.php';

use Perimetre\WpTools\Plugin;
use Perimetre\WpTools\RemoteLogin\Endpoint as RemoteLoginEndpoint;
use Perimetre\WpTools\RemoteLogin\Settings as RemoteLoginSettings;
use Perimetre\WpTools\Status\Endpoint as StatusEndpoint;
use Perimetre\WpTools\Status\Settings as StatusSettings;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Enable auto-updates from GitHub Releases.
 */
$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/perimetre/perimetre-wp-tools-plugin/',
    __FILE__,
    'perimetre-wp-tools'
);
/** @phpstan-ignore method.notFound */
$updateChecker->getVcsApi()->enableReleaseAssets();

/**
 * Load plugin translations.
 */
add_action('init', [Plugin::class, 'load_textdomain'], 1);

/**
 * Bootstrap status endpoint settings and rewrite rule.
 */
StatusSettings::register();
StatusEndpoint::register();

/**
 * Bootstrap remote-login settings and REST endpoint. The portal handshake
 * runs automatically after every settings save — there is no separate
 * Connect action class.
 */
RemoteLoginSettings::register();
RemoteLoginEndpoint::register();

/**
 * Schedule cron and flush rewrite rules on activation.
 */
register_activation_hook(__FILE__, [StatusEndpoint::class, 'activate']);

/**
 * Clean up status cron and rewrite rules on deactivation.
 */
register_deactivation_hook(__FILE__, [StatusEndpoint::class, 'deactivate']);
