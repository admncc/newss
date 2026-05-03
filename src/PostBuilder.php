<?php

declare(strict_types=1);

namespace Newss;

final class PostBuilder
{
    public function createPost(array $rewrite, array $payload, bool $forceDraft = false): int
    {
        $videoId   = (string) ($payload['video_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
            throw new \RuntimeException('invalid video id: ' . $videoId);
        }
        $channel   = (string) ($payload['channel_name'] ?? '');
        $channelId = (string) ($payload['channel_id'] ?? '');
        $published = (string) ($payload['published'] ?? '');

        $killSwitch    = (bool) get_option('newss_kill_switch_drafts', false);
        $defaultStatus = (string) get_option('newss_default_status', 'publish');
        $status = ($killSwitch || $forceDraft || $defaultStatus === 'draft') ? 'draft' : 'publish';

        $title           = sanitize_text_field((string) ($rewrite['title'] ?? ''));
        $slug            = sanitize_title((string) ($rewrite['slug'] ?? ''));
        $focusKeyword    = sanitize_text_field((string) ($rewrite['focus_keyword'] ?? ''));
        $metaDescription = sanitize_text_field((string) ($rewrite['meta_description'] ?? ''));
        $imageAlt        = sanitize_text_field((string) ($rewrite['image_alt'] ?? ''));
        $aiCategory      = (string) ($rewrite['category'] ?? '');
        $bodyHtml        = $this->sanitizeBodyHtml((string) ($rewrite['body_html'] ?? ''));

        $content = $this->embedHtml($videoId, $channel, $channelId, $published)
            . "\n\n" . $bodyHtml
            . "\n\n" . $this->disclosureHtml($channel);

        $categoryIds = $this->resolveCategories(
            (int) ($payload['category_id'] ?? 0),
            $aiCategory
        );

        $authorId = (int) get_option('newss_post_author', 0);
        if ($authorId === 0) {
            $authorId = get_current_user_id() ?: 1;
        }

        $tags = array_values(array_filter(array_map(
            static fn($t): string => trim((string) $t),
            (array) ($rewrite['tags'] ?? [])
        )));

        $metaInput = [
            '_newss_video_id'   => $videoId,
            '_newss_channel_id' => $channelId,
            '_newss_source_url' => 'https://www.youtube.com/watch?v=' . $videoId,
        ];

        if ($focusKeyword !== '') {
            $metaInput['rank_math_focus_keyword']  = $focusKeyword;
            $metaInput['_yoast_wpseo_focuskw']     = $focusKeyword;
        }
        if ($metaDescription !== '') {
            $metaInput['rank_math_description']    = $metaDescription;
            $metaInput['_yoast_wpseo_metadesc']    = $metaDescription;
        }
        if ($title !== '') {
            $metaInput['rank_math_title']          = $title;
            $metaInput['_yoast_wpseo_title']       = $title;
        }

        $postArr = [
            'post_title'    => $title,
            'post_name'     => $slug,
            'post_excerpt'  => $metaDescription,
            'post_content'  => $content,
            'post_status'   => $status,
            'post_author'   => $authorId,
            'post_category' => $categoryIds,
            'meta_input'    => $metaInput,
        ];

        if ($published !== '') {
            try {
                $dt = new \DateTimeImmutable($published);
                $postArr['post_date_gmt'] = $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $postArr['post_date']     = $dt->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
            } catch (\Exception) {
                // ignore — let WP set current time
            }
        }

        $iframeFilter = static function (array $tags, $context): array {
            if ($context !== 'post') {
                return $tags;
            }
            $tags['iframe'] = [
                'src'             => true,
                'width'           => true,
                'height'          => true,
                'frameborder'     => true,
                'allow'           => true,
                'allowfullscreen' => true,
                'loading'         => true,
                'style'           => true,
                'class'           => true,
            ];
            $tags['div']['style'] = true;
            $tags['div']['class'] = true;
            return $tags;
        };
        add_filter('wp_kses_allowed_html', $iframeFilter, 10, 2);
        try {
            $postId = wp_insert_post($postArr, true);
        } finally {
            remove_filter('wp_kses_allowed_html', $iframeFilter, 10);
        }
        if (is_wp_error($postId)) {
            throw new \RuntimeException('wp_insert_post failed: ' . $postId->get_error_message());
        }

        if ($tags !== []) {
            wp_set_post_tags((int) $postId, $tags, false);
        }

        $this->setFeaturedImage(
            (int) $postId,
            $videoId,
            $this->buildAltText($imageAlt, $focusKeyword, $title)
        );
        return (int) $postId;
    }

    /**
     * Stellt sicher dass das Fokus-Keyword im Alt-Text steht.
     * Falls Claude es vergisst: Keyword wird vorne angefügt.
     * Max-Länge 125 (gleich wie Tool-Schema-Constraint).
     */
    private function buildAltText(string $imageAlt, string $focusKeyword, string $titleFallback): string
    {
        $alt = trim($imageAlt);
        if ($alt === '') {
            $alt = trim($titleFallback);
        }
        if ($focusKeyword !== '' && $alt !== '' && stripos($alt, $focusKeyword) === false) {
            $alt = rtrim($focusKeyword, ' .,:;') . ' – ' . $alt;
        }
        if (mb_strlen($alt) > 125) {
            $alt = mb_substr($alt, 0, 124) . '…';
        }
        return $alt;
    }

    private function resolveCategories(int $perChannelFallback, string $aiCategoryName): array
    {
        if ($aiCategoryName !== '') {
            $allowed = Anthropic::categoryList();
            if (in_array($aiCategoryName, $allowed, true)) {
                $termId = $this->ensureCategoryByName($aiCategoryName);
                if ($termId > 0) {
                    return [$termId];
                }
            }
        }

        if ($perChannelFallback > 0) {
            return [$perChannelFallback];
        }

        $defaultCat = (int) get_option('newss_default_category', 0);
        return $defaultCat > 0 ? [$defaultCat] : [];
    }

    private function ensureCategoryByName(string $name): int
    {
        $term = get_term_by('name', $name, 'category');
        if ($term && !is_wp_error($term)) {
            return (int) $term->term_id;
        }
        $created = wp_insert_term($name, 'category');
        if (is_wp_error($created)) {
            return 0;
        }
        return (int) $created['term_id'];
    }

    private function embedHtml(string $videoId, string $channel, string $channelId, string $published): string
    {
        $embedUrl    = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId);
        $channelLink = 'https://www.youtube.com/channel/' . rawurlencode($channelId);
        $videoLink   = 'https://www.youtube.com/watch?v=' . rawurlencode($videoId);
        $publishedDe = $this->formatDateDe($published);

        return sprintf(
            '<div class="newss-embed" style="aspect-ratio:16/9;margin:0 auto 20px auto;max-width:70%%;background:#000;">'
            . '<iframe src="%s" style="width:100%%;height:100%%;border:0;display:block;"'
            . ' allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture" allowfullscreen loading="lazy"></iframe>'
            . '</div>'
            . '<div class="newss-source" style="margin-bottom:20px;font-size:0.85em;color:#666;">'
            . '<p>Dieses Video wurde am %s von <a href="%s" target="_blank" rel="noopener noreferrer">%s</a> auf YouTube veröffentlicht. '
            . '<a href="%s" target="_blank" rel="noopener noreferrer">Zum Original-Video auf YouTube</a>.</p></div>',
            esc_url($embedUrl),
            esc_html($publishedDe),
            esc_url($channelLink),
            esc_html($channel),
            esc_url($videoLink)
        );
    }

