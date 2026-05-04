<?php
/**
 * Template-Funktionen — Reading-Time, KI-Disclosure, Schema.org-JSON-LD.
 *
 * @package NachrichtenblattChild
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lesezeit in Minuten — 200 WPM, mindestens 1.
 */
function nbc_reading_time(?int $postId = null): int
{
    $postId = $postId ?: get_the_ID();
    if (!$postId) {
        return 1;
    }

    $cached = get_post_meta($postId, '_nbc_reading_time', true);
    if (is_numeric($cached) && (int) $cached > 0) {
        return (int) $cached;
    }

    $content = get_post_field('post_content', $postId);
    $words   = str_word_count(wp_strip_all_tags((string) $content));
    $minutes = max(1, (int) ceil($words / 200));

    update_post_meta($postId, '_nbc_reading_time', $minutes);
    return $minutes;
}

/**
 * Rendert die Lesezeit-Pille — wird im Single-Header eingehängt.
 */
function nbc_render_reading_time(): string
{
    $min = nbc_reading_time();
    return sprintf(
        '<span class="nbc-reading-time" aria-label="%s">%s</span>',
        esc_attr(sprintf(
            /* translators: %d = Lesezeit in Minuten */
            _n('%d Minute Lesezeit', '%d Minuten Lesezeit', $min, 'nachrichtenblatt-child'),
            $min
        )),
        esc_html(sprintf(
            /* translators: %d = Lesezeit in Minuten */
            _n('%d Min. Lesezeit', '%d Min. Lesezeit', $min, 'nachrichtenblatt-child'),
            $min
        ))
    );
}

/**
 * Lesezeit-Cache invalidieren, wenn der Beitrag aktualisiert wird.
 */
add_action('save_post', static function (int $postId): void {
    if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
        return;
    }
    delete_post_meta($postId, '_nbc_reading_time');
});

/**
 * KI-Disclosure-Banner für Beiträge, die das Newss-Plugin angelegt hat.
 *
 * Newss setzt `_newss_ai_generated` als Postmeta. Wir rendern eine deutliche
 * Hinweis-Box am Ende des Inhalts — pflichtgemäß nach EU AI Act Art. 50.
 *
 * Das Newss-Plugin selbst hängt bereits einen Hinweis an. Wir prüfen, ob er
 * schon im Content steckt — sonst hängen wir unsere Variante an.
 */
add_filter('the_content', static function (string $content): string {
    if (!is_singular('post') || !in_the_loop() || !is_main_query()) {
        return $content;
    }

    $isAi = (bool) get_post_meta(get_the_ID(), '_newss_ai_generated', true);
    if (!$isAi) {
        return $content;
    }

    // Kein Doppel-Disclosure — wenn das Plugin schon einen gerendert hat, raus.
    if (str_contains($content, 'newss-ai-disclosure') || str_contains($content, 'nbc-ai-disclosure')) {
        return $content;
    }

    $disclosure = sprintf(
        '<aside class="nbc-ai-disclosure" role="note" aria-label="%s">' .
            '<svg class="nbc-ai-disclosure__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">' .
                '<path d="M12 2v3M12 19v3M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M2 12h3M19 12h3M4.93 19.07l2.12-2.12M16.95 7.05l2.12-2.12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' .
                '<circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2"/>' .
            '</svg>' .
            '<div><strong>%s</strong> %s</div>' .
        '</aside>',
        esc_attr__('Hinweis zur KI-Erstellung', 'nachrichtenblatt-child'),
        esc_html__('Hinweis:', 'nachrichtenblatt-child'),
        esc_html__('Dieser Beitrag wurde mithilfe eines KI-Systems aus einem Video-Transkript erstellt und redaktionell geprüft. Inhaltliche Verantwortung liegt bei der Redaktion.', 'nachrichtenblatt-child')
    );

    return $content . $disclosure;
}, 99);

/**
 * NewsArticle-JSON-LD für Single-Posts ergänzen — verbessert SEO/News-Sitemaps.
 * Wenn Rank Math / Yoast aktiv sind und bereits Schema liefern, nicht doppelt.
 */
add_action('wp_head', static function (): void {
    if (!is_singular('post')) {
        return;
    }
    if (defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION')) {
        return;
    }

    $postId = get_the_ID();
    if (!$postId) {
        return;
    }

    $authorId = (int) get_post_field('post_author', $postId);
    $thumb    = get_the_post_thumbnail_url($postId, 'full');

    $schema = [
        '@context'         => 'https://schema.org',
        '@type'            => 'NewsArticle',
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => get_permalink($postId)],
        'headline'         => wp_strip_all_tags(get_the_title($postId)),
        'datePublished'    => get_the_date('c', $postId),
        'dateModified'     => get_the_modified_date('c', $postId),
        'author'           => [
            '@type' => 'Person',
            'name'  => get_the_author_meta('display_name', $authorId),
            'url'   => get_author_posts_url($authorId),
        ],
        'publisher' => [
            '@type' => 'NewsMediaOrganization',
            'name'  => get_bloginfo('name'),
            'url'   => home_url('/'),
        ],
        'inLanguage' => get_bloginfo('language'),
    ];

    if ($thumb) {
        $schema['image'] = [$thumb];
    }

    if ((bool) get_post_meta($postId, '_newss_ai_generated', true)) {
        $schema['creativeWorkStatus'] = 'AI-assisted';
    }

    echo "\n<script type=\"application/ld+json\">" .
        wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
        "</script>\n";
}, 30);

/**
 * Body-Class um nützliche Marker erweitern — erlaubt CSS-Targeting
 * von KI-Beiträgen, langen Lesestücken etc.
 */
add_filter('body_class', static function (array $classes): array {
    if (is_singular('post')) {
        $postId = get_the_ID();
        if ((bool) get_post_meta($postId, '_newss_ai_generated', true)) {
            $classes[] = 'nbc-is-ai-post';
        }
        $rt = nbc_reading_time($postId);
        if ($rt >= 8) {
            $classes[] = 'nbc-longread';
        }
    }
    return $classes;
});

/**
 * Excerpt-Länge auf news-typische Länge bringen (Newspaper kürzt brutal).
 */
add_filter('excerpt_length', static fn () => 28, 999);
add_filter('excerpt_more', static fn () => '…', 999);
