<?php
/**
 * Plugin Name:       Newss
 * Description:       Pollt YouTube-Kanäle und erstellt automatisch Artikel aus Video-Transkripten via Anthropic Claude.
 * Version:           0.1.0
 * Requires PHP:      8.1
 * Requires at least: 6.4
 * Author:            Newss
 * Text Domain:       newss
 * License:           proprietary
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('NEWSS_VERSION', '0.1.0');
define('NEWSS_FILE', __FILE__);
define('NEWSS_DIR', __DIR__);
define('NEWSS_URL', plugin_dir_url(__FILE__));

$autoload = NEWSS_DIR . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>Newss:</strong> Composer-Abhängigkeiten fehlen. Im Plugin-Verzeichnis <code>composer install</code> ausführen.</p></div>';
    });
    return;
}
require $autoload;

register_activation_hook(__FILE__, [\Newss\Plugin::class, 'onActivate']);
register_deactivation_hook(__FILE__, [\Newss\Plugin::class, 'onDeactivate']);

add_action('plugins_loaded', static function (): void {
    \Newss\Plugin::instance()->boot();
});