    private function disclosureHtml(string $channel): string
    {
        $channelLabel = $channel !== '' ? esc_html($channel) : 'YouTube-Kanal-Betreiber';
        return sprintf(
            '<div class="newss-disclosure" style="margin-top:30px;padding:12px 14px;border:1px solid #eee;background:#fafafa;font-size:0.8em;color:#555;line-height:1.6;">'
            . '<p style="margin:0"><strong>Hinweis:</strong> Dieser Artikel wurde KI-gestützt erstellt und kann Fehler oder Ungenauigkeiten enthalten. '
            . 'Das verwendete Beitragsbild ist das YouTube-Vorschaubild des Original-Videos. '
            . 'Sämtliche Urheberrechte am Video, am Vorschaubild und an den darin enthaltenen Inhalten liegen beim jeweiligen YouTube-Kanal-Betreiber (%s). '
            . 'Bei Fragen oder Anliegen zur Nutzung bitte über das <a href="%s">Impressum</a> kontaktieren.</p></div>',
            $channelLabel,
            esc_url(get_privacy_policy_url() ?: home_url('/impressum/'))
        );
    }

    private function sanitizeBodyHtml(string $html): string
    {
        return wp_kses($html, [
            'p'      => [],
            'h2'     => [],
            'h3'     => [],
            'strong' => [],
            'em'     => [],
            'ul'     => [],
            'ol'     => [],
            'li'     => [],
            'br'     => [],
        ]);
    }

    private function formatDateDe(string $iso): string
    {
        if ($iso === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($iso))->format('d.m.Y');
        } catch (\Exception) {
            return '';
        }
    }

    private function setFeaturedImage(int $postId, string $videoId, string $altText): void
    {
        if ($postId === 0 || $videoId === '') {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $candidates = [
            "https://i.ytimg.com/vi/{$videoId}/maxresdefault.jpg",
            "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
        ];
        foreach ($candidates as $url) {
            $tmp = $this->downloadViaHttp($url);
            if ($tmp === '') {
                continue;
            }
            if (filesize($tmp) < 5_000) {
                @unlink($tmp);
                continue;
            }
            $attachId = media_handle_sideload(
                ['name' => "yt-{$videoId}.jpg", 'tmp_name' => $tmp],
                $postId,
                $altText
            );
            if (is_wp_error($attachId)) {
                @unlink($tmp);
                continue;
            }
            if ($altText !== '') {
                update_post_meta((int) $attachId, '_wp_attachment_image_alt', $altText);
            }
            set_post_thumbnail($postId, $attachId);
            return;
        }
    }

    private function downloadViaHttp(string $url): string
    {
        $resp = Http::get($url, ['timeout' => 30]);
        if (is_wp_error($resp)) {
            return '';
        }
        if ((int) wp_remote_retrieve_response_code($resp) !== 200) {
            return '';
        }
        $body = (string) wp_remote_retrieve_body($resp);
        if ($body === '') {
            return '';
        }
        $tmp = wp_tempnam('newss-thumb-');
        if (!$tmp) {
            return '';
        }
        if (file_put_contents($tmp, $body) === false) {
            @unlink($tmp);
            return '';
        }
        return $tmp;
    }
}
