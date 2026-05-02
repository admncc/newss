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

        (new PostBuilder())->createPost($rewrite, $payload);
    }
}
