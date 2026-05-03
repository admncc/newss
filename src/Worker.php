<?php

declare(strict_types=1);

namespace Newss;

final class Worker
{
    public const HOOK_PROCESS = 'newss_process_video';

    public static function register(): void
    {
        add_action(self::HOOK_PROCESS, [self::class, 'processVideo'], 10, 1);
    }

    public static function processVideo(array $payload): void
    {
        $videoId = (string) ($payload['video_id'] ?? '');
        if ($videoId === '') {
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
            return;
        }

        $transcript = (new Transcript())->fetch($videoId);
        if ($transcript === '' || mb_strlen($transcript) < 200) {
            error_log("[newss] transcript too short / missing for {$videoId}; skipping");
            return;
        }

        $rewrite = (new Anthropic())->rewrite($transcript, $payload);
        if (!$rewrite) {
            throw new \RuntimeException('Claude rewrite failed; will retry');
        }

        $blockedHits = self::blockedTopicHits($rewrite);
        if ($blockedHits !== []) {
            $action = (string) get_option('newss_blocked_action', 'skip');
            error_log(sprintf(
                '[newss] sensitive topics for %s: [%s] -> action=%s',
                $videoId,
                implode(',', $blockedHits),
                $action
            ));
            if ($action === 'skip') {
                return;
            }
            $rewrite['_force_draft'] = 1;
        }

        (new PostBuilder())->createPost($rewrite, $payload);
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
}
