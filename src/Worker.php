<?php

declare(strict_types=1);

namespace Newss;

final class Worker
{
    public const HOOK_PROCESS = 'newss_process_video';

    private static int $currentActionId = 0;

    public static function setCurrentActionId(int $actionId): void
    {
        self::$currentActionId = $actionId;
    }

    public static function register(): void
    {
        add_action(self::HOOK_PROCESS, [self::class, 'processVideo'], 10, 1);
        add_action('action_scheduler_before_execute', static function ($actionId): void {
            self::$currentActionId = (int) $actionId;
        });
        // Auto-Cleanup: jedes Mal wenn AS-Runner anlaeuft, raeumen wir
        // Stuck-Jobs (>5 Min. in-progress) automatisch auf -- rate-limited
        // via Transient, damit's nicht bei jeder AS-Batch-Iteration laeuft.
        add_action('action_scheduler_before_process_queue', [self::class, 'maybeAutoCleanup']);
    }

    public static function maybeAutoCleanup(): void
    {
        if (get_transient('newss_auto_cleanup_ran')) {
            return;
        }
        // Marker bevor cleanup laeuft, damit ein evtl. Fatal nicht in
        // Endlosschleife resultiert
        set_transient('newss_auto_cleanup_ran', time(), 2 * MINUTE_IN_SECONDS);
        Logger::info('auto-cleanup: starting (threshold=5min)');
        $result = self::cleanupStuckJobs(5 * MINUTE_IN_SECONDS);
        if ($result['cleared'] > 0 || $result['poll_mutex'] || !empty($result['errors'])) {
            Logger::warn(sprintf(
                'auto-cleanup: %d stuck-job(s) failed-marked%s%s',
                $result['cleared'],
                $result['poll_mutex'] ? ' + poll-mutex cleared' : '',
                !empty($result['errors']) ? ' + errors: ' . implode(' | ', $result['errors']) : ''
            ));
            // Wenn was freigegeben wurde: wp-cron sofort triggern damit die
            // freigegebenen Slots nicht erst beim naechsten Plesk-Cron-Tick
            // (5 Min) wieder aufgefuellt werden.
            if ($result['cleared'] > 0) {
                wp_remote_get(home_url('/wp-cron.php?doing_wp_cron=' . time()), [
                    'timeout'   => 0.01,
                    'blocking'  => false,
                    'sslverify' => false,
                ]);
                Logger::info('auto-cleanup: wp-cron getriggered fuer freigegebene Slots');
            }
        } else {
            Logger::info('auto-cleanup: nothing to clean');
        }
    }

    public static function processVideo(array $payload): void
    {
        $videoId = (string) ($payload['video_id'] ?? '');
        Logger::info('worker: start video=' . $videoId, [
            'attempt' => (int) ($payload['attempt'] ?? 1),
            'channel' => (string) ($payload['channel_name'] ?? ''),
            'title'   => mb_substr((string) ($payload['video_title'] ?? ''), 0, 60),
        ]);
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

            // Duplicate-Detection: verschiedene Channels berichten oft ueber
            // dieselben Ereignisse. Wir prueft ob es in den letzten 24h schon
            // einen Newss-Post mit sehr aehnlichem Titel gibt -> skip damit
            // wir nicht 3x dieselbe News in verschiedenen Wordings publishen.
            // Vor dem Transcript-Fetch -> spart Whisper+Rewrite-Cost.
            if ((int) get_option('newss_dedup_enabled', 1) === 1) {
                $videoTitle = (string) ($payload['video_title'] ?? '');
                $windowH    = max(1, (int) get_option('newss_dedup_window_hours', 24));
                $threshold  = max(30, min(95, (int) get_option('newss_dedup_threshold', 65)));
                $dup = self::findRecentDuplicate($videoTitle, $windowH * HOUR_IN_SECONDS, $threshold);
                if ($dup !== null) {
                    self::skip(sprintf(
                        'duplicate topic (%d%% aehnlich zu Post #%d "%s")',
                        (int) $dup['similarity'],
                        (int) $dup['post_id'],
                        mb_substr((string) $dup['title'], 0, 80)
                    ), $videoId);
                    return;
                }
            }

            $tx = new Transcript();
            $transcript = $tx->fetch($videoId);
            if ($transcript === '' || mb_strlen($transcript) < 50) {
                $diag = self::formatAttemptLog($tx->attemptLog);
                self::handleEmptyTranscript($payload, $videoId, mb_strlen($transcript), $diag, $tx->attemptLog);
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
            Logger::info('worker: posted #' . $postId . ' video=' . $videoId, [
                'draft'   => $forceDraft,
                'channel' => (string) ($payload['channel_name'] ?? ''),
            ]);
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
     * Sucht Newss-Posts der letzten $windowSec mit sehr aehnlichem Titel.
     * Liefert die aehnlichste Zeile oder null wenn nichts drueber threshold.
     *
     * Similarity: PHP similar_text (Levenshtein-based, gibt Prozent).
     * Normalisierung vorher: lowercase, Punctuation weg, WP-typische Praefixe
     * ('EIL:', '🔴', 'LIVE:', etc.) strippen -- News-Kanaele haengen die oft
     * ans gleiche Ereignis dran.
     *
     * @return ?array{post_id:int, title:string, similarity:float}
     */
    private static function findRecentDuplicate(string $newTitle, int $windowSec, int $thresholdPercent): ?array
    {
        $newNorm = self::normalizeTitle($newTitle);
        if (mb_strlen($newNorm) < 15) {
            // Zu kurz fuer sinnvollen Vergleich (Shorts etc.)
            return null;
        }

        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', time() - $windowSec);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE pm.meta_key = %s
               AND p.post_type = 'post'
               AND p.post_status IN ('publish','draft','private','pending','future')
               AND p.post_date_gmt >= %s
             ORDER BY p.ID DESC
             LIMIT 100",
            '_newss_video_id',
            $since
        ), ARRAY_A) ?: [];

        $best = null;
        foreach ($rows as $r) {
            $existNorm = self::normalizeTitle((string) $r['post_title']);
            if (mb_strlen($existNorm) < 10) continue;
            similar_text($newNorm, $existNorm, $percent);
            if ($percent >= $thresholdPercent && ($best === null || $percent > $best['similarity'])) {
                $best = [
                    'post_id'    => (int) $r['ID'],
                    'title'      => (string) $r['post_title'],
                    'similarity' => (float) $percent,
                ];
            }
        }
        return $best;
    }

