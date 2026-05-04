<?php

declare(strict_types=1);

namespace Newss;

final class Settings
{
    private const PAGE_SLUG = 'newss-settings';
    private const OPTION_GROUP = 'newss_settings';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_newss_run_now', [self::class, 'handleRunNow']);
        add_action('admin_post_newss_test_whisper', [self::class, 'handleTestWhisper']);
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
            set_transient('newss_whisper_test', [
                'type'    => 'error',
                'lines'   => ['Kein Key gesetzt — sowohl Input-Feld als auch DB sind leer.'],
            ], 60);
            self::redirect();
        }

        $keyPrefix = substr($key, 0, 12);
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
            sprintf('Endpunkt: GET https://api.openai.com/v1/models'),
            sprintf('Dauer: %d ms', $elapsedMs),
        ];

        if (is_wp_error($resp)) {
            $lines[] = 'Network-Fehler (wp_error): ' . $resp->get_error_code() . ' — ' . $resp->get_error_message();
            set_transient('newss_whisper_test', ['type' => 'error', 'lines' => $lines], 120);
            self::redirect();
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        $hdrs = wp_remote_retrieve_headers($resp);

        $lines[] = sprintf('HTTP-Status: %d', $code);
        $orgHeader = is_object($hdrs) && method_exists($hdrs, 'offsetGet') ? (string) ($hdrs['openai-organization'] ?? '') : '';
        if ($orgHeader !== '') {
            $lines[] = 'OpenAI-Org: ' . $orgHeader;
        }
        $rateRemain = is_object($hdrs) ? (string) ($hdrs['x-ratelimit-remaining-requests'] ?? '') : '';
        if ($rateRemain !== '') {
            $lines[] = 'Rate-Limit-Remaining: ' . $rateRemain;
        }

        if ($code === 200) {
            $data = json_decode($body, true);
            $modelCount = is_array($data) && isset($data['data']) ? count($data['data']) : 0;
            $hasWhisper = false;
            $whisperModels = [];
            foreach (($data['data'] ?? []) as $m) {
                $id = (string) ($m['id'] ?? '');
                if (str_contains($id, 'whisper')) {
                    $hasWhisper = true;
                    $whisperModels[] = $id;
                }
            }
            $lines[] = sprintf('Modelle erreichbar: %d', $modelCount);
            $lines[] = $hasWhisper
                ? 'Whisper-Modelle: ' . implode(', ', $whisperModels)
                : 'Achtung: whisper-1 nicht in der Liste — Key-Permissions decken Audio nicht ab.';
            set_transient('newss_whisper_test', [
                'type'  => $hasWhisper ? 'success' : 'warning',
                'lines' => $lines,
            ], 120);
            self::redirect();
        }

        $errMsg = '';
        $errCode = '';
        $errType = '';
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
            default               => 'Wenn Key OK aussieht: Key-Permissions prüfen — restricted Keys brauchen explizit "Model capabilities → Audio".',
        };
        $lines[] = '→ Hinweis: ' . $hint;

        set_transient('newss_whisper_test', ['type' => 'error', 'lines' => $lines], 120);
        self::redirect();
    }

    private static function redirect(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    public static function addMenu(): void
    {
        add_menu_page(
            'Newss',
            'Newss',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'renderPage'],
            'dashicons-megaphone',
            58
        );
        add_submenu_page(
            self::PAGE_SLUG,
            'Newss – Einstellungen',
            'Einstellungen',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'renderPage']
        );
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
            'newss_default_category'       => 'absint',
            'newss_category_list'          => [self::class, 'sanitizeMultiline'],
            'newss_blocked_topics'         => [self::class, 'sanitizeBlockedTopics'],
            'newss_blocked_action'         => [self::class, 'sanitizeBlockedAction'],
            'newss_default_status'         => [self::class, 'sanitizeStatus'],
            'newss_kill_switch_drafts'     => 'absint',
            'newss_post_author'            => 'absint',
        ];
        foreach ($opts as $opt => $cb) {
            register_setting(self::OPTION_GROUP, $opt, ['sanitize_callback' => $cb]);
        }
    }

    public static function sanitizeTemperature($value): float
    {
        $f = (float) $value;
        return max(0.0, min(1.0, $f));
    }

    public static function sanitizeMultiline($value): string
    {
        return wp_kses_post((string) $value);
    }

    public static function sanitizeStatus($value): string
    {
        return in_array($value, ['publish', 'draft'], true) ? $value : 'publish';
    }

    public static function sanitizeYoutubeMethod($value): string
    {
        return in_array($value, ['api', 'rss'], true) ? $value : 'rss';
    }

    public static function sanitizeBlockedTopics($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $allowed = array_keys(Anthropic::topicLabels());
        return array_values(array_intersect(array_map('strval', $value), $allowed));
    }

    public static function sanitizeBlockedAction($value): string
    {
        return in_array($value, ['skip', 'draft'], true) ? $value : 'skip';
    }

    public static function handleRunNow(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('newss_run_now');

        if (function_exists('as_enqueue_async_action')) {
            \as_enqueue_async_action('newss_run_poll_now', [], 'newss');
            $flag = 'queued';
        } else {
            RssPoller::pollAll();
            $flag = 'sync';
        }

        wp_safe_redirect(add_query_arg(['ran' => $flag], admin_url('admin.php?page=' . self::PAGE_SLUG)));
        exit;
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $models = [
            'claude-opus-4-7'   => 'Claude Opus 4.7 (höchste Qualität)',
            'claude-sonnet-4-6' => 'Claude Sonnet 4.6 (Standard, empfohlen)',
            'claude-haiku-4-5'  => 'Claude Haiku 4.5 (schnell, günstig)',
        ];

        $apiKey      = (string) get_option('newss_anthropic_api_key', '');
        $model       = (string) get_option('newss_anthropic_model', 'claude-sonnet-4-6');
        $maxTokens   = (int)    get_option('newss_anthropic_max_tokens', 4000);
        $temperature = (float)  get_option('newss_anthropic_temperature', 1.0);
        $systemPrompt= (string) get_option('newss_system_prompt', Anthropic::defaultSystemPrompt());
        $userTpl     = (string) get_option('newss_user_prompt_template', Anthropic::defaultUserTemplate());
        $youtubeProxy = (string) get_option('newss_youtube_proxy', '');
        $supadataKey = (string) get_option('newss_supadata_api_key', '');
        $ytdlp       = (string) get_option('newss_ytdlp_path', 'yt-dlp');
        $whEnabled   = (int)    get_option('newss_whisper_enabled', 0);
        $whKey       = (string) get_option('newss_whisper_api_key', '');
        $defCat      = (int)    get_option('newss_default_category', 0);
        $catList     = (string) get_option('newss_category_list', Anthropic::defaultCategoriesText());
        $defStatus   = (string) get_option('newss_default_status', 'publish');
        $blockedTopics = (array) get_option('newss_blocked_topics', []);
        $blockedAction = (string) get_option('newss_blocked_action', 'skip');
        $killSwitch    = (int)    get_option('newss_kill_switch_drafts', 0);
        $postAuthor  = (int)    get_option('newss_post_author', 0);

        $lastPoll = get_option('newss_last_poll', null);
        $nextRun  = wp_next_scheduled(Cron::HOOK_PERIODIC);

        $ytDlpFound = self::detectBinary($ytdlp);
        $ffmpegFound = self::detectBinary('ffmpeg');

        $runUrl = wp_nonce_url(admin_url('admin-post.php?action=newss_run_now'), 'newss_run_now');

        ?>
        <div class="wrap">
            <h1>Newss – Einstellungen</h1>

            <?php if (isset($_GET['ran'])):
                $ranFlag = sanitize_key((string) $_GET['ran']);
                ?>
                <div class="notice notice-success is-dismissible">
                    <?php if ($ranFlag === 'queued'): ?>
                        <p>RSS-Polling wurde in die Hintergrund-Queue gelegt — läuft jetzt asynchron. Reload in ~30–90 Sekunden für aktualisierten Status.</p>
                    <?php else: ?>
                        <p>RSS-Polling wurde ausgeführt.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h2>Status</h2>
            <table class="widefat striped" style="max-width:780px">
                <tbody>
                <tr><th>yt-dlp</th><td><?php echo $ytDlpFound ? '<span style="color:#0a7">gefunden</span>' : '<span style="color:#c00">nicht gefunden</span>'; ?> (<code><?php echo esc_html($ytdlp); ?></code>)</td></tr>
                <tr><th>ffmpeg</th><td><?php echo $ffmpegFound ? '<span style="color:#0a7">gefunden</span>' : '<span style="color:#c00">nicht gefunden</span>'; ?></td></tr>
                <tr><th>Nächster Lauf (alle 8h, 3× täglich)</th><td><?php echo $nextRun ? esc_html(self::formatTime($nextRun)) : '—'; ?></td></tr>
                <tr><th>Letztes Polling</th><td><?php
                    if (is_array($lastPoll)) {
                        $s = $lastPoll['stats'];
                        printf(
                            '%s — Kanäle: %d, neue Videos: %d, Fehler: %d',
                            esc_html(self::formatTime((int) $lastPoll['time'])),
                            (int) $s['channels'],
                            (int) $s['new'],
                            (int) $s['errors']
                        );
                    } else {
                        echo '—';
                    }
                ?></td></tr>
                </tbody>
            </table>
            <p><a href="<?php echo esc_url($runUrl); ?>" class="button button-secondary">Jetzt manuell pollen</a></p>

            <p style="background:#fff8e1;border-left:4px solid #ffb900;padding:8px 12px;max-width:780px">
                <strong>Empfehlung:</strong> Trag auf dem Server diesen System-Cron ein und deaktiviere WP-Cron in <code>wp-config.php</code>:<br>
                <code>0 */8 * * * curl -s <?php echo esc_html(home_url('/wp-cron.php?doing_wp_cron')); ?> &gt; /dev/null</code><br>
                <code>define('DISABLE_WP_CRON', true);</code>
            </p>

            <?php Status::renderChannelPoll(); ?>

            <?php Status::renderPipeline(); ?>

            <?php Updater::renderSection(); ?>

            <?php Channels::renderSection(); ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>

                <h2>Anthropic Claude</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="newss_anthropic_api_key">API-Key</label></th>
                        <td><input type="password" id="newss_anthropic_api_key" name="newss_anthropic_api_key" value="<?php echo esc_attr($apiKey); ?>" class="regular-text" autocomplete="off"></td>
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
                            <?php
                            $dailyCap = (int) get_option('newss_anthropic_daily_cap', 0);
                            $callsToday = get_option('newss_anthropic_calls_today', null);
                            $todayCount = (is_array($callsToday) && ($callsToday['date'] ?? '') === wp_date('Y-m-d')) ? (int) $callsToday['count'] : 0;
                            ?>
                            <input type="number" id="newss_anthropic_daily_cap" name="newss_anthropic_daily_cap" value="<?php echo esc_attr((string) $dailyCap); ?>" min="0" max="10000" step="10">
                            <p class="description">
                                Hard-Cap pro Kalendertag (Europe/Berlin). 0 = unbegrenzt.
                                Heute bereits: <strong><?php echo (int) $todayCount; ?></strong> Calls.
                                Bei Erreichen werden weitere Worker-Jobs als „skipped" markiert (kein Anthropic-Charge mehr).
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="newss_system_prompt">System-Prompt</label></th>
                        <td><textarea id="newss_system_prompt" name="newss_system_prompt" rows="10" class="large-text code"><?php echo esc_textarea($systemPrompt); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="newss_user_prompt_template">User-Prompt-Template</label></th>
                        <td>
                            <textarea id="newss_user_prompt_template" name="newss_user_prompt_template" rows="6" class="large-text code"><?php echo esc_textarea($userTpl); ?></textarea>
                            <p class="description">Platzhalter: <code>{title}</code>, <code>{channel}</code>, <code>{published_at}</code>, <code>{transcript}</code></p>
                        </td>
                    </tr>
                </table>

                <h2>YouTube-Zugriff</h2>
                <?php $ytMethod = (string) get_option('newss_youtube_method', 'rss'); ?>
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
                                <textarea id="newss_youtube_proxy" name="newss_youtube_proxy" rows="6" class="large-text code" placeholder="host:port:user:pass&#10;http://user:pass@host:port&#10;socks5://host:port&#10;# Eine Zeile pro Proxy. Bei mehreren wird per Request randomisiert rotiert."><?php echo esc_textarea($youtubeProxy); ?></textarea>
                                <p class="description">
                                    Eine Zeile pro Proxy. Akzeptierte Formate:<br>
                                    <code>host:port:user:pass</code> &nbsp;|&nbsp; <code>http://user:pass@host:port</code> &nbsp;|&nbsp; <code>socks5://host:port</code><br>
                                    Bei mehreren Einträgen wird pro Request randomisiert einer gewählt.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="newss_youtube_cookie">Consent-Cookie</label></th>
                            <td>
                                <input type="text" id="newss_youtube_cookie" name="newss_youtube_cookie" value="<?php echo esc_attr((string) get_option('newss_youtube_cookie', \Newss\Http::DEFAULT_YT_COOKIE)); ?>" class="large-text code" placeholder="<?php echo esc_attr(\Newss\Http::DEFAULT_YT_COOKIE); ?>">
                                <p class="description">
                                    Wird bei DE-/EU-IPs gebraucht damit YouTube nicht die Consent-Wall serviert.
                                    Default funktioniert seit 2021. Leer = kein Cookie senden.
                                </p>
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

                <h2>Transkript-Quelle</h2>
                <p class="description" style="max-width:780px;margin-bottom:8px">
                    Reihenfolge: zuerst <strong>Supadata</strong> (wenn Key gesetzt) → dann <strong>yt-dlp</strong> → dann <strong>Whisper</strong> (wenn aktiviert).
                    Auf Cloud-Hostern (RunCloud, AWS, etc.) wird yt-dlp meist von YouTube als Bot blockiert — Supadata umgeht das.
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="newss_supadata_api_key">Supadata.ai API-Key</label></th>
                        <td>
                            <input type="password" id="newss_supadata_api_key" name="newss_supadata_api_key" value="<?php echo esc_attr($supadataKey); ?>" class="regular-text" autocomplete="off">
                            <p class="description">
                                Empfohlen für Cloud-Hosting. Account: <a href="https://supadata.ai" target="_blank" rel="noopener">supadata.ai</a>.
                                Wenn gesetzt, wird Supadata <strong>vor</strong> yt-dlp probiert.
                            </p>
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
                        <th scope="row"><label for="newss_whisper_api_key">OpenAI API-Key (für Whisper)</label></th>
                        <td>
                            <input type="password" id="newss_whisper_api_key" name="newss_whisper_api_key" value="<?php echo esc_attr($whKey); ?>" class="regular-text" autocomplete="off">
                            <?php
                            $whisperNotice = get_transient('newss_whisper_test');
                            if ($whisperNotice) {
                                delete_transient('newss_whisper_test');
                            }
                            ?>
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

                <h2>Veröffentlichung</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="newss_default_category">Default-Kategorie</label></th>
                        <td>
                            <?php
                            wp_dropdown_categories([
                                'show_option_none'  => '— keine —',
                                'option_none_value' => 0,
                                'name'              => 'newss_default_category',
                                'selected'          => $defCat,
                                'hide_empty'        => false,
                            ]);
                            ?>
                            <p class="description">Letzter Fallback wenn weder KI eine Kategorie auswählt noch beim Kanal eine gesetzt ist.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="newss_category_list">Kategorien-Liste (KI-Auswahl)</label></th>
                        <td>
                            <textarea id="newss_category_list" name="newss_category_list" rows="10" class="large-text code" placeholder="Eine Kategorie pro Zeile"><?php echo esc_textarea($catList); ?></textarea>
                            <p class="description">
                                Eine Kategorie pro Zeile. Claude wählt für jeden Artikel exakt eine aus dieser Liste.
                                Fehlende Kategorien werden bei Bedarf in WordPress automatisch angelegt.<br>
                                <strong>Reihenfolge:</strong> KI-Auswahl gewinnt zuerst — die Kanal-Kategorie greift nur wenn die KI nichts Passendes findet, dann ggf. die Default-Kategorie.
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
                                <legend class="screen-reader-text"><span>Sensible Themen</span></legend>
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
                        <th scope="row"><label for="newss_post_author">Autor (User-ID)</label></th>
                        <td>
                            <input type="number" id="newss_post_author" name="newss_post_author" value="<?php echo esc_attr((string) $postAuthor); ?>" min="0">
                            <p class="description">0 = aktueller Benutzer beim Cron-Lauf (i. d. R. nicht eingeloggt → fällt auf User-ID 1). Setze auf einen dedizierten „Redaktion"-User.</p>
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
        </div>
        <?php
    }

    private static function detectBinary(string $bin): bool
    {
        if (str_starts_with($bin, '/')) {
            return @is_executable($bin);
        }
        if (function_exists('shell_exec')) {
            $out = trim((string) @shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($bin))));
            if ($out !== '') {
                return true;
            }
        }
        foreach (['/usr/local/bin', '/usr/bin', '/snap/bin', '/usr/local/sbin', '/usr/sbin', '/bin'] as $dir) {
            if (@is_executable($dir . '/' . $bin)) {
                return true;
            }
        }
        return false;
    }

    private static function formatTime(int $ts): string
    {
        return wp_date('Y-m-d H:i', $ts);
    }
}
