<?php

declare(strict_types=1);

namespace Newss;

final class RssPoller
{
    public static function pollAll(): void
    {
        $channels = get_option('newss_channels', []);
        if (!is_array($channels) || $channels === []) {
            return;
        }

        $stats = ['channels' => 0, 'new' => 0, 'errors' => 0];
        $perChannel = [];

        $first = true;
        foreach ($channels as $channel) {
            if (empty($channel['enabled']) || empty($channel['id'])) {
                continue;
            }
            if (!$first) {
                usleep(1500 * 1000); // 1.5s zwischen Channels — verhindert YT-Drossel
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
        }

        update_option('newss_last_poll', [
            'time'     => time(),
            'stats'    => $stats,
            'channels' => $perChannel,
        ], false);
    }

    private static function pollChannel(array $channel): int
    {
        $url = sprintf(
            'https://www.youtube.com/feeds/videos.xml?channel_id=%s',
            rawurlencode((string) $channel['id'])
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
            // 404 / 500 / 503: wahrscheinlich Proxy-Drossel -> erneut mit anderem Proxy
            if (($lastCode === 404 || $lastCode >= 500) && $attempt < $maxAttempts) {
                usleep(1500 * 1000 * $attempt);
                continue;
            }
            throw new \RuntimeException('RSS HTTP ' . $lastCode);
        }
        if ($lastCode !== 200) {
            throw new \RuntimeException('RSS HTTP ' . $lastCode . ' nach ' . $maxAttempts . ' Versuchen');
        }

        $videos = self::parseFeed((string) wp_remote_retrieve_body($response));

        $enqueued = 0;
        foreach ($videos as $video) {
            if (self::videoAlreadyHandled($video['id'])) {
                continue;
            }
            set_transient(Worker::pendingTransientKey($video['id']), 1, DAY_IN_SECONDS);
            \as_enqueue_async_action(
                Worker::HOOK_PROCESS,
                [[
                    'video_id'     => $video['id'],
                    'video_title'  => $video['title'],
                    'channel_id'   => (string) $channel['id'],
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
