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

        foreach ($channels as $channel) {
            if (empty($channel['enabled']) || empty($channel['id'])) {
                continue;
            }
            $stats['channels']++;
            try {
                $stats['new'] += self::pollChannel($channel);
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log('[newss] poll error for ' . ($channel['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }

        update_option('newss_last_poll', [
            'time'  => time(),
            'stats' => $stats,
        ], false);
    }

    private static function pollChannel(array $channel): int
    {
        $url = sprintf(
            'https://www.youtube.com/feeds/videos.xml?channel_id=%s',
            rawurlencode((string) $channel['id'])
        );

        $response = Http::get($url, [
            'timeout'    => 30,
            'user-agent' => 'NewssAutopost/1.0 (+WordPress)',
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('RSS fetch failed: ' . $response->get_error_message());
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            throw new \RuntimeException('RSS HTTP ' . wp_remote_retrieve_response_code($response));
        }

        $videos = self::parseFeed((string) wp_remote_retrieve_body($response));

        $enqueued = 0;
        foreach ($videos as $video) {
            if (self::videoAlreadyHandled($video['id'])) {
                continue;
            }
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
        $existing = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'any',
            'meta_key'       => '_newss_video_id',
            'meta_value'     => $videoId,
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);
        if ($existing) {
            return true;
        }

        $pending = \as_has_scheduled_action(Worker::HOOK_PROCESS, null, 'newss');
        if (!$pending) {
            return false;
        }
        $matches = \as_get_scheduled_actions([
            'hook'     => Worker::HOOK_PROCESS,
            'group'    => 'newss',
            'status'   => [\ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING],
            'per_page' => 200,
        ], 'ids');
        foreach ($matches as $actionId) {
            $action = \ActionScheduler::store()->fetch_action($actionId);
            $args   = $action ? $action->get_args() : [];
            $payload = $args[0] ?? null;
            if (is_array($payload) && ($payload['video_id'] ?? '') === $videoId) {
                return true;
            }
        }
        return false;
    }
}
