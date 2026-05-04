<?php

declare(strict_types=1);

namespace Newss;

final class RssPoller
{
    public static function pollAll(): void
    {
        error_log('[newss] pollAll: enter');
        $channels = get_option('newss_channels', []);
        if (!is_array($channels) || $channels === []) {
            error_log('[newss] pollAll abort: no channels');
            return;
        }
        $enabled = array_values(array_filter($channels, static fn(array $c): bool => !empty($c['enabled']) && !empty($c['id'])));
        if ($enabled === []) {
            error_log('[newss] pollAll abort: no enabled channels');
            return;
        }

        // Mutex: verhindert parallele pollAll-Läufe (System-Cron + manueller AS-Trigger)
        if (get_transient('newss_poll_running')) {
            error_log('[newss] pollAll abort: another run in progress (mutex transient set)');
            return;
        }
        error_log('[newss] pollAll: starting with ' . count($enabled) . ' channels');

        // Auto-Cleanup von Stuck-Jobs (>10 Min. in-progress) -- belt-and-
        // suspenders falls der action_scheduler_before_process_queue-Hook
        // bei diesem Run nicht greift.
        Worker::maybeAutoCleanup();
        set_transient('newss_poll_running', time(), 30 * MINUTE_IN_SECONDS);
        update_option('newss_last_cron_run', time(), false);

        // 18 Channels × ~3s = ~1 min minimum, mit Whisper/yt-dlp deutlich
        // mehr. Standard-PHP-Limit (30s) und AS-Runner-Limit (30s) reichen
        // nicht. Hier explizit hochsetzen damit pollAll() nicht abbricht
        // und das Mutex stehen laesst.
        @set_time_limit(600);
        @ignore_user_abort(true);

        update_option('newss_poll_progress', [
            'started_at' => time(),
            'total'      => count($enabled),
            'done'       => 0,
            'current'    => '',
            'channels'   => [],
        ], false);

        $stats = ['channels' => 0, 'new' => 0, 'errors' => 0];
        $perChannel = [];

        try {
            $first = true;
            foreach ($enabled as $channel) {
                if (!$first) {
                    usleep(1500 * 1000);
                }
                $first = false;
                $stats['channels']++;
                $entry = [
                    'id'    => (string) $channel['id'],
                    'name'  => (string) ($channel['name'] ?? $channel['id']),
                    'count' => 0,
                    'ok'    => false,
                    'error' => '',
                ];
                self::updateProgress($stats['channels'] - 1, $entry['name'], $perChannel);
                try {
                    $entry['count'] = self::pollChannel($channel);
                    $entry['ok']    = true;
                    $stats['new'] += $entry['count'];
                } catch (\Throwable $e) {
                    $entry['error'] = $e->getMessage();
                    $stats['errors']++;
                    error_log('[newss] poll error for ' . $entry['id'] . ': ' . $e->getMessage());
                }
                $perChannel[] = $entry;
                self::updateProgress($stats['channels'], '', $perChannel);
            }

            update_option('newss_last_poll', [
                'time'     => time(),
                'stats'    => $stats,
                'channels' => $perChannel,
            ], false);
        } finally {
            delete_option('newss_poll_progress');
            delete_transient('newss_poll_running');
        }
    }

    private static function updateProgress(int $done, string $current, array $channels): void
    {
        $progress = get_option('newss_poll_progress', null);
        if (!is_array($progress)) {
            return;
        }
        $progress['done']     = $done;
        $progress['current']  = $current;
        $progress['channels'] = $channels;
        update_option('newss_poll_progress', $progress, false);
    }

