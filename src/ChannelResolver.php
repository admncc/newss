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

        // Handle-Pfad: erst per offizieller API auflösen falls Key gesetzt
        $handle = $this->extractHandle($input);
        if ($handle !== '') {
            $apiResult = $this->resolveViaApi($handle);
            if ($apiResult !== null) {
                return $apiResult;
            }
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

    /**
     * Holt die Channel-ID via YouTube Data API channels.list?forHandle=...
     * Autoritativer als HTML-Scraping, nur möglich wenn API-Key vorhanden.
     */
    private function resolveViaApi(string $handle): ?array
    {
        $apiKey = trim((string) get_option('newss_youtube_api_key', ''));
        if ($apiKey === '') {
            return null;
        }
        $url = add_query_arg([
            'part'      => 'snippet',
            'forHandle' => '@' . ltrim($handle, '@'),
            'key'       => $apiKey,
        ], 'https://www.googleapis.com/youtube/v3/channels');

        $resp = wp_remote_get($url, ['timeout' => 15]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        $item = $data['items'][0] ?? null;
        if (!is_array($item) || empty($item['id'])) {
            return null;
        }
        return [
            'id'   => (string) $item['id'],
            'name' => (string) ($item['snippet']['title'] ?? $item['id']),
        ];
    }

    private function extractHandle(string $input): string
    {
        if (preg_match('#youtube\.com/@([A-Za-z0-9._-]+)#i', $input, $m)) {
            return $m[1];
        }
        if (preg_match('/^@?([A-Za-z0-9._-]+)$/', $input, $m) && !preg_match('#^https?://#', $input)) {
            return $m[1];
        }
        return '';
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
        // Reihenfolge nach Vertrauenswürdigkeit:
        // 1) canonical/og:url zeigen auf DIE aktuelle Seite (= echter Kanal)
        // 2) itemprop=channelId — strukturiertes Markup vom Channel selbst
        // 3) browseId — Channel-Browse-Context im YT-Player-State
        // Erst zuletzt fallen wir auf "channelId" im Inline-JSON zurück, das
        // auch in Sidebar/Empfehlungen vorkommt und falsch matchen kann.
        $patterns = [
            '#<link[^>]+rel=["\']canonical["\'][^>]+href=["\']https?://www\.youtube\.com/channel/(UC[A-Za-z0-9_-]{22})["\']#i',
            '#<meta[^>]+property=["\']og:url["\'][^>]+content=["\']https?://www\.youtube\.com/channel/(UC[A-Za-z0-9_-]{22})["\']#i',
            '#<meta[^>]+itemprop=["\'](?:identifier|channelId)["\'][^>]+content=["\'](UC[A-Za-z0-9_-]{22})["\']#i',
            '#"browseId":"(UC[A-Za-z0-9_-]{22})"#',
            '#"externalId":"(UC[A-Za-z0-9_-]{22})"#',
            '#"channelId":"(UC[A-Za-z0-9_-]{22})"#',
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
        $resp = Http::get($url, [
            'timeout'    => 20,
            'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36',
            'headers'    => [
                'Accept-Language' => 'de,en;q=0.8',
                'Cookie'          => 'CONSENT=YES+cb.20210328-17-p0.de+FX+1; SOCS=CAESEwgDEgk0ODE3Nzk3MjQaAmRlIAEaBgiA_LyaBg',
            ],
        ]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            return '';
        }
        return (string) wp_remote_retrieve_body($resp);
    }
}