    private static function normalizeTitle(string $s): string
    {
        // News-Kanal-Praefixe/Sensationsworte strippen die den Similarity-Vergleich
        // verzerren wuerden ('EIL:', 'LIVE:', 'SCHOCK IN...', '🔴')
        $s = preg_replace('/\p{So}|\p{Cn}/u', '', $s) ?? $s; // Emojis + Symbole
        $s = preg_replace('/\b(EIL|LIVE|SCHOCK|EXKLUSIV|BREAKING|SKANDAL|IRRE|HAMMER|BILD LIVE|WELT NEWS)\s*[:|-]?/iu', '', $s) ?? $s;
        // Kanal-Namen die oft im Titel stehen
        $s = preg_replace('/\|\s*(tagesschau|tagesthemen|heute journal|Aktionaer|BILD|WELT|ntv|Focus).*$/iu', '', $s) ?? $s;
        // Interpunktion + Ziffern grob wegwerfen, Umlaute klein
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^\p{L}\s]/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * Wenn Transcript leer ist: pruefen ob Retry sinnvoll ist
     * (Video < 24h alt UND Versuch < 3) und ggf. neue AS-Action in
     * 2h bzw. 4h schedulen. Sonst permanent skippen.
     */
    private static function handleEmptyTranscript(array $payload, string $videoId, int $chars, string $diagnose, array $attemptLog = []): void
    {
        $attempt     = max(1, (int) ($payload['attempt'] ?? 1));
        $publishedTs = self::parsePublishedTs((string) ($payload['published'] ?? ''));
        $ageHours    = $publishedTs > 0 ? (time() - $publishedTs) / 3600 : 999;

        // Permanent-Fail-Detection: bei bestimmten Ursachen (Live-Stream,
        // Age-Restriction, private/geoblocked, Audio zu lang) helfen Retries
        // nachweislich nicht -> sofort skip statt +2h/+4h zu verplempern.
        $permanent = Transcript::classifyPermanentFail($attemptLog);
        if ($permanent !== null) {
            self::skip(sprintf(
                'transcript empty (permanent-fail: %s). %s',
                $permanent,
                $diagnose
            ), $videoId);
            return;
        }

        $shouldRetry = $attempt < 3 && $ageHours < 24 && function_exists('as_schedule_single_action');
        if ($shouldRetry) {
            $delay = $attempt === 1 ? 2 * HOUR_IN_SECONDS : 4 * HOUR_IN_SECONDS;
            $retryPayload = $payload;
            $retryPayload['attempt'] = $attempt + 1;

            \as_schedule_single_action(
                time() + $delay,
                self::HOOK_PROCESS,
                [$retryPayload],
                'newss'
            );
            // Pending-Transient verlaengern damit Channel-Poll das Video
            // nicht zwischendurch erneut enqueueed
            set_transient(self::pendingTransientKey($videoId), 1, 7 * DAY_IN_SECONDS);

            self::skip(sprintf(
                'transcript empty (%d chars, Versuch %d/3, Video %.1fh alt) — Retry in %dh geplant. %s',
                $chars,
                $attempt,
                $ageHours,
                (int) round($delay / HOUR_IN_SECONDS),
                $diagnose
            ), $videoId);
            return;
        }

        $reason = $attempt >= 3 ? 'nach 3 Versuchen' : ($ageHours >= 24 ? 'Video zu alt fuer Retry' : 'kein Retry moeglich');
        self::skip(sprintf(
            'transcript empty (%d chars, Versuch %d, %s). %s',
            $chars,
            $attempt,
            $reason,
            $diagnose
        ), $videoId);
    }

