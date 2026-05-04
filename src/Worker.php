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
            // 3-stufiger Topic-Filter (nur wirksam bei Aktion=skip,
            // bei Aktion=draft muss eh die volle Pipeline laufen):
            //   Stage 1 (gratis, lokal):  Stichwort-Heuristik auf Titel
            //   Stage 2 (~0,001 USD):     Haiku-Pre-Classify auf Titel+Channel
            //   Stage 3 (regulaer):       Post-Rewrite topic_tags-Check (unten)
            if ((int) get_option('newss_preclassify_enabled', 1) === 1
                && (string) get_option('newss_blocked_action', 'skip') === 'skip') {
                $blocked = array_values(array_filter((array) get_option('newss_blocked_topics', [])));
                if ($blocked !== []) {
                    $title   = (string) ($payload['video_title'] ?? '');
                    $channel = (string) ($payload['channel_name'] ?? '');

                    // Stage 1: Heuristik
                    $heur = Anthropic::heuristicTopicCheck($title, $blocked);
                    if ($heur !== []) {
                        self::skip('heuristic hit: ' . implode(',', $heur) . ' (Titel-Stichwort)', $videoId);
                        return;
                    }

                    // Stage 2: Haiku
                    $preTags = (new Anthropic())->preClassifyTopics($title, $channel);
                    if (is_array($preTags)) {
                        $hits = array_values(array_intersect($preTags, $blocked));
                        if ($hits !== []) {
                            self::skip('haiku-preclassify hit: ' . implode(',', $hits) . ' (kein Transcript geholt)', $videoId);
                            return;
                        }
                        self::log('preclassify ok: ' . ($preTags === [] ? 'no topics' : implode(',', $preTags)));
                    }
                }
            }

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
            delete_transient(Status::STATUS_COUNTS_CACHE_KEY);
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

    /**
     * Markiert AS-Actions die laenger als $thresholdSec auf in-progress
     * stehen als failed, gibt zugehoerige Locks und Pending-Transients
     * frei und entfernt newss_poll_running falls stale.
     *
     * @return array{cleared:int, jobs:array<int,array<string,string>>, poll_mutex:bool}
     */
    public static function cleanupStuckJobs(int $thresholdSec = 900): array
    {
        $out = ['cleared' => 0, 'jobs' => [], 'poll_mutex' => false];
        if (!class_exists('\\ActionScheduler')) {
            return $out;
        }
        try {
            $store = \ActionScheduler::store();
            $ids = (array) $store->query_actions([
                'hook'     => self::HOOK_PROCESS,
                'group'    => 'newss',
                'status'   => 'in-progress',
                'per_page' => 100,
                'order'    => 'ASC',
                'orderby'  => 'date',
            ]);
            $now = time();
            foreach ($ids as $aid) {
                $aid = (int) $aid;
                try {
                    $action = $store->fetch_action($aid);
                } catch (\Throwable) {
                    continue;
                }
                if (!$action) continue;
                $schedule = $action->get_schedule();
                $startTs = 0;
                if ($schedule && method_exists($schedule, 'get_date') && $schedule->get_date()) {
                    $startTs = $schedule->get_date()->getTimestamp();
                }
                if ($startTs === 0 || ($now - $startTs) < $thresholdSec) {
                    continue;
                }
                $args    = $action->get_args();
                $payload = $args[0] ?? [];
                $videoId = (string) ($payload['video_id'] ?? '');

                // Lock + Pending-Transient freigeben
                if ($videoId !== '') {
                    delete_option('newss_lock_' . $videoId);
                    delete_transient(self::pendingTransientKey($videoId));
                }

                // Action als failed markieren (AS zeigt sie dann im failed-Bucket)
                try {
                    $store->mark_failure($aid);
                    \ActionScheduler::logger()->log($aid, '[newss] stuck-cleanup: marked failed (age ' . ($now - $startTs) . 's)');
                } catch (\Throwable $e) {
                    error_log('[newss] cleanupStuckJobs mark_failure ' . $aid . ': ' . $e->getMessage());
                    continue;
                }

                $out['cleared']++;
                $out['jobs'][] = [
                    'action_id' => (string) $aid,
                    'video_id'  => $videoId,
                    'title'     => (string) ($payload['video_title'] ?? ''),
                    'age_sec'   => (string) ($now - $startTs),
                ];
            }
        } catch (\Throwable $e) {
            error_log('[newss] cleanupStuckJobs error: ' . $e->getMessage());
        }

        // Stale poll-mutex (>thresholdSec ohne progress-Option = sicher tot)
        $running = get_transient('newss_poll_running');
        if ($running && !get_option('newss_poll_progress')) {
            $startedTs = is_numeric($running) ? (int) $running : 0;
            if ($startedTs === 0 || (time() - $startedTs) > $thresholdSec) {
                delete_transient('newss_poll_running');
                $out['poll_mutex'] = true;
            }
        }

        delete_transient(Status::STATUS_COUNTS_CACHE_KEY);
        return $out;
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
