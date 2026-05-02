<?php

declare(strict_types=1);

namespace Newss;

final class ChannelResolver
{
    public function resolve(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (preg_match('/^UC[A-Za-z0-9_-]{22}$/', $input)) {
            return $this->fetchNameForId($input);
        }

        if (preg_match('#youtube\.com/channel/(UC[A-Za-z0-9_-]{22})#', $input, $m)) {
            return $this->fetchNameForId($m[1]);
        }

        $url = $this->normalizeUrl($input);
        if ($url === null) {
            return null;
        }

        $html = $this->httpGet($url);
        if ($html === '') {
            return null;
        }

        $id = $this->extractId($html);
        if ($id === null) {
            return null;
        }

        $name = $this->extractName($html) ?? $id;
        return ['id' => $id, 'name' => $name];
    }

    private function normalizeUrl(string $input): ?string
    {
        if (preg_match('#^https?://#i', $input)) {
            return $input;
        }
        if (preg_match('/^@([A-Za-z0-9._-]+)$/', $input, $m)) {
            return 'https://www.youtube.com/@' . $m[1];
        }
        if (preg_match('#^youtube\.com/#i', $input)) {
            return 'https://www.' . $input;
        }
        return null;
    }

    private function extractId(string $html): ?string
    {
        $patterns = [
            '#"channelId":"(UC[A-Za-z0-9_-]{22})"#',
            '#"externalId":"(UC[A-Za-z0-9_-]{22})"#',
            '#<link[^>]+rel="canonical"[^>]+href="https?://www\.youtube\.com/channel/(UC[A-Za-z0-9_-]{22})"#',
            '#<meta[^>]+itemprop="(?:identifier|channelId)"[^>]+content="(UC[A-Za-z0-9_-]{22})"#',
            '#youtube\.com/channel/(UC[A-Za-z0-9_-]{22})#',
        ];
        foreach ($patterns as $rx) {
            if (preg_match($rx, $html, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    private function extractName(string $html): ?string
    {
        $patterns = [
            '#<meta[^>]+property="og:title"[^>]+content="([^"]+)"#',
            '#<meta[^>]+name="title"[^>]+content="([^"]+)"#',
            '#"title":"([^"]+)","navigationEndpoint"#',
            '#<title>([^<]+) - YouTube</title>#',
        ];
        foreach ($patterns as $rx) {
            if (preg_match($rx, $html, $m)) {
                $name = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
                if ($name !== '') {
                    return $name;
                }
            }
        }
        return null;
    }

    private function fetchNameForId(string $channelId): array
    {
        $body = $this->httpGet('https://www.youtube.com/feeds/videos.xml?channel_id=' . $channelId);
        $name = $channelId;
        if ($body !== '') {
            if (preg_match('#<author>\s*<name>([^<]+)</name>#', $body, $m)
                || preg_match('#<title>([^<]+)</title>#', $body, $m)) {
                $name = trim(html_entity_decode($m[1], ENT_QUOTES));
            }
        }
        return ['id' => $channelId, 'name' => $name];
    }

    private function httpGet(string $url): string
    {
        $resp = wp_remote_get($url, [
            'timeout'    => 20,
            'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36',
            'headers'    => [
                'Accept-Language' => 'de,en;q=0.8',
            ],
        ]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            return '';
        }
        return (string) wp_remote_retrieve_body($resp);
    }
}
