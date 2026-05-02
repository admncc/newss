<?php

declare(strict_types=1);

namespace Newss;

final class PostBuilder
{
    public function createPost(array $rewrite, array $payload): int
    {
        $videoId   = (string) ($payload['video_id'] ?? '');
        $channel   = (string) ($payload['channel_name'] ?? '');
        $channelId = (string) ($payload['channel_id'] ?? '');
        $published = (string) ($payload['published'] ?? '');

        $killSwitch = (bool) get_option('newss_kill_switch_drafts', false);
        $defaultStatus = (string) get_option('newss_default_status', 'publish');
        $status = $killSwitch ? 'draft' : ($defaultStatus === 'draft' ? 'draft' : 'publish');

        $bodyHtml = $this->sanitizeBodyHtml((string) ($rewrite['body_html'] ?? ''));

        $content = $this->embedHtml($videoId, $channel, $channelId, $published)
            . "\n\n" . $bodyHtml
            . "\n\n" . $this->disclosureHtml($channel);

        $categoryIds = [];
        $categoryId  = (int) ($payload['category_id'] ?? 0);
        if ($categoryId === 0) {
            $categoryId = (int) get_option('newss_default_category', 0);
        }
        if ($categoryId > 0) {
            $categoryIds[] = $categoryId;
        }

        $authorId = (int) get_option('newss_post_author', 0);
        if ($authorId === 0) {
            $authorId = get_current_user_id() ?: 1;
        }

        $tags = array_values(array_filter(array_map(
            static fn($t): string => trim((string) $t),
            (array) ($rewrite['tags'] ?? [])
        )));

        $postArr = [
            'post_title'    => sanitize_text_field((string) ($rewrite['title'] ?? '')),
            'post_name'     => sanitize_title((string) ($rewrite['slug'] ?? '')),
            'post_excerpt'  => sanitize_text_field((string) ($rewrite['excerpt'] ?? '')),
            'post_content'  => $content,
            'post_status'   => $status,
            'post_author'   => $authorId,
            'post_category' => $categoryIds,
            'tags_input'    => $tags,
            'meta_input'    => [
                '_newss_video_id'   => $videoId,
                '_newss_channel_id' => $channelId,
                '_newss_source_url' => 'https://www.youtube.com/watch?v=' . $videoId,
            ],
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

        $postId = wp_insert_post($postArr, true);
        if (is_wp_error($postId)) {
            throw new \RuntimeException('wp_insert_post failed: ' . $postId->get_error_message());
        }

        $this->setFeaturedImage((int) $postId, $videoId);
        return (int) $postId;
    }

    private function embedHtml(string $videoId, string $channel, string $channelId, string $published): string
    {
        $embedUrl    = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId);
        $channelLink = 'https://www.youtube.com/channel/' . rawurlencode($channelId);
        $videoLink   = 'https://www.youtube.com/watch?v=' . rawurlencode($videoId);
        $publishedDe = $this->formatDateDe($published);

        return sprintf(
            '<div class="newss-embed" style="margin-bottom:20px;position:relative;padding-bottom:56.25%%;height:0;overflow:hidden;">'
            . '<iframe src="%s" style="position:absolute;top:0;left:0;width:100%%;height:100%%;" frameborder="0"'
            . ' allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture" allowfullscreen></iframe>'
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
        return sprintf(
            '<div class="newss-disclosure" style="margin-top:30px;padding:12px;border:1px solid #eee;background:#fafafa;font-size:0.85em;color:#555;">'
            . '<p><strong>Hinweis:</strong> Dieser Artikel wurde mithilfe von KI auf Basis des verlinkten YouTube-Videos von %s erstellt und kann Fehler oder Ungenauigkeiten enthalten.</p></div>',
            esc_html($channel)
        );
    }

    private function sanitizeBodyHtml(string $html): string
    {
        return wp_kses($html, [
            'p'      => [],
            'strong' => [],
            'em'     => [],
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

    private function setFeaturedImage(int $postId, string $videoId): void
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
            $tmp = download_url($url, 30);
            if (is_wp_error($tmp)) {
                continue;
            }
            if (filesize($tmp) < 5_000) {
                @unlink($tmp);
                continue;
            }
            $attachId = media_handle_sideload(
                ['name' => "yt-{$videoId}.jpg", 'tmp_name' => $tmp],
                $postId
            );
            if (is_wp_error($attachId)) {
                @unlink($tmp);
                continue;
            }
            set_post_thumbnail($postId, $attachId);
            return;
        }
    }
}