    /**
     * Synchronous single-channel test: fetches latest video list without enqueueing
     * any jobs. Returns counts of "would-enqueue" + skipped duplicates.
     *
     * @return array{ok:bool, total:int, new:int, skipped:int, error:string, samples:array<int,array{id:string,title:string}>}
     */
    public static function testChannel(array $channel): array
    {
        $sourceId = (string) ($channel['id'] ?? '');
        $type     = (string) ($channel['type'] ?? 'channel');
        if ($sourceId === '') {
            return ['ok' => false, 'total' => 0, 'new' => 0, 'skipped' => 0, 'error' => 'Source-ID fehlt', 'samples' => []];
        }
        $method = (string) get_option('newss_youtube_method', 'rss');
        try {
            if ($method === 'api') {
                $apiKey = trim((string) get_option('newss_youtube_api_key', ''));
                if ($apiKey === '') {
                    throw new \RuntimeException('Methode = API gewählt, aber API-Key ist leer');
                }
                $videos = $type === 'playlist'
                    ? self::fetchPlaylistViaApi($sourceId, $apiKey)
                    : self::fetchViaApi($sourceId, $apiKey);
            } else {
                $videos = $type === 'playlist'
                    ? self::fetchPlaylistViaRss($sourceId)
                    : self::fetchViaRss($sourceId);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'total' => 0, 'new' => 0, 'skipped' => 0, 'error' => $e->getMessage(), 'samples' => []];
        }

        $new = 0;
        $skipped = 0;
        $samples = [];
        foreach ($videos as $v) {
            if (self::videoAlreadyHandled($v['id'])) {
                $skipped++;
            } else {
                $new++;
            }
            if (count($samples) < 3) {
                $samples[] = ['id' => (string) $v['id'], 'title' => (string) $v['title']];
            }
        }
        return [
            'ok'      => true,
            'total'   => count($videos),
            'new'     => $new,
            'skipped' => $skipped,
            'error'   => '',
            'samples' => $samples,
        ];
    }

    private static function pollChannel(array $channel): int
    {
        $sourceId = (string) $channel['id'];
        $type     = (string) ($channel['type'] ?? 'channel');
        $method   = (string) get_option('newss_youtube_method', 'rss');

        if ($method === 'api') {
            $apiKey = trim((string) get_option('newss_youtube_api_key', ''));
            if ($apiKey === '') {
                throw new \RuntimeException('Methode = API gewählt, aber API-Key ist leer');
            }
            $videos = $type === 'playlist'
                ? self::fetchPlaylistViaApi($sourceId, $apiKey)
                : self::fetchViaApi($sourceId, $apiKey);
        } else {
            $videos = $type === 'playlist'
                ? self::fetchPlaylistViaRss($sourceId)
                : self::fetchViaRss($sourceId);
        }

        $enqueued = 0;
        foreach ($videos as $video) {
            if (self::videoAlreadyHandled($video['id'])) {
                continue;
            }
            // TTL = 7 Tage, gleich wie Action-Scheduler-Retention.
            // Verhindert dass alte Pending-Transients vor dem AS-Job ablaufen
            // und der Channel beim nächsten Poll dieselben Videos doppelt enqueued.
            set_transient(Worker::pendingTransientKey($video['id']), 1, 7 * DAY_IN_SECONDS);
            \as_enqueue_async_action(
                Worker::HOOK_PROCESS,
                [[
                    'video_id'     => $video['id'],
                    'video_title'  => $video['title'],
                    'channel_id'   => $sourceId,
                    'channel_name' => (string) ($channel['name'] ?? ''),
                    'category_id'  => (int) ($channel['category'] ?? 0),
                    'published'    => $video['published'],
                ]],
                'newss'
            );
            $enqueued++;
        }
        return $enqueued;
    }

