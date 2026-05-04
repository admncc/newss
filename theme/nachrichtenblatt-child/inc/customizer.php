<?php
/**
 * Customizer-Anbindung — erlaubt Redaktion, Akzentfarbe und Standard-Theme
 * (hell/dunkel) ohne Code-Änderung umzustellen. Die Werte werden als
 * CSS-Variablen ins <head> geschrieben.
 *
 * @package NachrichtenblattChild
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_action('customize_register', static function ($wp_customize): void {
    $wp_customize->add_section('nbc_design', [
        'title'    => __('Nachrichtenblatt — Design', 'nachrichtenblatt-child'),
        'priority' => 30,
    ]);

    // Akzentfarbe (Marken-Rot).
    $wp_customize->add_setting('nbc_accent_color', [
        'default'           => '#d81e1e',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'nbc_accent_color', [
        'label'   => __('Akzentfarbe', 'nachrichtenblatt-child'),
        'section' => 'nbc_design',
    ]));

    // Standard-Modus (hell / dunkel / system).
    $wp_customize->add_setting('nbc_color_mode', [
        'default'           => 'system',
        'sanitize_callback' => static fn ($v) => in_array($v, ['light', 'dark', 'system'], true) ? $v : 'system',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('nbc_color_mode', [
        'label'   => __('Standard-Farbmodus', 'nachrichtenblatt-child'),
        'section' => 'nbc_design',
        'type'    => 'select',
        'choices' => [
            'system' => __('System (Browser-Einstellung folgen)', 'nachrichtenblatt-child'),
            'light'  => __('Hell', 'nachrichtenblatt-child'),
            'dark'   => __('Dunkel', 'nachrichtenblatt-child'),
        ],
    ]);

    // Lesefortschrittsbalken an/aus.
    $wp_customize->add_setting('nbc_reading_progress', [
        'default'           => true,
        'sanitize_callback' => static fn ($v) => (bool) $v,
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('nbc_reading_progress', [
        'label'   => __('Lesefortschrittsbalken auf Beiträgen anzeigen', 'nachrichtenblatt-child'),
        'section' => 'nbc_design',
        'type'    => 'checkbox',
    ]);
});

/**
 * Customizer-Werte als CSS-Variablen rendern und JS-Flag setzen.
 */
add_action('wp_head', static function (): void {
    $accent = get_theme_mod('nbc_accent_color', '#d81e1e');
    $mode   = get_theme_mod('nbc_color_mode', 'system');

    echo "<style id='nbc-customizer'>:root{--nbc-brand:" . esc_attr($accent) . "}</style>\n";

    if ($mode !== 'system') {
        echo "<script>document.documentElement.setAttribute('data-nbc-color-mode'," . wp_json_encode($mode) . ");</script>\n";
    }
}, 20);

/**
 * Reading-Progress-Flag für JS.
 */
add_filter('nbc_reading_progress_enabled', static fn () => (bool) get_theme_mod('nbc_reading_progress', true));
