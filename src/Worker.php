<?php

declare(strict_types=1);

namespace Newss;

final class Worker
{
    public const HOOK_PROCESS = 'newss_process_video';

    private static int $currentActionId = 0;

    public static function register(): void
    {
        add_action(self::HOOK_PROCESS, [self::class, 'processVideo'], 10, 1);
        add_action('action_scheduler_before_execute', static function ($actionId): void {
            self::$currentActionId = (int) $actionId;
        });
    }

    public static function processVideo(array $payload): void
    {
        $videoId = (string) ($payload['video_id'] ?? '');
        if ($videoId === '') {
            self::skip('empty payload', '');
            return;
        }

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
            self::skip('post already exists', $videoId);
            return;
        }

        $transcript = (new Transcript())->fetch($videoId);
        if ($transcript === '' || mb_strlen($transcript) < 50) {
            self::skip('transcript missing/too-short (' . mb_strlen($transcript) . ' chars)', $videoId);
            return;
        }

        $rewrite = (new Anthropic())->rewrite($transcript, $payload);
        if (!$rewrite) {
            throw new \RuntimeException('Claude rewrite failed; will retry');
        }

        $blockedHits = self::blockedTopicHits($rewrite);
        if ($blockedHits !== []) {
            $action = (string) get_option('newss_blocked_action', 'skip');
            if ($action === 'skip') {
                self::skip('sensitive topics: ' . implode(',', $blockedHits), $videoId);
                return;
            }
            $rewrite['_force_draft'] = 1;
        }

        $postId = (new PostBuilder())->createPost($rewrite, $payload);
        self::log('posted post #' . $postId);
    }

    private static function blockedTopicHits(array $rewrite): array
    {
        $tags    = array_values(array_filter((array) ($rewrite['topic_tags'] ?? [])));
        $blocked = array_values(array_filter((array) get_option('newss_blocked_topics', [])));
        if ($tags === [] || $blocked === []) {
            return [];
        }
        return array_values(array_intersect($tags, $blocked));
    }

    private static function skip(string $reason, string $videoId): void
    {
        if ($videoId !== '') {
            error_log("[newss] skip {$videoId}: {$reason}");
        }
        self::log('SKIP: ' . $reason);
    }

    private static function log(string $message): void
    {
        if (self::$currentActionId > 0 && class_exists('\\ActionScheduler')) {
            try {
                \ActionScheduler::logger()->log(self::$currentActionId, '[newss] ' . $message);
            } catch (\Throwable) {
                // logger may not be ready in some contexts
            }
        }
    }
}
