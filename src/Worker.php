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

        if (self::videoAlreadyHasPost($videoId)) {
            self::skip('post already exists', $videoId);
            return;
        }

        $lockKey = 'newss_lock_' . $videoId;
        $now = time();
        if (!add_option($lockKey, $now, '', 'no')) {
            $existing = (int) get_option($lockKey, 0);
            if ($existing > 0 && ($now - $existing) > 600) {
                // Stale Lock (>10 min) — vorheriger Worker ist gestorben ohne finally
                error_log("[newss] stale lock cleared for {$videoId} (age " . ($now - $existing) . 's)');
                delete_option($lockKey);
                if (!add_option($lockKey, $now, '', 'no')) {
                    self::skip('lock contention nach stale-cleanup', $videoId);
                    return;
                }
            } else {
                self::skip('parallel worker already processing', $videoId);
                return;
            }
        }

        try {
            $tx = new Transcript();
            $transcript = $tx->fetch($videoId);
            if ($transcript === '' || mb_strlen($transcript) < 50) {
                self::skip('transcript missing/too-short (' . mb_strlen($transcript) . ' chars)', $videoId);
                return;
            }
            self::log(sprintf('transcript via %s (%d chars)', $tx->lastProvider ?: 'unknown', mb_strlen($transcript)));

            $rewrite = (new Anthropic())->rewrite($transcript, $payload);
            if (!$rewrite) {
                throw new \RuntimeException('Claude rewrite failed; will retry');
            }

            $blockedHits = self::blockedTopicHits($rewrite);
            $forceDraft  = false;
            if ($blockedHits !== []) {
                $action = (string) get_option('newss_blocked_action', 'skip');
                if ($action === 'skip') {
                    self::skip('sensitive topics: ' . implode(',', $blockedHits), $videoId);
                    return;
                }
                $forceDraft = true;
            }

            $postId = (new PostBuilder())->createPost($rewrite, $payload, $forceDraft);
            delete_transient(self::pendingTransientKey($videoId));
            self::log('posted post #' . $postId);
        } finally {
            delete_option($lockKey);
        }
    }

    public static function videoAlreadyHasPost(string $videoId): bool
    {
        $existing = get_posts([
            'post_type'              => 'post',
            'post_status'            => ['publish', 'draft', 'private', 'pending', 'future', 'trash'],
            'meta_key'               => '_newss_video_id',
            'meta_value'             => $videoId,
            'fields'                 => 'ids',
            'posts_per_page'         => 1,
            'no_found_rows'          => true,
            'cache_results'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        return !empty($existing);
    }

    public static function pendingTransientKey(string $videoId): string
    {
        return 'newss_pending_' . $videoId;
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
