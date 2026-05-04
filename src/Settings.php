<?php

declare(strict_types=1);

namespace Newss;

final class Settings
{
    private const OPTION_GROUP = 'newss_settings';

    private const SLUG_STATUS      = 'newss-settings';
    private const SLUG_PIPELINE    = 'newss-pipeline';
    private const SLUG_CHANNELS    = 'newss-channels';
    private const SLUG_CLAUDE      = 'newss-claude';
    private const SLUG_YOUTUBE     = 'newss-youtube';
    private const SLUG_TRANSCRIPT  = 'newss-transcript';
    private const SLUG_PUBLISHING  = 'newss-publishing';
    private const SLUG_HELP        = 'newss-help';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_newss_run_now', [self::class, 'handleRunNow']);
        add_action('admin_post_newss_test_whisper', [self::class, 'handleTestWhisper']);
        add_action('admin_post_newss_test_anthropic', [self::class, 'handleTestAnthropic']);
        add_action('admin_post_newss_test_youtube', [self::class, 'handleTestYoutube']);
        add_action('admin_post_newss_test_supadata', [self::class, 'handleTestSupadata']);
        add_action('wp_ajax_newss_poll_progress', [self::class, 'handleAjaxPollProgress']);
        add_action('wp_ajax_newss_pipeline_live', [self::class, 'handleAjaxPipelineLive']);
        add_action('admin_post_newss_cleanup_stuck', [self::class, 'handleCleanupStuck']);
        add_action('admin_post_newss_run_queue', [self::class, 'handleRunQueue']);
    }

    public static function handleRunQueue(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('newss_run_queue');

        set_transient('newss_pipeline_notice', [
            'type'    => 'success',
            'message' => 'Queue-Runner gestartet — laufende Pending-Jobs werden im Hintergrund abgearbeitet. Live-Box oben aktualisiert sich automatisch.',
        ], 30);

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG_PIPELINE));

        @ignore_user_abort(true);
        @set_time_limit(600);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            if (!headers_sent()) {
                header('Connection: close');
                header('Content-Length: 0');
            }
            while (ob_get_level() > 0) { @ob_end_flush(); }
            @flush();
        }

        self::runAsQueue();
        exit;
    }

    /**
     * Triggert den AS-QueueRunner im aktuellen Prozess.
     * Erwartet vorher fastcgi_finish_request() / Connection-Close,
     * sonst blockt der User-Redirect bis alle Pending-Jobs durch sind.
     */
    private static function runAsQueue(): void
    {
        global $wpdb;
        if (!class_exists('\\ActionScheduler')) {
            return;
        }

        // 1) Stale AS-Lock-Transients/Optionen aufraeumen die einen toten
        //    Vorgaenger-Run blockieren koennten
        delete_transient('action_scheduler_lock_runner');
        delete_transient('action_scheduler_lock_async-request-runner');
        delete_option('action_scheduler_lock_runner');
        delete_option('action_scheduler_lock_async-request-runner');

        // 2) Stale Claims aus actionscheduler_claims loeschen
        $claimsTable  = $wpdb->prefix . 'actionscheduler_claims';
        $actionsTable = $wpdb->prefix . 'actionscheduler_actions';
        $cutoff = gmdate('Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS);

        $staleClaims = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$claimsTable} WHERE date_created_gmt < %s",
            $cutoff
        ));
        if ($staleClaims > 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$actionsTable} a
                 INNER JOIN {$claimsTable} c ON a.claim_id = c.claim_id
                 SET a.claim_id = 0
                 WHERE c.date_created_gmt < %s",
                $cutoff
            ));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$claimsTable} WHERE date_created_gmt < %s",
                $cutoff
            ));
            error_log('[newss] runAsQueue: cleared ' . $staleClaims . ' stale AS claims');
        }

        // 3) Orphan claim_id auf Actions wo Claim-Row gar nicht mehr existiert
        $orphaned = (int) $wpdb->query(
            "UPDATE {$actionsTable} a
             LEFT JOIN {$claimsTable} c ON a.claim_id = c.claim_id
             SET a.claim_id = 0
             WHERE a.claim_id <> 0 AND c.claim_id IS NULL"
        );
        if ($orphaned > 0) {
            error_log('[newss] runAsQueue: cleared ' . $orphaned . ' orphan claim_id refs');
        }

        // 4) Diagnose: wieviele Pending-Jobs sind ueberhaupt due?
        $duePending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$actionsTable}
             WHERE hook = %s AND status = %s
               AND scheduled_date_gmt <= %s
               AND claim_id = 0",
            Worker::HOOK_PROCESS,
            'pending',
            gmdate('Y-m-d H:i:s')
        ));
        error_log('[newss] runAsQueue: ' . $duePending . ' due+unclaimed Newss-jobs vor dem Run');

        // 5) AS-Runner versuchen (bevorzugt, da er Logging + Hooks korrekt durchfuehrt)
        $totalProcessed = 0;
        for ($i = 0; $i < 5; $i++) {
            try {
                $count = (int) \ActionScheduler::runner()->run('Newss-Manual-' . ($i + 1));
                error_log('[newss] runAsQueue iter ' . ($i + 1) . ': AS-runner processed ' . $count);
                $totalProcessed += $count;
                if ($count === 0) break;
            } catch (\Throwable $e) {
                error_log('[newss] runAsQueue iter ' . ($i + 1) . ' exception: ' . $e->getMessage());
                break;
            }
        }

        // 6) Fallback: wenn AS nichts verarbeitet hat aber due-Jobs vorhanden
        //    sind, direkt per SQL+processVideo-Call arbeiten (umgeht AS-Claim-
        //    Mechanismus komplett -- letzte Notwehr)
        if ($totalProcessed === 0 && $duePending > 0) {
            error_log('[newss] runAsQueue: AS-runner inaktiv trotz ' . $duePending . ' due-jobs -- starte Direkt-Processor');
            $direct = self::processPendingDirectly(25);
            error_log('[newss] runAsQueue: Direkt-Processor erledigte ' . $direct);
        }
    }

    /**
     * AS umgehen: holt due-Pending-Newss-Jobs per direktem SQL, markiert
     * sie in-progress, ruft Worker::processVideo direkt auf, markiert sie
     * complete/failed. Logging an die AS-Log-Tabelle bleibt erhalten.
     *
     * Letzte Notwehr falls \ActionScheduler::runner()->run() durch interne
     * Lock-/Concurrency-Logik blockiert.
     */
    private static function processPendingDirectly(int $maxActions): int
    {
        global $wpdb;
        $actionsTable = $wpdb->prefix . 'actionscheduler_actions';
        $processed = 0;

        for ($i = 0; $i < $maxActions; $i++) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT action_id, args FROM {$actionsTable}
                 WHERE hook = %s AND status = %s
                   AND scheduled_date_gmt <= %s
                   AND claim_id = 0
                 ORDER BY scheduled_date_gmt ASC
                 LIMIT 1",
                Worker::HOOK_PROCESS,
                'pending',
                gmdate('Y-m-d H:i:s')
            ), ARRAY_A);
            if (!$row) {
                break;
            }
            $aid = (int) $row['action_id'];

            // Optimistic-Lock via UPDATE WHERE status='pending'
            $claimed = (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$actionsTable}
                 SET status = %s, last_attempt_gmt = %s
                 WHERE action_id = %d AND status = %s",
                'in-progress',
                gmdate('Y-m-d H:i:s'),
                $aid,
                'pending'
            ));
            if ($claimed !== 1) {
                continue; // jemand anderes hat ihn gerade gegriffen
            }

            $args = json_decode((string) $row['args'], true);
            $payload = is_array($args) && isset($args[0]) ? (array) $args[0] : [];

            Worker::setCurrentActionId($aid);
            try {
                if (class_exists('\\ActionScheduler') && method_exists('\\ActionScheduler', 'logger')) {
                    @\ActionScheduler::logger()->log($aid, '[newss] direct-processor: starting');
                }
                Worker::processVideo($payload);
                $wpdb->update(
                    $actionsTable,
                    ['status' => 'complete'],
                    ['action_id' => $aid],
                    ['%s'], ['%d']
                );
                $processed++;
            } catch (\Throwable $e) {
                error_log('[newss] direct-processor exception #' . $aid . ': ' . $e->getMessage());
                $wpdb->update(
                    $actionsTable,
                    ['status' => 'failed'],
                    ['action_id' => $aid],
                    ['%s'], ['%d']
                );
                if (class_exists('\\ActionScheduler') && method_exists('\\ActionScheduler', 'logger')) {
                    @\ActionScheduler::logger()->log($aid, '[newss] direct-processor exception: ' . $e->getMessage());
                }
            } finally {
                Worker::setCurrentActionId(0);
            }
        }
        return $processed;
    }

    public static function handleCleanupStuck(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('newss_cleanup_stuck');

        $threshold = max(60, (int) ($_POST['threshold'] ?? 900));
        $result = Worker::cleanupStuckJobs($threshold);

        $msg = sprintf(
            '%d Stuck-Job(s) als failed markiert (Threshold: %d Min.).%s',
            $result['cleared'],
            (int) round($threshold / 60),
            $result['poll_mutex'] ? ' Stale poll-Mutex zusaetzlich befreit.' : ''
        );
        if ($result['cleared'] > 0) {
            $titles = array_map(static fn(array $j): string => $j['title'] ?: $j['video_id'], $result['jobs']);
            $msg .= ' Betroffen: ' . implode(', ', array_slice($titles, 0, 5));
            if (count($titles) > 5) {
                $msg .= ' (+' . (count($titles) - 5) . ' weitere)';
            }
        }
        if (!empty($result['errors'])) {
            $msg .= ' FEHLER: ' . implode(' | ', $result['errors']);
        }
        $type = !empty($result['errors']) ? 'error' : ($result['cleared'] > 0 ? 'success' : 'info');
        if ($result['cleared'] > 0) {
            $msg .= ' Queue-Runner wird im Hintergrund gestartet.';
        }
        set_transient('newss_pipeline_notice', ['type' => $type, 'message' => $msg], 30);
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG_PIPELINE));

        // Bei erfolgreichem Cleanup: AS-Queue im Hintergrund anstossen,
        // damit die freigegebenen + bereits pending Jobs sofort laufen.
        if ($result['cleared'] > 0) {
            @ignore_user_abort(true);
            @set_time_limit(600);
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } else {
                if (!headers_sent()) {
                    header('Connection: close');
                    header('Content-Length: 0');
                }
                while (ob_get_level() > 0) { @ob_end_flush(); }
                @flush();
            }
            self::runAsQueue();
        }
        exit;
    }

    public static function handleAjaxPipelineLive(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        wp_send_json(Status::pipelineLiveData());
    }

    public static function handleTestAnthropic(): void
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('newss_test_anthropic');
        $key = sanitize_text_field(wp_unslash((string) ($_POST['anthropic_key'] ?? '')));
        $src = 'eingegeben';
        if ($key === '') { $key = (string) get_option('newss_anthropic_api_key', ''); $src = 'gespeichert'; }
        $lines = self::testInit($src, $key, 'GET https://api.anthropic.com/v1/models');
        if ($key === '') {
            self::testFinish('newss_anthropic_test', 'newss-claude', 'error', $lines, 'Kein Key — Feld leer + DB leer.');
        }
        $start = microtime(true);
        $resp = wp_remote_get('https://api.anthropic.com/v1/models', [
            'timeout' => 20,
            'headers' => ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'],
        ]);
        $lines[] = sprintf('Dauer: %d ms', (int) round((microtime(true) - $start) * 1000));
        if (is_wp_error($resp)) {
            $lines[] = 'Network-Fehler: ' . $resp->get_error_code() . ' — ' . $resp->get_error_message();
            self::testFinish('newss_anthropic_test', 'newss-claude', 'error', $lines);
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        $lines[] = 'HTTP-Status: ' . $code;
        if ($code === 200) {
            $data = json_decode($body, true);
            $models = is_array($data) && isset($data['data']) ? array_map(static fn($m) => (string) ($m['id'] ?? ''), $data['data']) : [];
            $hasSonnet = (bool) array_filter($models, static fn(string $id): bool => str_contains($id, 'sonnet'));
            $lines[] = 'Modelle erreichbar: ' . count($models);
            if ($models) $lines[] = 'Beispiele: ' . implode(', ', array_slice($models, 0, 5));
            self::testFinish('newss_anthropic_test', 'newss-claude', $hasSonnet ? 'success' : 'warning', $lines);
        }
        $err = self::extractApiError($body);
        $lines = array_merge($lines, $err['lines']);
        $lines[] = '→ Hinweis: ' . match ($err['code']) {
            'authentication_error' => 'Key falsch / widerrufen / Format-Fehler.',
            'permission_error'     => 'Account-Permission fehlt — Anthropic-Console prüfen.',
            'rate_limit_error'     => 'Rate-Limit überschritten — kurz warten.',
            'invalid_request_error'=> 'Anfrage-Fehler — eventuell falsche Anthropic-Version-Header.',
            default                => 'Wenn Key OK aussieht: Console > Settings > API Keys prüfen.',
        };
        self::testFinish('newss_anthropic_test', 'newss-claude', 'error', $lines);
    }

    public static function handleTestYoutube(): void
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('newss_test_youtube');
        $key = sanitize_text_field(wp_unslash((string) ($_POST['youtube_key'] ?? '')));
        $src = 'eingegeben';
        if ($key === '') { $key = (string) get_option('newss_youtube_api_key', ''); $src = 'gespeichert'; }
        $lines = self::testInit($src, $key, 'GET channels.list?id=UCBR8-...');
        if ($key === '') {
            self::testFinish('newss_youtube_test', 'newss-youtube', 'error', $lines, 'Kein Key — Feld leer + DB leer.');
        }
        $start = microtime(true);
        $url = add_query_arg([
            'part' => 'snippet',
            'id'   => 'UCBR8-60-B28hp2BmDPdntcQ',
            'key'  => $key,
        ], 'https://www.googleapis.com/youtube/v3/channels');
        $resp = wp_remote_get($url, ['timeout' => 20]);
        $lines[] = sprintf('Dauer: %d ms', (int) round((microtime(true) - $start) * 1000));
        if (is_wp_error($resp)) {
            $lines[] = 'Network-Fehler: ' . $resp->get_error_code() . ' — ' . $resp->get_error_message();
            self::testFinish('newss_youtube_test', 'newss-youtube', 'error', $lines);
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        $lines[] = 'HTTP-Status: ' . $code;
        if ($code === 200) {
            $data = json_decode($body, true);
            $title = $data['items'][0]['snippet']['title'] ?? '';
            $lines[] = 'Test-Channel aufgelöst: ' . ($title ?: '(kein Titel)');
            self::testFinish('newss_youtube_test', 'newss-youtube', 'success', $lines);
        }
        $err = self::extractGoogleError($body);
        $lines = array_merge($lines, $err['lines']);
        $lines[] = '→ Hinweis: ' . match ($err['reason']) {
            'API_KEY_INVALID', 'keyInvalid'     => 'Key falsch oder widerrufen.',
            'ipRefererBlocked', 'API_KEY_HTTP_REFERRER_BLOCKED' => 'Key-Restriction blockiert deine Server-IP.',
            'accessNotConfigured'                => 'YouTube Data API v3 ist im Project nicht aktiviert.',
            'quotaExceeded', 'dailyLimitExceeded'=> 'Quota erschöpft — Pacific Midnight resetten.',
            default                              => 'Permissions im Google-Cloud-Project prüfen.',
        };
        self::testFinish('newss_youtube_test', 'newss-youtube', 'error', $lines);
    }

    public static function handleTestSupadata(): void
    {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('newss_test_supadata');
        $key = sanitize_text_field(wp_unslash((string) ($_POST['supadata_key'] ?? '')));
        $src = 'eingegeben';
        if ($key === '') { $key = (string) get_option('newss_supadata_api_key', ''); $src = 'gespeichert'; }
        $lines = self::testInit($src, $key, 'GET supadata.ai/v1/youtube/transcript');
        if ($key === '') {
            self::testFinish('newss_supadata_test', 'newss-transcript', 'error', $lines, 'Kein Key — Feld leer + DB leer.');
        }
        $start = microtime(true);
        // jNQXAC9IVRw = "Me at the zoo", erstes YouTube-Video, hat Captions
        $url = add_query_arg([
            'url'  => 'https://www.youtube.com/watch?v=jNQXAC9IVRw',
            'lang' => 'en',
            'text' => 'true',
        ], 'https://api.supadata.ai/v1/youtube/transcript');
        $resp = wp_remote_get($url, ['timeout' => 30, 'headers' => ['x-api-key' => $key]]);
        $lines[] = sprintf('Dauer: %d ms', (int) round((microtime(true) - $start) * 1000));
        if (is_wp_error($resp)) {
            $lines[] = 'Network-Fehler: ' . $resp->get_error_code() . ' — ' . $resp->get_error_message();
            self::testFinish('newss_supadata_test', 'newss-transcript', 'error', $lines);
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        $lines[] = 'HTTP-Status: ' . $code;
        if ($code === 200) {
            $data = json_decode($body, true);
            $text = (string) ($data['content'] ?? $data['text'] ?? '');
            $lines[] = 'Transkript-Länge: ' . strlen($text) . ' Zeichen';
            if ($text) $lines[] = 'Snippet: ' . substr($text, 0, 100);
            self::testFinish('newss_supadata_test', 'newss-transcript', $text !== '' ? 'success' : 'warning', $lines);
        }
        $err = self::extractApiError($body);
        $lines = array_merge($lines, $err['lines']);
        $lines[] = '→ Hinweis: ' . match ($err['code']) {
            'invalid-request', 'unauthorized' => 'Key falsch oder fehlt.',
            'limit-exceeded'                   => 'Plan-Limit erreicht — Subscription upgraden oder warten.',
            'forbidden'                        => 'Geo-Block — Supadata kann das Video aus seiner Region nicht abrufen.',
            'transcript-unavailable'           => 'Test-Video hat keine Captions — Key war OK, war nur ein Pech-Test.',
            default                            => 'Supadata-Doku & Account-Status prüfen.',
        };
        self::testFinish('newss_supadata_test', 'newss-transcript', 'error', $lines);
    }

    private static function testInit(string $src, string $key, string $endpoint): array
    {
        return [
            'Key-Quelle: ' . $src,
            'Key-Prefix: ' . substr($key, 0, 6) . '… (Länge: ' . strlen($key) . ')',
            'Endpunkt: ' . $endpoint,
        ];
    }

    private static function extractApiError(string $body): array
    {
        $data = json_decode($body, true);
        $msg = $code = $type = '';
        if (is_array($data) && isset($data['error'])) {
            if (is_array($data['error'])) {
                $msg = (string) ($data['error']['message'] ?? '');
                $code = (string) ($data['error']['code'] ?? $data['error']['type'] ?? '');
                $type = (string) ($data['error']['type'] ?? '');
            } else {
                $code = (string) $data['error'];
                $msg = (string) ($data['message'] ?? '');
            }
        }
        return [
            'code' => $code,
            'lines' => [
                'error.code: ' . ($code ?: '—'),
                'error.type: ' . ($type ?: '—'),
                'error.message: ' . ($msg ?: '(keine)'),
                'Body-Auszug: ' . substr($body, 0, 400),
            ],
        ];
    }

    private static function extractGoogleError(string $body): array
    {
        $data = json_decode($body, true);
        $reason = $msg = '';
        if (is_array($data) && isset($data['error'])) {
            $msg = (string) ($data['error']['message'] ?? '');
            if (is_array($data['error']['errors'] ?? null)) {
                $reason = (string) ($data['error']['errors'][0]['reason'] ?? '');
            }
            if ($reason === '' && isset($data['error']['status'])) {
                $reason = (string) $data['error']['status'];
            }
        }
        return [
            'reason' => $reason,
            'lines' => [
                'error.reason: ' . ($reason ?: '—'),
                'error.message: ' . ($msg ?: '(keine)'),
                'Body-Auszug: ' . substr($body, 0, 400),
            ],
        ];
    }

    private static function testFinish(string $transientKey, string $page, string $type, array $lines, ?string $msg = null): void
    {
        if ($msg !== null) {
            $lines = [$msg];
        }
        set_transient($transientKey, ['type' => $type, 'lines' => $lines], 120);
        self::redirectTo($page);
    }

    /**
     * Rendert Button + Result-Box. Form-Submit nimmt aktuellen Input-Value
     * (per JS-Snapshot ins hidden field) damit man auch ohne Save testen kann.
     */
    private static function renderApiTestButton(string $action, string $nonceKey, string $hiddenFieldName, string $inputId, string $transientKey, string $hint = ''): void
    {
        $notice = get_transient($transientKey);
        if ($notice) delete_transient($transientKey);
        $formId = $action . '_form';
        $snapId = $action . '_snap';
        ?>
        <div style="margin-top:8px">
            <button type="button" class="button" onclick="(function(){var s=document.getElementById('<?php echo esc_js($snapId); ?>');var i=document.getElementById('<?php echo esc_js($inputId); ?>');s.value=i.value;document.getElementById('<?php echo esc_js($formId); ?>').submit();})();">Key testen</button>
            <?php if ($hint !== ''): ?>
                <span class="description">— <?php echo esc_html($hint); ?></span>
            <?php endif; ?>
        </div>
        <?php if (is_array($notice) && !empty($notice['lines'])): ?>
            <div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> inline" style="margin-top:10px;padding:10px 14px">
                <p style="margin:0 0 6px 0"><strong>Test-Ergebnis:</strong></p>
                <pre style="margin:0;background:#f6f7f7;padding:8px;font-size:11px;line-height:1.5;white-space:pre-wrap;word-break:break-all"><?php
                    foreach ((array) $notice['lines'] as $line) {
                        echo esc_html((string) $line) . "\n";
                    }
                ?></pre>
            </div>
        <?php endif; ?>
        <?php
    }

    private static function renderApiTestHiddenForm(string $action, string $nonceKey, string $hiddenFieldName): void
    {
        $formId = $action . '_form';
        $snapId = $action . '_snap';
        ?>
        <form id="<?php echo esc_attr($formId); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="<?php echo esc_attr($hiddenFieldName); ?>" value="" id="<?php echo esc_attr($snapId); ?>">
            <?php wp_nonce_field($nonceKey); ?>
        </form>
        <?php
    }

    public static function handleAjaxPollProgress(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }
        $progress = get_option('newss_poll_progress', null);
        $inProgress = is_array($progress);
        // Queued-Marker loeschen sobald Worker tatsaechlich angefangen hat
        if ($inProgress && get_transient('newss_poll_queued')) {
            delete_transient('newss_poll_queued');
        }
        wp_send_json([
            'in_progress' => $inProgress,
            'queued'      => !$inProgress && (bool) get_transient('newss_poll_queued'),
            'progress'    => $progress,
            'last_poll'   => get_option('newss_last_poll', null),
        ]);
    }

    public static function addMenu(): void
    {
        add_menu_page(
            'Newss',
            'Newss',
            'manage_options',
            self::SLUG_STATUS,
            [self::class, 'renderStatusPage'],
            'dashicons-megaphone',
            58
        );
        add_submenu_page(self::SLUG_STATUS, 'Newss · Status',          'Status',           'manage_options', self::SLUG_STATUS,     [self::class, 'renderStatusPage']);
        add_submenu_page(self::SLUG_STATUS, 'Newss · Job-Pipeline',    'Job-Pipeline',     'manage_options', self::SLUG_PIPELINE,   [self::class, 'renderPipelinePage']);
        add_submenu_page(self::SLUG_STATUS, 'Newss · Kanäle',          'Kanäle',           'manage_options', self::SLUG_CHANNELS,   [self::class, 'renderChannelsPage']);
        add_submenu_page(self::SLUG_STATUS, 'Newss · YouTube',         'YouTube',          'manage_options', self::SLUG_YOUTUBE,    [self::class, 'renderYoutubePage']);
        add_submenu_page(self::SLUG_STATUS, 'Newss · Transkript',      'Transkript',       'manage_options', self::SLUG_TRANSCRIPT, [self::class, 'renderTranscriptPage']);
        add_submenu_page(self::SLUG_STATUS, 'Newss · Claude',          'Claude',           'manage_options', self::SLUG_CLAUDE,     [self::class, 'renderClaudePage']);
        add_submenu_page(self::SLUG_STATUS, 'Newss · Veröffentlichung','Veröffentlichung', 'manage_options', self::SLUG_PUBLISHING, [self::class, 'renderPublishingPage']);
        add_submenu_page(self::SLUG_STATUS, 'Newss · Hilfe',           'Hilfe',            'manage_options', self::SLUG_HELP,       [self::class, 'renderHelpPage']);
    }

    public static function registerSettings(): void
    {
        register_setting(self::OPTION_GROUP, 'newss_anthropic_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
            'show_in_rest'      => false,
            'default'           => '',
            'autoload'          => false,
        ]);
        register_setting(self::OPTION_GROUP, 'newss_supadata_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
            'autoload'          => false,
        ]);
        register_setting(self::OPTION_GROUP, 'newss_whisper_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
            'autoload'          => false,
        ]);
        register_setting(self::OPTION_GROUP, 'newss_youtube_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
            'autoload'          => false,
        ]);
        register_setting(self::OPTION_GROUP, 'newss_youtube_method', [
            'sanitize_callback' => [self::class, 'sanitizeYoutubeMethod'],
            'default'           => 'rss',
        ]);

        $opts = [
            'newss_anthropic_model'        => 'sanitize_text_field',
            'newss_anthropic_max_tokens'   => 'absint',
            'newss_anthropic_daily_cap'    => 'absint',
            'newss_anthropic_temperature'  => [self::class, 'sanitizeTemperature'],
            'newss_system_prompt'          => [self::class, 'sanitizeMultiline'],
            'newss_user_prompt_template'   => [self::class, 'sanitizeMultiline'],
            'newss_youtube_proxy'          => [self::class, 'sanitizeMultiline'],
            'newss_youtube_cookie'         => 'sanitize_text_field',
            'newss_ytdlp_path'             => 'sanitize_text_field',
            'newss_whisper_enabled'        => 'absint',
            'newss_whisper_daily_cap'      => 'absint',
            'newss_default_category'       => 'absint',
            'newss_category_list'          => [self::class, 'sanitizeMultiline'],
            'newss_blocked_topics'         => [self::class, 'sanitizeBlockedTopics'],
            'newss_blocked_action'         => [self::class, 'sanitizeBlockedAction'],
            'newss_preclassify_enabled'    => 'absint',
            'newss_default_status'         => [self::class, 'sanitizeStatus'],
            'newss_kill_switch_drafts'     => 'absint',
            'newss_post_author'            => 'absint',
        ];
        foreach ($opts as $opt => $cb) {
            register_setting(self::OPTION_GROUP, $opt, ['sanitize_callback' => $cb]);
        }
    }

    public static function sanitizeTemperature($value): float    { return max(0.0, min(1.0, (float) $value)); }
    public static function sanitizeMultiline($value): string     { return wp_kses_post((string) $value); }
    public static function sanitizeStatus($value): string        { return in_array($value, ['publish', 'draft'], true) ? $value : 'publish'; }
    public static function sanitizeYoutubeMethod($value): string { return in_array($value, ['api', 'rss'], true) ? $value : 'rss'; }
    public static function sanitizeBlockedAction($value): string { return in_array($value, ['skip', 'draft'], true) ? $value : 'skip'; }
    public static function sanitizeBlockedTopics($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $allowed = array_keys(Anthropic::topicLabels());
        return array_values(array_intersect(array_map('strval', $value), $allowed));
    }

    public static function handleRunNow(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('newss_run_now');
        error_log('[newss] handleRunNow: enter');

        // Stale-Mutex-Cleanup: wenn ein vorheriger Run abgestuerzt ist
        // (PHP-Timeout, Worker-Kill etc.), bleibt newss_poll_running stehen
        // und blockt jeden weiteren Lauf bis zum Transient-Expire (30 Min).
        // -> Wenn keine Progress-Option vorhanden, ist der Run definitiv tot.
        $running = get_transient('newss_poll_running');
        if ($running && !get_option('newss_poll_progress')) {
            delete_transient('newss_poll_running');
            error_log('[newss] handleRunNow: cleared stale newss_poll_running mutex (no progress)');
        }

        // Marker fuer UI: Klick-Zeit, AJAX zeigt 'In Queue'-Box bis pollAll
        // tatsaechlich Progress schreibt.
        set_transient('newss_poll_queued', time(), 5 * MINUTE_IN_SECONDS);

        wp_safe_redirect(add_query_arg(['ran' => 'queued'], admin_url('admin.php?page=' . self::SLUG_STATUS)));

        // Antwort an Browser zuruecksenden BEVOR pollAll laeuft (kann
        // mehrere Minuten dauern). Browser sieht Status-Page mit Live-Bar
        // sofort, waehrend pollAll im Hintergrund tatsaechlich pollt.
        // Reihenfolge der Detach-Calls ist wichtig:
        //  1) Headers + Body raus
        //  2) PHP-FPM: fastcgi_finish_request schliesst die Connection
        //  3) Apache mod_php: Content-Length + flush ist die zuverlaessigste
        //     Variante (kein fastcgi_finish_request verfuegbar)
        @ignore_user_abort(true);
        @set_time_limit(600);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            // Apache mod_php Fallback
            if (!headers_sent()) {
                header('Connection: close');
                header('Content-Length: 0');
            }
            while (ob_get_level() > 0) { @ob_end_flush(); }
            @flush();
        }

        error_log('[newss] handleRunNow: detached, calling pollAll() now');
        // pollAll direkt aufrufen statt via Action-Scheduler.
        // Grund: bei DISABLE_WP_CRON=true triggert AS nur per System-Cron-Tick,
        // d.h. as_enqueue_async_action laesst den Job in der Queue liegen.
        // Direkter Call ist deterministisch und blockt den User nicht
        // (wir sind nach detach in einem detached PHP-Prozess).
        try {
            RssPoller::pollAll();
            error_log('[newss] handleRunNow: pollAll() returned cleanly');
        } catch (\Throwable $e) {
            error_log('[newss] handleRunNow: pollAll exception: ' . $e->getMessage());
        }
        delete_transient('newss_poll_queued');
        exit;
    }

    public static function handleTestWhisper(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('newss_test_whisper');

        $key = sanitize_text_field(wp_unslash((string) ($_POST['whisper_key'] ?? '')));
        $keySource = 'eingegeben';
        if ($key === '') {
            $key = (string) get_option('newss_whisper_api_key', '');
            $keySource = 'gespeichert';
        }
        if ($key === '') {
            set_transient('newss_whisper_test', ['type' => 'error', 'lines' => ['Kein Key gesetzt — sowohl Input-Feld als auch DB sind leer.']], 60);
            self::redirectTo(self::SLUG_TRANSCRIPT);
        }

        $keyPrefix = substr($key, 0, 6);
        $keyLen    = strlen($key);
        $startedAt = microtime(true);

        $resp = wp_remote_get('https://api.openai.com/v1/models', [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $key],
        ]);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $lines = [
            sprintf('Key-Quelle: %s', $keySource),
            sprintf('Key-Prefix: %s… (Länge: %d Zeichen)', $keyPrefix, $keyLen),
            'Endpunkt: GET https://api.openai.com/v1/models',
            sprintf('Dauer: %d ms', $elapsedMs),
        ];

        if (is_wp_error($resp)) {
            $lines[] = 'Network-Fehler (wp_error): ' . $resp->get_error_code() . ' — ' . $resp->get_error_message();
            set_transient('newss_whisper_test', ['type' => 'error', 'lines' => $lines], 120);
            self::redirectTo(self::SLUG_TRANSCRIPT);
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        $hdrs = wp_remote_retrieve_headers($resp);

        $lines[] = sprintf('HTTP-Status: %d', $code);
        if (is_object($hdrs) && method_exists($hdrs, 'offsetGet')) {
            $org = (string) ($hdrs['openai-organization'] ?? '');
            if ($org !== '') $lines[] = 'OpenAI-Org: ' . $org;
            $rate = (string) ($hdrs['x-ratelimit-remaining-requests'] ?? '');
            if ($rate !== '') $lines[] = 'Rate-Limit-Remaining: ' . $rate;
        }

        if ($code === 200) {
            $data = json_decode($body, true);
            $modelCount = is_array($data) && isset($data['data']) ? count($data['data']) : 0;
            $whisperModels = [];
            foreach (($data['data'] ?? []) as $m) {
                $id = (string) ($m['id'] ?? '');
                if (str_contains($id, 'whisper')) $whisperModels[] = $id;
            }
            $lines[] = sprintf('Modelle erreichbar: %d', $modelCount);
            $lines[] = $whisperModels !== []
                ? 'Whisper-Modelle: ' . implode(', ', $whisperModels)
                : 'Achtung: whisper-1 nicht in der Liste — Key-Permissions decken Audio nicht ab.';
            set_transient('newss_whisper_test', [
                'type'  => $whisperModels !== [] ? 'success' : 'warning',
                'lines' => $lines,
            ], 120);
            self::redirectTo(self::SLUG_TRANSCRIPT);
        }

        $errMsg = $errCode = $errType = '';
        $data = json_decode($body, true);
        if (is_array($data) && isset($data['error'])) {
            $errMsg  = (string) ($data['error']['message'] ?? '');
            $errCode = (string) ($data['error']['code'] ?? '');
            $errType = (string) ($data['error']['type'] ?? '');
        }
        $lines[] = sprintf('error.code: %s', $errCode ?: '—');
        $lines[] = sprintf('error.type: %s', $errType ?: '—');
        $lines[] = 'error.message: ' . ($errMsg ?: '(keine)');
        $lines[] = 'Body-Auszug: ' . substr($body, 0, 500);
        $hint = match ($errCode) {
            'invalid_api_key'     => 'Key existiert nicht / widerrufen / Tippfehler. Neuen Key auf platform.openai.com/api-keys erstellen.',
            'insufficient_quota'  => 'Account hat kein Guthaben → platform.openai.com/account/billing/overview → Add Credits.',
            'rate_limit_exceeded' => 'Aktuelles Rate-Limit überschritten — kurz warten und retesten.',
            default               => 'Wenn Key OK aussieht: Permissions prüfen — restricted Keys brauchen explizit "Model capabilities → Audio".',
        };
        $lines[] = '→ Hinweis: ' . $hint;

        set_transient('newss_whisper_test', ['type' => 'error', 'lines' => $lines], 120);
        self::redirectTo(self::SLUG_TRANSCRIPT);
    }

    private static function redirectTo(string $slug): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . $slug));
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Pages
    // ─────────────────────────────────────────────────────────────────────

    public static function renderStatusPage(): void
    {
        if (!current_user_can('manage_options')) return;

        $ytdlp = (string) get_option('newss_ytdlp_path', 'yt-dlp');
        $ytDlpFound = self::detectBinary($ytdlp);
        $ffmpegFound = self::detectBinary('ffmpeg');
        $lastPoll = get_option('newss_last_poll', null);
        $nextRun  = wp_next_scheduled(Cron::HOOK_PERIODIC);
        $runUrl = wp_nonce_url(admin_url('admin-post.php?action=newss_run_now'), 'newss_run_now');
        ?>
        <div class="wrap">
            <h1>Newss · Status</h1>

            <?php if (isset($_GET['ran'])):
                $flag = sanitize_key(wp_unslash((string) $_GET['ran'])); ?>
                <div class="notice notice-success is-dismissible">
                    <?php if ($flag === 'queued'): ?>
                        <p>RSS-Polling wurde in die Hintergrund-Queue gelegt — läuft asynchron. Status oben aktualisiert sich live.</p>
                    <?php else: ?>
                        <p>RSS-Polling wurde ausgeführt.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php
            Status::renderOnboarding();
            Status::renderHealthTiles();
            Status::renderKpiTiles();
            ?>

            <h2>Server-Status</h2>
            <table class="widefat striped" style="max-width:780px">
                <tbody>
                <tr><th>yt-dlp</th><td><?php echo $ytDlpFound ? '<span style="color:#0a7">gefunden</span>' : '<span style="color:#c00">nicht gefunden</span>'; ?> (<code><?php echo esc_html($ytdlp); ?></code>)</td></tr>
                <tr><th>ffmpeg</th><td><?php echo $ffmpegFound ? '<span style="color:#0a7">gefunden</span>' : '<span style="color:#c00">nicht gefunden</span>'; ?></td></tr>
                <tr><th>Nächster Lauf (alle 3h, 8× täglich)</th><td><?php echo $nextRun ? esc_html(self::formatTime($nextRun)) : '—'; ?></td></tr>
                <?php
                $lastCron = (int) get_option('newss_last_cron_run', 0);
                $cronAge = $lastCron > 0 ? time() - $lastCron : -1;
                $cronColor = $cronAge < 0 ? '#c00' : ($cronAge > 16 * HOUR_IN_SECONDS ? '#c00' : ($cronAge > 12 * HOUR_IN_SECONDS ? '#b87000' : '#0a7'));
                ?>
                <tr><th>Letzter Cron-Lauf</th><td>
                    <?php if ($lastCron > 0): ?>
                        <span style="color:<?php echo esc_attr($cronColor); ?>">
                            <?php echo esc_html(Status::timeAgoDe($lastCron)); ?>
                        </span>
                        <span style="color:#999">(<?php echo esc_html(self::formatTime($lastCron)); ?>)</span>
                        <?php if ($cronAge > 16 * HOUR_IN_SECONDS): ?>
                            — <strong style="color:#c00">⚠ länger als 16h — Cron läuft nicht!</strong>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:#c00">noch nie — System-Cron prüfen oder „Jetzt manuell pollen"</span>
                    <?php endif; ?>
                </td></tr>
                <tr><th>Letztes Polling</th><td><?php
                    if (is_array($lastPoll)) {
                        $s = $lastPoll['stats'];
                        printf(
                            '%s (%s) — Kanäle: %d, neue Videos: %d, Fehler: %d',
                            esc_html(self::formatTime((int) $lastPoll['time'])),
                            esc_html(Status::timeAgoDe((int) $lastPoll['time'])),
                            (int) $s['channels'], (int) $s['new'], (int) $s['errors']
                        );
                    } else { echo '—'; }
                ?></td></tr>
                </tbody>
            </table>

            <?php
            $providers = [
                'youtube_api' => 'YouTube Data API',
                'supadata'    => 'Supadata',
                'whisper'     => 'OpenAI Whisper',
            ];
            $hasAnyHealth = false;
            foreach ($providers as $key => $_label) {
                if (get_option(Transcript::HEALTH_OPTION_PREFIX . $key, null)) {
                    $hasAnyHealth = true;
                    break;
                }
            }
            if ($hasAnyHealth):
            ?>
            <h2 style="margin-top:24px">Provider-Health</h2>
            <table class="widefat striped" style="max-width:780px">
                <thead><tr><th style="width:30%">Provider</th><th style="width:80px">Status</th><th>Letzter Call</th><th>Hinweis</th></tr></thead>
                <tbody>
                <?php foreach ($providers as $key => $label):
                    $h = get_option(Transcript::HEALTH_OPTION_PREFIX . $key, null);
                    if (!is_array($h)) continue;
                    $okBadge = !empty($h['ok'])
                        ? '<span style="color:#0a7">✓ OK</span>'
                        : '<span style="color:#c00">✗ Fehler</span>';
                    $age = time() - (int) ($h['ts'] ?? 0);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($label); ?></strong></td>
                        <td><?php echo $okBadge; ?></td>
                        <td style="font-size:11px"><?php echo esc_html(Status::timeAgoDe((int) ($h['ts'] ?? 0))); ?></td>
                        <td style="font-size:11px"><?php echo esc_html((string) ($h['note'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <p><a href="<?php echo esc_url($runUrl); ?>" class="button button-secondary">Jetzt manuell pollen</a></p>

            <p style="background:#fff8e1;border-left:4px solid #ffb900;padding:8px 12px;max-width:780px">
                <strong>Empfehlung:</strong> Trag auf dem Server diesen System-Cron ein und deaktiviere WP-Cron in <code>wp-config.php</code>:<br>
                <code>0 */3 * * * curl -s <?php echo esc_html(home_url('/wp-cron.php?doing_wp_cron')); ?> &gt; /dev/null</code><br>
                <code>define('DISABLE_WP_CRON', true);</code>
            </p>

            <?php Status::renderChannelPoll(); ?>

            <?php Updater::renderSection(); ?>
        </div>
        <?php
    }

    public static function renderPipelinePage(): void
    {
        if (!current_user_can('manage_options')) return;
        $notice = get_transient('newss_pipeline_notice');
        if ($notice) {
            delete_transient('newss_pipeline_notice');
        }
        ?>
        <div class="wrap">
            <h1>Newss · Job-Pipeline</h1>
            <?php if (is_array($notice)): ?>
                <div class="notice notice-<?php echo esc_attr((string) $notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html((string) $notice['message']); ?></p>
                </div>
            <?php endif; ?>
            <?php Status::renderPipeline(); ?>
        </div>
        <?php
    }

    public static function renderChannelsPage(): void
    {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1>Newss · Kanäle</h1>
            <?php Channels::renderSection(); ?>
        </div>
        <?php
    }

    public static function renderClaudePage(): void
    {
        if (!current_user_can('manage_options')) return;

        $models = [
            'claude-opus-4-7'   => 'Claude Opus 4.7 (höchste Qualität)',
            'claude-sonnet-4-6' => 'Claude Sonnet 4.6 (Standard, empfohlen)',
            'claude-haiku-4-5'  => 'Claude Haiku 4.5 (schnell, günstig)',
        ];
        $apiKey       = (string) get_option('newss_anthropic_api_key', '');
        $model        = (string) get_option('newss_anthropic_model', 'claude-sonnet-4-6');
        $maxTokens    = (int)    get_option('newss_anthropic_max_tokens', 4000);
        $temperature  = (float)  get_option('newss_anthropic_temperature', 1.0);
        $systemPrompt = (string) get_option('newss_system_prompt', Anthropic::defaultSystemPrompt());
        $userTpl      = (string) get_option('newss_user_prompt_template', Anthropic::defaultUserTemplate());
        $dailyCap     = (int)    get_option('newss_anthropic_daily_cap', 0);
        $callsToday   = get_option('newss_anthropic_calls_today', null);
        $todayCount   = (is_array($callsToday) && ($callsToday['date'] ?? '') === wp_date('Y-m-d')) ? (int) $callsToday['count'] : 0;
        ?>
        <div class="wrap">
            <h1>Newss · Claude (Anthropic)</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="newss_anthropic_api_key">API-Key</label></th>
                        <td>
                            <input type="password" id="newss_anthropic_api_key" name="newss_anthropic_api_key" value="<?php echo esc_attr($apiKey); ?>" class="regular-text" autocomplete="off">
                            <?php self::renderApiTestButton('newss_test_anthropic', 'newss_test_anthropic', 'anthropic_key', 'newss_anthropic_api_key', 'newss_anthropic_test', 'Test-Call gegen /v1/models'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="newss_anthropic_model">Modell</label></th>
                        <td>
                            <select id="newss_anthropic_model" name="newss_anthropic_model">
                                <?php foreach ($models as $id => $label): ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($model, $id); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="newss_anthropic_max_tokens">Max. Tokens</label></th>
                        <td><input type="number" id="newss_anthropic_max_tokens" name="newss_anthropic_max_tokens" value="<?php echo esc_attr((string) $maxTokens); ?>" min="500" max="8000" step="100"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="newss_anthropic_temperature">Temperature</label></th>
                        <td><input type="number" id="newss_anthropic_temperature" name="newss_anthropic_temperature" value="<?php echo esc_attr((string) $temperature); ?>" min="0" max="1" step="0.1"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="newss_anthropic_daily_cap">Max Calls / Tag</label></th>
                        <td>
                            <input type="number" id="newss_anthropic_daily_cap" name="newss_anthropic_daily_cap" value="<?php echo esc_attr((string) $dailyCap); ?>" min="0" max="10000" step="10">
                            <p class="description">
                                Hard-Cap pro Kalendertag (Europe/Berlin). 0 = unbegrenzt.
                                Heute bereits: <strong><?php echo (int) $todayCount; ?></strong> Calls.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="newss_system_prompt">System-Prompt</label></th>
                        <td><textarea id="newss_system_prompt" name="newss_system_prompt" rows="14" class="large-text code"><?php echo esc_textarea($systemPrompt); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="newss_user_prompt_template">User-Prompt-Template</label></th>
                        <td>
                            <textarea id="newss_user_prompt_template" name="newss_user_prompt_template" rows="6" class="large-text code"><?php echo esc_textarea($userTpl); ?></textarea>
                            <p class="description">Platzhalter: <code>{title}</code>, <code>{channel}</code>, <code>{published_at}</code>, <code>{transcript}</code></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <?php self::renderApiTestHiddenForm('newss_test_anthropic', 'newss_test_anthropic', 'anthropic_key'); ?>
        </div>
        <?php
    }

    public static function renderYoutubePage(): void
    {
        if (!current_user_can('manage_options')) return;

        $ytMethod     = (string) get_option('newss_youtube_method', 'rss');
        $youtubeProxy = (string) get_option('newss_youtube_proxy', '');
        ?>
        <div class="wrap">
            <h1>Newss · YouTube</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Methode</th>
                        <td>
                            <label style="display:block;margin-bottom:6px">
                                <input type="radio" name="newss_youtube_method" value="api" <?php checked($ytMethod, 'api'); ?> onchange="newssToggleYtMethod()">
                                <strong>YouTube Data API</strong> — empfohlen, zuverlässig, Free-Tier (10.000 Units/Tag)
                            </label>
                            <label style="display:block">
                                <input type="radio" name="newss_youtube_method" value="rss" <?php checked($ytMethod, 'rss'); ?> onchange="newssToggleYtMethod()">
                                <strong>RSS-Feed mit Proxy</strong> — Fallback, funktioniert ohne Google-Account, anfällig für Drossel
                            </label>
                        </td>
                    </tr>
                </table>

                <div id="newss-yt-api-section">
                    <h3 style="margin:18px 0 6px">YouTube Data API</h3>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="newss_youtube_api_key">API-Key</label></th>
                            <td>
                                <input type="password" id="newss_youtube_api_key" name="newss_youtube_api_key" value="<?php echo esc_attr((string) get_option('newss_youtube_api_key', '')); ?>" class="regular-text" autocomplete="off">
                                <p class="description">
                                    Key erstellen: <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">console.cloud.google.com/apis/credentials</a>
                                    → Create API Key → YouTube Data API v3 aktivieren. Optional auf Server-IP einschränken.
                                </p>
                                <?php self::renderApiTestButton('newss_test_youtube', 'newss_test_youtube', 'youtube_key', 'newss_youtube_api_key', 'newss_youtube_test', 'Test-Call gegen channels.list'); ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="newss-yt-rss-section">
                    <h3 style="margin:18px 0 6px">RSS-Feed mit Proxy-Rotation</h3>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="newss_youtube_proxy">Proxy-Liste</label></th>
                            <td>
                                <textarea id="newss_youtube_proxy" name="newss_youtube_proxy" rows="6" class="large-text code" placeholder="host:port:user:pass&#10;http://user:pass@host:port&#10;socks5://host:port"><?php echo esc_textarea($youtubeProxy); ?></textarea>
                                <p class="description">
                                    Eine Zeile pro Proxy. Akzeptierte Formate:<br>
                                    <code>host:port:user:pass</code> &nbsp;|&nbsp; <code>http://user:pass@host:port</code> &nbsp;|&nbsp; <code>socks5://host:port</code>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="newss_youtube_cookie">Consent-Cookie</label></th>
                            <td>
                                <input type="text" id="newss_youtube_cookie" name="newss_youtube_cookie" value="<?php echo esc_attr((string) get_option('newss_youtube_cookie', \Newss\Http::DEFAULT_YT_COOKIE)); ?>" class="large-text code" placeholder="<?php echo esc_attr(\Newss\Http::DEFAULT_YT_COOKIE); ?>">
                                <p class="description">Wird bei DE-/EU-IPs gebraucht damit YouTube nicht die Consent-Wall serviert. Leer = kein Cookie senden.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <script>
                function newssToggleYtMethod() {
                    var m = document.querySelector('input[name="newss_youtube_method"]:checked');
                    if (!m) return;
                    var api = document.getElementById('newss-yt-api-section');
                    var rss = document.getElementById('newss-yt-rss-section');
                    var apiActive = m.value === 'api';
                    api.style.opacity = apiActive ? '1' : '0.4';
                    api.style.pointerEvents = apiActive ? 'auto' : 'none';
                    rss.style.opacity = apiActive ? '0.4' : '1';
                    rss.style.pointerEvents = apiActive ? 'none' : 'auto';
                }
                document.addEventListener('DOMContentLoaded', newssToggleYtMethod);
                </script>

                <?php submit_button(); ?>
            </form>
            <?php self::renderApiTestHiddenForm('newss_test_youtube', 'newss_test_youtube', 'youtube_key'); ?>
        </div>
        <?php
    }

    public static function renderTranscriptPage(): void
    {
        if (!current_user_can('manage_options')) return;

        $supadataKey = (string) get_option('newss_supadata_api_key', '');
        $ytdlp       = (string) get_option('newss_ytdlp_path', 'yt-dlp');
        $whEnabled   = (int)    get_option('newss_whisper_enabled', 0);
        $whKey       = (string) get_option('newss_whisper_api_key', '');

        $whisperNotice = get_transient('newss_whisper_test');
        if ($whisperNotice) delete_transient('newss_whisper_test');
        ?>
        <div class="wrap">
            <h1>Newss · Transkript</h1>
            <p class="description" style="max-width:780px">
                Reihenfolge: zuerst <strong>Supadata</strong> (wenn Key gesetzt) → dann <strong>yt-dlp</strong> → dann <strong>Whisper</strong> (wenn aktiviert).
            </p>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="newss_supadata_api_key">Supadata.ai API-Key</label></th>
                        <td>
                            <input type="password" id="newss_supadata_api_key" name="newss_supadata_api_key" value="<?php echo esc_attr($supadataKey); ?>" class="regular-text" autocomplete="off">
                            <p class="description">Empfohlen für Cloud-Hosting. Account: <a href="https://supadata.ai" target="_blank" rel="noopener">supadata.ai</a>.</p>
                            <?php self::renderApiTestButton('newss_test_supadata', 'newss_test_supadata', 'supadata_key', 'newss_supadata_api_key', 'newss_supadata_test', 'Test-Call mit kurzem YouTube-Video'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="newss_ytdlp_path">yt-dlp Pfad</label></th>
                        <td>
                            <input type="text" id="newss_ytdlp_path" name="newss_ytdlp_path" value="<?php echo esc_attr($ytdlp); ?>" class="regular-text">
                            <p class="description">Fallback wenn Supadata-Key leer ist oder ein Request scheitert.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Whisper-Fallback (OpenAI)</th>
                        <td>
                            <label><input type="checkbox" name="newss_whisper_enabled" value="1" <?php checked($whEnabled, 1); ?>> Aktivieren — wird probiert wenn Supadata + yt-dlp beide leer ausgehen</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="newss_whisper_daily_cap">Whisper Max Calls / Tag</label></th>
                        <td>
                            <?php
                            $whCap = (int) get_option('newss_whisper_daily_cap', 0);
                            $whCalls = get_option('newss_whisper_calls_today', null);
                            $whTodayCount = (is_array($whCalls) && ($whCalls['date'] ?? '') === wp_date('Y-m-d')) ? (int) $whCalls['count'] : 0;
                            ?>
                            <input type="number" id="newss_whisper_daily_cap" name="newss_whisper_daily_cap" value="<?php echo esc_attr((string) $whCap); ?>" min="0" max="10000" step="10">
                            <p class="description">
                                Hard-Cap pro Tag (Europe/Berlin). 0 = unbegrenzt.
                                Heute bereits: <strong><?php echo (int) $whTodayCount; ?></strong> erfolgreiche Calls.
                                Cost-Schätzung: ~$0.006 pro Minute Audio.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="newss_whisper_api_key">OpenAI API-Key (für Whisper)</label></th>
                        <td>
                            <input type="password" id="newss_whisper_api_key" name="newss_whisper_api_key" value="<?php echo esc_attr($whKey); ?>" class="regular-text" autocomplete="off">
                            <div style="margin-top:8px">
                                <button type="button" class="button" onclick="(function(){var snap=document.getElementById('newss-whisper-key-snapshot');var inp=document.getElementById('newss_whisper_api_key');snap.value=inp.value;document.getElementById('newss-test-whisper-form').submit();})();">Key testen</button>
                                <span class="description">— Test-Call gegen <code>/v1/models</code>. Nimmt den aktuellen Wert im Feld oben (auch ohne vorher zu speichern).</span>
                            </div>
                            <?php if (is_array($whisperNotice) && !empty($whisperNotice['lines'])): ?>
                                <div class="notice notice-<?php echo esc_attr((string) $whisperNotice['type']); ?> inline" style="margin-top:10px;padding:10px 14px">
                                    <p style="margin:0 0 6px 0"><strong>Whisper-Test-Ergebnis:</strong></p>
                                    <pre style="margin:0;background:#f6f7f7;padding:8px;font-size:11px;line-height:1.5;white-space:pre-wrap;word-break:break-all"><?php
                                        foreach ((array) $whisperNotice['lines'] as $line) {
                                            echo esc_html((string) $line) . "\n";
                                        }
                                    ?></pre>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <form id="newss-test-whisper-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none">
                <input type="hidden" name="action" value="newss_test_whisper">
                <input type="hidden" name="whisper_key" value="" id="newss-whisper-key-snapshot">
                <?php wp_nonce_field('newss_test_whisper'); ?>
            </form>
            <?php self::renderApiTestHiddenForm('newss_test_supadata', 'newss_test_supadata', 'supadata_key'); ?>
        </div>
        <?php
    }

    public static function renderPublishingPage(): void
    {
        if (!current_user_can('manage_options')) return;

        $defCat        = (int)    get_option('newss_default_category', 0);
        $catList       = (string) get_option('newss_category_list', Anthropic::defaultCategoriesText());
        $defStatus     = (string) get_option('newss_default_status', 'publish');
        $blockedTopics = (array)  get_option('newss_blocked_topics', []);
        $blockedAction    = (string) get_option('newss_blocked_action', 'skip');
        $preclassifyOn    = (int) get_option('newss_preclassify_enabled', 1);
        $killSwitch    = (int)    get_option('newss_kill_switch_drafts', 0);
        $postAuthor    = (int)    get_option('newss_post_author', 0);
        ?>
        <div class="wrap">
            <h1>Newss · Veröffentlichung</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="newss_default_category">Default-Kategorie</label></th>
                        <td>
                            <?php wp_dropdown_categories([
                                'show_option_none'  => '— keine —',
                                'option_none_value' => 0,
                                'name'              => 'newss_default_category',
                                'selected'          => $defCat,
                                'hide_empty'        => false,
                            ]); ?>
                            <p class="description">Letzter Fallback wenn weder KI eine Kategorie auswählt noch beim Kanal eine gesetzt ist.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="newss_category_list">Kategorien-Liste (KI-Auswahl)</label></th>
                        <td>
                            <textarea id="newss_category_list" name="newss_category_list" rows="10" class="large-text code" placeholder="Eine Kategorie pro Zeile"><?php echo esc_textarea($catList); ?></textarea>
                            <p class="description">
                                Eine Kategorie pro Zeile. Claude wählt für jeden Artikel exakt eine aus dieser Liste.
                                Fehlende Kategorien werden bei Bedarf automatisch angelegt.<br>
                                <strong>Reihenfolge:</strong> KI-Auswahl gewinnt zuerst — Kanal-Kategorie greift nur wenn KI nichts Passendes findet, dann ggf. Default-Kategorie.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="newss_default_status">Status</label></th>
                        <td>
                            <select name="newss_default_status" id="newss_default_status">
                                <option value="publish" <?php selected($defStatus, 'publish'); ?>>Sofort veröffentlichen</option>
                                <option value="draft"   <?php selected($defStatus, 'draft'); ?>>Nur als Entwurf</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Kill-Switch</th>
                        <td>
                            <label><input type="checkbox" name="newss_kill_switch_drafts" value="1" <?php checked($killSwitch, 1); ?>> <strong>Notfall:</strong> alle neuen Posts als Entwurf, ignoriere obigen Status</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Sensible Themen</th>
                        <td>
                            <fieldset>
                                <?php foreach (Anthropic::topicLabels() as $key => $label): ?>
                                    <label style="display:block;margin-bottom:4px">
                                        <input type="checkbox" name="newss_blocked_topics[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $blockedTopics, true)); ?>>
                                        <?php echo esc_html($label); ?>
                                        <code style="font-size:11px;color:#888"><?php echo esc_html($key); ?></code>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="description">Wenn die KI eines dieser Topics für ein Video erkennt, wird der Artikel je nach Aktion unten behandelt.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Aktion bei Treffer</th>
                        <td>
                            <label style="margin-right:20px"><input type="radio" name="newss_blocked_action" value="skip" <?php checked($blockedAction, 'skip'); ?>> Überspringen (kein Post)</label>
                            <label><input type="radio" name="newss_blocked_action" value="draft" <?php checked($blockedAction, 'draft'); ?>> Als Entwurf anlegen (zur manuellen Sichtung)</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Pre-Filter (3 Stufen)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="newss_preclassify_enabled" value="1" <?php checked($preclassifyOn, 1); ?>>
                                Vor Transcript-Fetch Titel + Channel prüfen — bei Treffer wird <em>vor</em> Whisper- und Rewrite-Call abgebrochen.
                            </label>
                            <p class="description">
                                Drei Stufen, hintereinandergeschaltet:<br>
                                &nbsp;&nbsp;<strong>1.</strong> Stichwort-Heuristik auf Titel (gratis, lokal)<br>
                                &nbsp;&nbsp;<strong>2.</strong> Claude Haiku auf Titel + Channel (~0,001 USD/Video, nur falls Stufe 1 nichts findet)<br>
                                &nbsp;&nbsp;<strong>3.</strong> Reguläre Topic-Tags-Prüfung nach dem Claude-Rewrite (Final-Check, läuft immer)<br>
                                Wirkt nur bei <strong>Aktion = Überspringen</strong>; bei „Als Entwurf anlegen" muss die volle Pipeline laufen.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="newss_post_author">Autor (User-ID)</label></th>
                        <td>
                            <input type="number" id="newss_post_author" name="newss_post_author" value="<?php echo esc_attr((string) $postAuthor); ?>" min="0">
                            <p class="description">0 = aktueller Benutzer beim Cron-Lauf (i. d. R. nicht eingeloggt → fällt auf User-ID 1).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function renderHelpPage(): void
    {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1>Newss · Hilfe</h1>

            <h2>Quick-Start</h2>
            <ol style="max-width:780px;line-height:1.7">
                <li><strong>YouTube-API-Key</strong> in <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_YOUTUBE)); ?>">YouTube</a> eintragen und mit „Testen“ verifizieren.</li>
                <li><strong>Anthropic-API-Key</strong> in <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_CLAUDE)); ?>">Claude</a> eintragen und Modell wählen.</li>
                <li>Optional: <strong>Supadata-Key</strong> in <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_TRANSCRIPT)); ?>">Transkript</a> für schnelles Caption-Fetching, oder <strong>yt-dlp</strong> als Free-Fallback.</li>
                <li>In <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_CHANNELS)); ?>">Kanäle</a> einen YouTube-Kanal per URL/Handle hinzufügen und „Testen“ klicken — wenn Videos gefunden werden, ist alles korrekt.</li>
                <li>In <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_PUBLISHING)); ?>">Veröffentlichung</a> Default-Kategorie und Status setzen.</li>
                <li>Auf <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_STATUS)); ?>">Status</a> sehen wie der erste Poll läuft. Bei Bedarf manuell „Jetzt pollen“ triggern.</li>
            </ol>

            <h2>Häufige Fehler</h2>
            <table class="widefat striped" style="max-width:880px">
                <thead><tr><th style="width:240px">Symptom</th><th>Ursache &amp; Lösung</th></tr></thead>
                <tbody>
                    <tr>
                        <td><strong>YT-Quota erschöpft</strong></td>
                        <td>YouTube-Daily-Quota (10k Units) verbraucht. Reset um Mitternacht Pacific-Zeit (~09:00 Berlin). Sichtbar im Status-Health-Tile. Polls pausieren automatisch bis Reset.</td>
                    </tr>
                    <tr>
                        <td><strong>Supadata HTTP 429</strong></td>
                        <td>Rate-Limit. Plugin fällt automatisch auf yt-dlp / Whisper zurück. Falls dauerhaft: Plan upgraden oder Supadata deaktivieren.</td>
                    </tr>
                    <tr>
                        <td><strong>yt-dlp findet keine Subs</strong></td>
                        <td>Channel ohne Auto-Captions oder Geo-/Bot-Block. Mit Residential-Proxy in <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_TRANSCRIPT)); ?>">Transkript</a> abhilfen oder Whisper-Fallback aktivieren.</td>
                    </tr>
                    <tr>
                        <td><strong>Claude HTTP 529 / overloaded</strong></td>
                        <td>Anthropic-Server überlastet. Action-Scheduler retried automatisch. Falls dauerhaft: kleineres Modell wählen.</td>
                    </tr>
                    <tr>
                        <td><strong>Claude HTTP 429 / rate-limit</strong></td>
                        <td>Eigener Workspace-Cap erreicht. In <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_CLAUDE)); ?>">Claude</a> Daily-Cap reduzieren oder Workspace-Limit anheben.</td>
                    </tr>
                    <tr>
                        <td><strong>Pipeline hängt auf „pending“</strong></td>
                        <td>Action-Scheduler-Cron läuft nicht. Prüfen: <code>wp action-scheduler run</code> via WP-CLI oder System-Cron für <code>wp-cron.php</code> einrichten.</td>
                    </tr>
                    <tr>
                        <td><strong>Posts erscheinen als Entwurf trotz „Sofort veröffentlichen“</strong></td>
                        <td>Kill-Switch aktiv (in Veröffentlichung) oder sensibles Topic von KI erkannt → siehe „Aktion bei Treffer“.</td>
                    </tr>
                </tbody>
            </table>

            <h2>Wartung</h2>
            <ul style="max-width:780px;line-height:1.7">
                <li><strong>Plugin-Update:</strong> auf <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_STATUS)); ?>">Status</a> ganz unten — „Aus Git aktualisieren“.</li>
                <li><strong>Stuck-Jobs:</strong> in <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG_PIPELINE)); ?>">Job-Pipeline</a> Filter „failed“ → manuell retry oder Video überspringen.</li>
                <li><strong>Deinstallation:</strong> Plugin löschen entfernt alle Optionen, Transients, Locks &amp; Action-Scheduler-Jobs (siehe <code>uninstall.php</code>).</li>
            </ul>

            <h2>Doku &amp; Links</h2>
            <ul style="max-width:780px;line-height:1.7">
                <li>Anthropic API: <a href="https://docs.anthropic.com/en/api/messages" target="_blank" rel="noopener">docs.anthropic.com</a></li>
                <li>YouTube Data API v3: <a href="https://developers.google.com/youtube/v3/docs/playlistItems/list" target="_blank" rel="noopener">developers.google.com</a></li>
                <li>Supadata: <a href="https://supadata.ai/docs" target="_blank" rel="noopener">supadata.ai/docs</a></li>
                <li>Action Scheduler: <a href="https://actionscheduler.org/" target="_blank" rel="noopener">actionscheduler.org</a></li>
            </ul>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private static function detectBinary(string $bin): bool
    {
        if (str_starts_with($bin, '/')) {
            return @is_executable($bin);
        }
        if (function_exists('shell_exec')) {
            $out = trim((string) @shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($bin))));
            if ($out !== '') return true;
        }
        foreach (['/usr/local/bin', '/usr/bin', '/snap/bin', '/usr/local/sbin', '/usr/sbin', '/bin'] as $dir) {
            if (@is_executable($dir . '/' . $bin)) return true;
        }
        return false;
    }

    private static function formatTime(int $ts): string
    {
        return wp_date('Y-m-d H:i', $ts);
    }
}
