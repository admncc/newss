<?php
/**
 * Asset-Enqueues für das Child-Theme.
 *
 * Wir laden das Parent-CSS zuerst, dann das Redesign-CSS, damit unsere Regeln
 * gewinnen, ohne dass wir alles mit !important erschlagen müssen.
 *
 * @package NachrichtenblattChild
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', static function (): void {
    $parent = 'newspaper-parent-style';

    // Parent-Style robust laden (Newspaper enqueued sein eigenes Stylesheet
    // unter wechselnden Handles — wir setzen unser eigenes, falls keines da ist).
    if (!wp_style_is($parent, 'enqueued')) {
        wp_enqueue_style(
            $parent,
            get_template_directory_uri() . '/style.css',
            [],
            wp_get_theme(get_template())->get('Version') ?: NBC_VERSION
        );
    }

    wp_enqueue_style(
        'nachrichtenblatt-child-redesign',
        NBC_URL . '/assets/css/redesign.css',
        [$parent],
        NBC_VERSION
    );

    // Preconnect für Google Fonts — Newspaper lädt PT Serif/Work Sans/Open Sans/Roboto.
    add_filter('wp_resource_hints', static function (array $hints, string $relation): array {
        if ($relation === 'preconnect') {
            $hints[] = ['href' => 'https://fonts.gstatic.com', 'crossorigin'];
            $hints[] = 'https://fonts.googleapis.com';
        }
        return $hints;
    }, 10, 2);

    wp_enqueue_script(
        'nachrichtenblatt-child-redesign',
        NBC_URL . '/assets/js/redesign.js',
        [],
        NBC_VERSION,
        ['strategy' => 'defer', 'in_footer' => true]
    );

    // Dem JS Kontext mitgeben — Single-Posts sollen Lesefortschritt zeigen,
    // Listen-Seiten nicht.
    wp_localize_script('nachrichtenblatt-child-redesign', 'NBC', [
        'isSingle'      => is_singular('post'),
        'i18n'          => [
            'menuOpen'   => __('Menü öffnen', 'nachrichtenblatt-child'),
            'menuClose'  => __('Menü schließen', 'nachrichtenblatt-child'),
            'darkOn'    => __('Dunkles Design aktivieren', 'nachrichtenblatt-child'),
            'darkOff'   => __('Helles Design aktivieren', 'nachrichtenblatt-child'),
        ],
    ]);
}, 20);

/**
 * Skip-Link früh ins <body>, damit Screenreader & Tastaturnutzer:innen
 * direkt zum Hauptinhalt springen können.
 */
add_action('wp_body_open', static function (): void {
    echo '<a class="nbc-skip-link screen-reader-text" href="#content">' .
        esc_html__('Zum Hauptinhalt springen', 'nachrichtenblatt-child') .
        '</a>';
}, 1);

/**
 * Critical inline CSS — verhindert FOUC für Header/Logo bevor das CSS lädt.
 * Bewusst klein gehalten (< 1 KB).
 */
add_action('wp_head', static function (): void {
    echo "<style id='nbc-critical'>:root{--nbc-brand:#d81e1e;--nbc-ink:#0e1116;--nbc-paper:#fff}html{scroll-behavior:smooth}@media (prefers-reduced-motion:reduce){html{scroll-behavior:auto}}.nbc-skip-link{position:absolute;left:-9999px;top:0;z-index:100000;background:var(--nbc-ink);color:#fff;padding:.75rem 1rem;font-weight:600}.nbc-skip-link:focus{left:1rem;top:1rem}</style>";
}, 1);
