<?php
/**
 * Nachrichtenblatt Child Theme — Bootstrap.
 *
 * @package NachrichtenblattChild
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('NBC_VERSION', '1.0.0');
define('NBC_DIR', __DIR__);
define('NBC_URL', get_stylesheet_directory_uri());

require_once NBC_DIR . '/inc/enqueue.php';
require_once NBC_DIR . '/inc/template-functions.php';
require_once NBC_DIR . '/inc/customizer.php';

/**
 * Theme-Setup — wird nach dem Parent geladen, ergänzt also nur.
 */
add_action('after_setup_theme', static function (): void {
    load_child_theme_textdomain('nachrichtenblatt-child', NBC_DIR . '/languages');

    // Block-Editor-Styles (Gutenberg) — Redaktion sieht im Editor das Frontend.
    add_theme_support('editor-styles');
    add_editor_style('assets/css/editor.css');

    // Vollständige Block-Unterstützung.
    add_theme_support('responsive-embeds');
    add_theme_support('align-wide');
    add_theme_support('html5', ['caption', 'comment-form', 'comment-list', 'gallery', 'search-form', 'style', 'script']);

    // Title-Tag wird vom Parent gesetzt — hier nur Sicherheitsnetz.
    if (!current_theme_supports('title-tag')) {
        add_theme_support('title-tag');
    }
}, 20);