    /**
     * @return array<int,array{id:string,title:string,published:string}>
     */
    private static function fetchViaApi(string $channelId, string $apiKey): array
    {
        if (!preg_match('/^UC[A-Za-z0-9_-]{22}$/', $channelId)) {
            throw new \RuntimeException('Invalid channel id format: ' . $channelId);
        }
        if (get_transient('newss_yt_quota_exhausted')) {
            throw new \RuntimeException('YT-API Daily-Quota erschöpft — wartet auf Reset (Pacific midnight)');
        }

        $uploadsPlaylist = self::resolveUploadsPlaylist($channelId, $apiKey);
        if ($uploadsPlaylist === '') {
            throw new \RuntimeException('YT-API: uploads-Playlist nicht ermittelbar für ' . $channelId);
        }

        try {
            return self::queryPlaylistItems($uploadsPlaylist, $apiKey);
        } catch (\RuntimeException $e) {
            // Bei HTTP 404 koennte die uploads-Playlist veraltet sein
            // (Channel hat sie umbenannt/verschoben). Cache invalidieren
            // und einmalig mit frischer Aufloesung retryen.
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                delete_transient('newss_uploads_' . $channelId);
                $uploadsPlaylist = self::resolveUploadsPlaylist($channelId, $apiKey);
                if ($uploadsPlaylist === '') {
                    throw new \RuntimeException('YT-API HTTP 404 + uploads-Playlist nicht auffindbar');
                }
                return self::queryPlaylistItems($uploadsPlaylist, $apiKey);
            }
            throw $e;
        }
    }

    /**
     * @return array<int,array{id:string,title:string,published:string}>
     */
    private static function fetchPlaylistViaApi(string $playlistId, string $apiKey): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{13,}$/', $playlistId)) {
            throw new \RuntimeException('Invalid playlist id format: ' . $playlistId);
        }
        if (get_transient('newss_yt_quota_exhausted')) {
            throw new \RuntimeException('YT-API Daily-Quota erschöpft — wartet auf Reset (Pacific midnight)');
        }
        return self::queryPlaylistItems($playlistId, $apiKey);
    }

    /**
     * Geteilter API-Call fuer playlistItems.list — sowohl fuer Channel-Uploads
     * als auch User-Playlists.
     *
     * @return array<int,array{id:string,title:string,published:string}>
     */
    private static function queryPlaylistItems(string $playlistId, string $apiKey): array
    {
        $url = add_query_arg([
            'part'       => 'snippet,contentDetails',
            'playlistId' => $playlistId,
            'maxResults' => 15,
            'key'        => $apiKey,
        ], 'https://www.googleapis.com/youtube/v3/playlistItems');

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) {
            throw new \RuntimeException('YT-API network error: ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $errMsg = '';
            $errReason = '';
            $j = json_decode($body, true);
            if (is_array($j) && isset($j['error'])) {
                $errMsg = isset($j['error']['message']) ? ': ' . $j['error']['message'] : '';
                if (is_array($j['error']['errors'] ?? null)) {
                    $errReason = (string) ($j['error']['errors'][0]['reason'] ?? '');
                }
            }
            if ($code === 403 && in_array($errReason, ['quotaExceeded', 'dailyLimitExceeded', 'rateLimitExceeded'], true)) {
                $secondsUntilReset = self::secondsUntilPacificMidnight();
                set_transient('newss_yt_quota_exhausted', time(), $secondsUntilReset);
                error_log('[newss] YT-API Quota erschöpft, Reset in ' . $secondsUntilReset . 's');
            }
            Transcript::recordHealth('youtube_api', false, "HTTP {$code}" . ($errReason ? " · {$errReason}" : ''));
            throw new \RuntimeException('YT-API HTTP ' . $code . $errMsg);
        }
        Transcript::recordHealth('youtube_api', true, '');
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['items'])) {
            return [];
        }
        $out = [];
        foreach ($data['items'] as $item) {
            $videoId = (string) ($item['contentDetails']['videoId'] ?? '');
            $title   = (string) ($item['snippet']['title'] ?? '');
            $pub     = (string) ($item['snippet']['publishedAt'] ?? '');
            if ($videoId !== '') {
                $out[] = ['id' => $videoId, 'title' => $title, 'published' => $pub];
            }
        }
        return $out;
    }

    /**
     * YouTube-Quota resettet täglich um Mitternacht Pacific Time.
     * Liefert die Sekunden bis zum nächsten Reset.
     */
    private static function secondsUntilPacificMidnight(): int
    {
        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles'));
            $next = $now->modify('+1 day')->setTime(0, 0, 0);
            return max(60, $next->getTimestamp() - $now->getTimestamp());
        } catch (\Throwable) {
            return 8 * HOUR_IN_SECONDS;
        }
    }

    /**
     * Liefert die Uploads-Playlist-ID. Erst via Cache (7 Tage),
     * sonst per channels.list, sonst Fallback auf UC->UU-Konvention.
     */
    private static function resolveUploadsPlaylist(string $channelId, string $apiKey): string
    {
        $cached = get_transient('newss_uploads_' . $channelId);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $url = add_query_arg([
            'part' => 'contentDetails',
            'id'   => $channelId,
            'key'  => $apiKey,
        ], 'https://www.googleapis.com/youtube/v3/channels');

        $resp = wp_remote_get($url, ['timeout' => 20]);
        if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
            $data = json_decode((string) wp_remote_retrieve_body($resp), true);
            $uploads = (string) ($data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? '');
            if ($uploads !== '') {
                set_transient('newss_uploads_' . $channelId, $uploads, 7 * DAY_IN_SECONDS);
                return $uploads;
            }
        }

        // Fallback: UC->UU-Konvention
        return 'UU' . substr($channelId, 2);
    }

    /**
     * @return array<int,array{id:string,title:string,published:string}>
     */
    private static function fetchViaRss(string $channelId): array
    {
        return self::queryRssFeed('channel_id', $channelId);
    }

    /**
     * @return array<int,array{id:string,title:string,published:string}>
     */
    private static function fetchPlaylistViaRss(string $playlistId): array
    {
        return self::queryRssFeed('playlist_id', $playlistId);
    }

    /**
     * @return array<int,array{id:string,title:string,published:string}>
     */
    private static function queryRssFeed(string $param, string $id): array
    {
        $url = sprintf(
            'https://www.youtube.com/feeds/videos.xml?%s=%s',
            $param,
            rawurlencode($id)
        );

        $maxAttempts = 3;
        $response = null;
        $lastCode = 0;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = Http::get($url, [
                'timeout'    => 30,
                'user-agent' => 'NewssAutopost/1.0 (+WordPress)',
            ]);
            if (is_wp_error($response)) {
                if ($attempt < $maxAttempts) {
                    usleep(1000 * 1000 * $attempt);
                    continue;
                }
                throw new \RuntimeException('RSS fetch failed: ' . $response->get_error_message());
            }
            $lastCode = (int) wp_remote_retrieve_response_code($response);
            if ($lastCode === 200) {
                break;
            }
            if (($lastCode === 404 || $lastCode >= 500) && $attempt < $maxAttempts) {
                usleep(1500 * 1000 * $attempt);
                continue;
            }
            throw new \RuntimeException('RSS HTTP ' . $lastCode);
        }
        if ($lastCode !== 200) {
            throw new \RuntimeException('RSS HTTP ' . $lastCode . ' nach ' . $maxAttempts . ' Versuchen');
        }
        return self::parseFeed((string) wp_remote_retrieve_body($response));
    }

    private static function parseFeed(string $xml): array
    {
        if ($xml === '') {
            return [];
        }
        libxml_use_internal_errors(true);
        $sxml = simplexml_load_string($xml);
        if ($sxml === false) {
            return [];
        }

        $ns = $sxml->getNamespaces(true);
        $ytNs = $ns['yt'] ?? 'http://www.youtube.com/xml/schemas/2015';

        $out = [];
        foreach ($sxml->entry ?? [] as $entry) {
            $ytChildren = $entry->children($ytNs);
            $videoId = isset($ytChildren->videoId) ? (string) $ytChildren->videoId : '';
            if ($videoId === '') {
                continue;
            }
            $out[] = [
                'id'        => $videoId,
                'title'     => (string) $entry->title,
                'published' => (string) $entry->published,
            ];
        }
        return $out;
    }

    private static function videoAlreadyHandled(string $videoId): bool
    {
        if (get_transient(Worker::pendingTransientKey($videoId))) {
            return true;
        }
        return Worker::videoAlreadyHasPost($videoId);
    }
}