    /**
     * @param array<int,array{provider:string,ok:bool,detail:string}> $log
     */
    private static function formatAttemptLog(array $log): string
    {
        if ($log === []) {
            return 'kein Provider gerufen';
        }
        $parts = array_map(
            static fn(array $a): string => $a['provider'] . '=' . ($a['ok'] ? 'OK' : 'FAIL') . '(' . $a['detail'] . ')',
            $log
        );
        return implode(' | ', $parts);
    }

    private static function parsePublishedTs(string $iso): int
    {
        if ($iso === '') return 0;
        $ts = strtotime($iso);
        return $ts !== false ? $ts : 0;
    }

    /**
     * Markiert AS-Actions die laenger als $thresholdSec auf in-progress
     * stehen als failed, gibt zugehoerige Locks und Pending-Transients
     * frei und entfernt newss_poll_running falls stale.
     *
     * Direkter DB-Update statt store->mark_failure, weil der bei offenem
     * claim_id (Worker mid-flight gekillt) still fehlschlaegt.
     *
     * @return array{cleared:int, jobs:array<int,array<string,string>>, poll_mutex:bool, errors:array<int,string>}
     */
    public static function cleanupStuckJobs(int $thresholdSec = 300): array
    {
        global $wpdb;
        $out = ['cleared' => 0, 'jobs' => [], 'poll_mutex' => false, 'errors' => []];

        $actionsTable = $wpdb->prefix . 'actionscheduler_actions';
        $cutoffGmt = gmdate('Y-m-d H:i:s', time() - $thresholdSec);

        // last_attempt_gmt = wann der Worker zuletzt geclaimt+gestartet hat.
        // Wir nehmen NUR Actions die wirklich seit X Min. laufen, nicht
        // welche die nur lange in der Queue warten.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT action_id, args, scheduled_date_gmt, last_attempt_gmt, claim_id
             FROM {$actionsTable}
             WHERE hook = %s
               AND status = %s
               AND last_attempt_gmt IS NOT NULL
               AND last_attempt_gmt <> '0000-00-00 00:00:00'
               AND last_attempt_gmt <= %s
             ORDER BY last_attempt_gmt ASC
             LIMIT 100",
            self::HOOK_PROCESS,
            'in-progress',
            $cutoffGmt
        ), ARRAY_A) ?: [];

        foreach ($rows as $row) {
            $aid = (int) $row['action_id'];
            $args = json_decode((string) $row['args'], true);
            $payload = is_array($args) ? ($args[0] ?? []) : [];
            $videoId = (string) ($payload['video_id'] ?? '');
            $title   = (string) ($payload['video_title'] ?? '');

            $age = max(0, time() - (new \DateTime((string) $row['last_attempt_gmt'], new \DateTimeZone('UTC')))->getTimestamp());

            // Direkter UPDATE — funktioniert auch wenn claim_id != 0
            $updated = $wpdb->update(
                $actionsTable,
                ['status' => 'failed', 'claim_id' => 0],
                ['action_id' => $aid],
                ['%s', '%d'],
                ['%d']
            );
            if ($updated === false) {
                $out['errors'][] = "Action #{$aid}: SQL-Update failed (" . $wpdb->last_error . ')';
                continue;
            }

            // Lock + Pending-Transient freigeben
            if ($videoId !== '') {
                delete_option('newss_lock_' . $videoId);
                delete_transient(self::pendingTransientKey($videoId));
            }

            // Logger ist optional (AS muss geladen sein) — Best-effort
            if (class_exists('\\ActionScheduler') && method_exists('\\ActionScheduler', 'logger')) {
                try {
                    \ActionScheduler::logger()->log(
                        $aid,
                        sprintf('[newss] stuck-cleanup: marked failed via direct UPDATE (age %ds, claim_id=%d)', $age, (int) $row['claim_id'])
                    );
                } catch (\Throwable $e) {
                    error_log('[newss] cleanup log error #' . $aid . ': ' . $e->getMessage());
                }
            }

            $out['cleared']++;
            $out['jobs'][] = [
                'action_id' => (string) $aid,
                'video_id'  => $videoId,
                'title'     => $title,
                'age_sec'   => (string) $age,
            ];
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
        Logger::warn('worker: skip ' . ($videoId !== '' ? $videoId . ' ' : '') . $reason);
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
