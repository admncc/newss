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
        $opts = [
            'newss_anthropic_api_key'      => 'sanitize_text_field',
            'newss_anthropic_model'        => 'sanitize_text_field',
            'newss_anthropic_max_tokens'   => 'absint',
            'newss_anthropic_temperature'  => [self::class, 'sanitizeTemperature'],
            'newss_system_prompt'          => [self::class, 'sanitizeMultiline'],
            'newss_user_prompt_template'   => [self::class, 'sanitizeMultiline'],
            'newss_youtube_proxy'          => [self::class, 'sanitizeMultiline'],
            'newss_supadata_api_key'       => 'sanitize_text_field',
            'newss_ytdlp_path'             => 'sanitize_text_field',
            'newss_whisper_enabled'        => 'absint',
            'newss_whisper_api_key'        => 'sanitize_text_field',
            'newss_default_category'       => 'absint',
            'newss_category_list'          => [self::class, 'sanitizeMultiline'],
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

    public static function handleRunNow(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('newss_run_now');
        RssPoller::pollAll();
        wp_safe_redirect(add_query_arg(['ran' => '1'], admin_url('admin.php?page=' . self::PAGE_SLUG)));
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
        $killSwitch  = (int)    get_option('newss_kill_switch_drafts', 0);
        $postAuthor  = (int)    get_option('newss_post_author', 0);

        $lastPoll = get_option('newss_last_poll', null);
        $nextRun  = wp_next_scheduled(Cron::HOOK_PERIODIC);

        $ytDlpFound = self::detectBinary($ytdlp);
        $ffmpegFound = self::detectBinary('ffmpeg');

        $runUrl = wp_nonce_url(admin_url('admin-post.php?action=newss_run_now'), 'newss_run_now');

        ?>
        <div class="wrap">
            <h1>Newss – Einstellungen</h1>

            <?php if (isset($_GET['ran'])): ?>
                <div class="notice notice-success is-dismissible"><p>RSS-Polling wurde manuell ausgeführt.</p></div>
            <?php endif; ?>

            <h2>Status</h2>
            <table class="widefat striped" style="max-width:780px">
                <tbody>
                <tr><th>yt-dlp</th><td><?php echo $ytDlpFound ? '<span style="color:#0a7">gefunden</span>' : '<span style="color:#c00">nicht gefunden</span>'; ?> (<code><?php echo esc_html($ytdlp); ?></code>)</td></tr>
                <tr><th>ffmpeg</th><td><?php echo $ffmpegFound ? '<span style="color:#0a7">gefunden</span>' : '<span style="color:#c00">nicht gefunden</span>'; ?></td></tr>
                <tr><th>Nächster Lauf (alle 6 Stunden)</th><td><?php echo $nextRun ? esc_html(self::formatTime($nextRun)) : '—'; ?></td></tr>
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
                <code>0 */6 * * * curl -s <?php echo esc_html(home_url('/wp-cron.php?doing_wp_cron')); ?> &gt; /dev/null</code><br>
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

                <h2>YouTube-Zugriff (Proxy-Rotation)</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="newss_youtube_proxy">Proxy-Liste</label></th>
                        <td>
                            <textarea id="newss_youtube_proxy" name="newss_youtube_proxy" rows="6" class="large-text code" placeholder="host:port:user:pass&#10;http://user:pass@host:port&#10;socks5://host:port&#10;# Eine Zeile pro Proxy. Bei mehreren wird per Request randomisiert rotiert."><?php echo esc_textarea($youtubeProxy); ?></textarea>
                            <p class="description">
                                Eine Zeile pro Proxy. Akzeptierte Formate:<br>
                                <code>host:port:user:pass</code> &nbsp;|&nbsp; <code>http://user:pass@host:port</code> &nbsp;|&nbsp; <code>socks5://host:port</code><br>
                                Bei mehreren Einträgen wird pro Request randomisiert einer gewählt.
                                Wird nur für YouTube-RSS und Channel-Resolver genutzt — Anthropic und Supadata gehen direkt.
                                Leer lassen = kein Proxy.
                            </p>
                        </td>
                    </tr>
                </table>

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
                        <td><input type="password" id="newss_whisper_api_key" name="newss_whisper_api_key" value="<?php echo esc_attr($whKey); ?>" class="regular-text" autocomplete="off"></td>
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
                        <th scope="row"><label for="newss_post_author">Autor (User-ID)</label></th>
                        <td>
                            <input type="number" id="newss_post_author" name="newss_post_author" value="<?php echo esc_attr((string) $postAuthor); ?>" min="0">
                            <p class="description">0 = aktueller Benutzer beim Cron-Lauf (i. d. R. nicht eingeloggt → fällt auf User-ID 1). Setze auf einen dedizierten „Redaktion"-User.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
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
