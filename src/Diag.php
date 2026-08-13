<?php

declare(strict_types=1);

namespace Newss;

/**
 * Read-only REST-API fuer externe Diagnose (z.B. fuer den Claude-Agent).
 * Auth: Bearer-Token im Authorization-Header. Toggle in Settings > Log.
 * Token wird bei jedem Enable neu generiert (32-char random).
 */
final class Diag
{
    public const OPTION_ENABLED = 'newss_diag_enabled';
    public const OPTION_TOKEN   = 'newss_diag_token';
    public const NAMESPACE      = 'newss/v1';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/diag/status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'routeStatus'],
            'permission_callback' => [self::class, 'checkAuth'],
        ]);
        register_rest_route(self::NAMESPACE, '/diag/log', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'routeLog'],
            'permission_callback' => [self::class, 'checkAuth'],
            'args' => [
                'limit'  => ['type' => 'integer', 'default' => 200, 'minimum' => 1, 'maximum' => 1000],
                'level'  => ['type' => 'string',  'default' => '', 'enum' => ['', 'info', 'warn', 'error']],
                'since'  => ['type' => 'number',  'default' => 0], // unix-ts (float, .ms)
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/diag/pipeline', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'routePipeline'],
            'permission_callback' => [self::class, 'checkAuth'],
        ]);
        register_rest_route(self::NAMESPACE, '/diag/channels', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'routeChannels'],
            'permission_callback' => [self::class, 'checkAuth'],
        ]);
        register_rest_route(self::NAMESPACE, '/diag/categories', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'routeCategories'],
            'permission_callback' => [self::class, 'checkAuth'],
        ]);
    }

    public static function routeCategories(\WP_REST_Request $req): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'ts' => time(),
        ] + self::categoryDebug());
    }

    /**
     * Bearer-Token-Check. Kein Login, kein Cookie -- nur Header.
     */
    public static function checkAuth(\WP_REST_Request $req)
    {
        if ((int) get_option(self::OPTION_ENABLED, 0) !== 1) {
            return new \WP_Error('newss_diag_disabled', 'Diagnostic-API ist deaktiviert', ['status' => 404]);
        }
        $stored = trim((string) get_option(self::OPTION_TOKEN, ''));
        if ($stored === '') {
            return new \WP_Error('newss_diag_no_token', 'Kein Token gesetzt', ['status' => 401]);
        }
        $auth = $req->get_header('authorization');
        if ($auth === null || $auth === '') {
            // Manche Reverse-Proxies droppen den Header -> Fallback via query-param
            $auth = 'Bearer ' . (string) $req->get_param('token');
        }
        if (!preg_match('/^Bearer\s+(.+)$/i', (string) $auth, $m)) {
            return new \WP_Error('newss_diag_no_bearer', 'Missing Bearer token', ['status' => 401]);
        }
        $provided = trim($m[1]);
        // constant-time compare
        if (!hash_equals($stored, $provided)) {
            return new \WP_Error('newss_diag_bad_token', 'Invalid token', ['status' => 401]);
        }
        return true;
    }

    /**
     * Fuer Debugging: welche Kategorien sind konfiguriert und wie sind
     * die letzten Newss-Posts kategorisiert.
     *
     * @return array{configured:array<int,string>, wp_categories:array<int,array<string,mixed>>, recent_newss_posts:array<int,array<string,mixed>>}
     */
    private static function categoryDebug(): array
    {
        global $wpdb;

        $configured = Anthropic::categoryList();

        // Alle WP-Kategorien mit Post-Count
        $wpCats = [];
        $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'number' => 100]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $t) {
                $wpCats[] = [
                    'term_id' => (int) $t->term_id,
                    'name'    => (string) $t->name,
                    'slug'    => (string) $t->slug,
                    'count'   => (int) $t->count,
                ];
            }
        }

        // Letzte 20 Newss-Posts mit Kategorie-Zuweisung
        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_date_gmt, pm.meta_value AS video_id
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE pm.meta_key = '_newss_video_id'
               AND p.post_type = 'post'
               AND p.post_status IN ('publish','draft','private','pending','future')
             ORDER BY p.ID DESC
             LIMIT 20",
            ARRAY_A
        ) ?: [];
        $recent = [];
        foreach ($rows as $r) {
            $postId = (int) $r['ID'];
            $cats = wp_get_post_categories($postId, ['fields' => 'all']);
            $catNames = [];
            $catIds = [];
            if (!is_wp_error($cats)) {
                foreach ($cats as $c) {
                    $catNames[] = (string) $c->name;
                    $catIds[] = (int) $c->term_id;
                }
            }
            $recent[] = [
                'post_id'    => $postId,
                'title'      => (string) $r['post_title'],
                'video_id'   => (string) $r['video_id'],
                'created_gmt'=> (string) $r['post_date_gmt'],
                'category_ids'   => $catIds,
                'category_names' => $catNames,
                'uncategorized'  => in_array('Uncategorized', $catNames, true) || in_array('Ohne Kategorie', $catNames, true) || $cats === [],
            ];
        }

        return [
            'configured'         => $configured,
            'wp_categories'      => $wpCats,
            'recent_newss_posts' => $recent,
        ];
    }

    public static function routeStatus(\WP_REST_Request $req): \WP_REST_Response
    {
        $lastPoll = get_option('newss_last_poll', null);
        $lastCron = (int) get_option('newss_last_cron_run', 0);
        $providers = ['youtube_api', 'supadata', 'whisper'];
        $health = [];
        foreach ($providers as $p) {
            $h = get_option(Transcript::HEALTH_OPTION_PREFIX . $p, null);
            $health[$p] = is_array($h) ? $h : null;
        }

        // System
        $ytdlpBin = (string) get_option('newss_ytdlp_path', 'yt-dlp');
        $ytdlpFound = self::detectBinary($ytdlpBin);
        $ffmpegFound = self::detectBinary('ffmpeg');

        // Cron / poll info
        $nextRun = wp_next_scheduled(Cron::HOOK_PERIODIC);

        // Config summary (keine secrets)
        $config = [
            'youtube_method'        => (string) get_option('newss_youtube_method', 'rss'),
            'youtube_api_key_set'   => (string) get_option('newss_youtube_api_key', '') !== '',
            'supadata_key_set'      => (string) get_option('newss_supadata_api_key', '') !== '',
            'whisper_enabled'       => (int) get_option('newss_whisper_enabled', 0) === 1,
            'whisper_key_set'       => (string) get_option('newss_whisper_api_key', '') !== '',
            'whisper_daily_cap'     => (int) get_option('newss_whisper_daily_cap', 0),
            'anthropic_key_set'     => (string) get_option('newss_anthropic_api_key', '') !== '',
            'anthropic_model'       => (string) get_option('newss_anthropic_model', 'claude-sonnet-4-6'),
            'anthropic_daily_cap'   => (int) get_option('newss_anthropic_daily_cap', 0),
            'blocked_topics'        => (array) get_option('newss_blocked_topics', []),
            'blocked_action'        => (string) get_option('newss_blocked_action', 'skip'),
            'preclassify_enabled'   => (int) get_option('newss_preclassify_enabled', 1) === 1,
            'yt_cookie_set'         => trim((string) get_option('newss_youtube_cookie', '')) !== '',
            'yt_proxy_set'          => trim((string) get_option('newss_youtube_proxy', '')) !== '',
        ];

        // Usage
        $anthropicCalls = get_option('newss_anthropic_calls_today', null);
        $whisperCalls   = get_option('newss_whisper_calls_today', null);

        return new \WP_REST_Response([
            'ts'         => time(),
            'plugin'     => [
                'version'    => defined('NEWSS_VERSION') ? NEWSS_VERSION : '?',
                'db_version' => (string) get_option('newss_db_version', ''),
            ],
            'system'     => [
                'ytdlp_bin'    => $ytdlpBin,
                'ytdlp_found'  => $ytdlpFound,
                'ffmpeg_found' => $ffmpegFound,
                'wp_version'   => get_bloginfo('version'),
                'php_version'  => PHP_VERSION,
                'timezone'     => wp_timezone_string(),
                'site_url'     => site_url(),
            ],
            'cron'       => [
                'next_scheduled_ts'   => $nextRun ?: 0,
                'last_cron_run_ts'    => $lastCron,
                'last_cron_age_sec'   => $lastCron > 0 ? time() - $lastCron : null,
                'wp_cron_disabled'    => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON === true,
            ],
            'config'     => $config,
            'health'     => $health,
            'usage_today'=> [
                'anthropic_calls' => is_array($anthropicCalls) && ($anthropicCalls['date'] ?? '') === wp_date('Y-m-d') ? (int) ($anthropicCalls['count'] ?? 0) : 0,
                'whisper_calls'   => is_array($whisperCalls) && ($whisperCalls['date'] ?? '') === wp_date('Y-m-d') ? (int) ($whisperCalls['count'] ?? 0) : 0,
            ],
            'last_poll'  => is_array($lastPoll) ? [
                'time'     => (int) ($lastPoll['time'] ?? 0),
                'stats'    => $lastPoll['stats'] ?? null,
                'channels' => array_map(static fn($c): array => [
                    'name'  => (string) ($c['name'] ?? ''),
                    'id'    => (string) ($c['id'] ?? ''),
                    'ok'    => (bool) ($c['ok'] ?? false),
                    'count' => (int) ($c['count'] ?? 0),
                    'error' => (string) ($c['error'] ?? ''),
                ], (array) ($lastPoll['channels'] ?? [])),
            ] : null,
        ]);
    }

    public static function routeLog(\WP_REST_Request $req): \WP_REST_Response
    {
        $entries = Logger::all();
        $limit = (int) $req->get_param('limit');
        $level = (string) $req->get_param('level');
        $since = (float) $req->get_param('since');

        if ($since > 0) {
            $entries = array_values(array_filter($entries, static fn(array $e): bool => (float) ($e['ts'] ?? 0) > $since));
        }
        if ($level !== '') {
            $entries = array_values(array_filter($entries, static fn(array $e): bool => ($e['level'] ?? '') === $level));
        }
        // Neueste zuletzt zurueckgeben, cap auf limit
        $entries = array_slice($entries, -$limit);

        return new \WP_REST_Response([
            'ts'            => time(),
            'total_in_buffer' => count(Logger::all()),
            'returned'      => count($entries),
            'limit'         => $limit,
            'level_filter'  => $level,
            'since'         => $since,
            'entries'       => $entries,
        ]);
    }

    public static function routePipeline(\WP_REST_Request $req): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'ts'   => time(),
            'live' => Status::pipelineLiveData(),
        ]);
    }

    public static function routeChannels(\WP_REST_Request $req): \WP_REST_Response
    {
        $channels = (array) get_option('newss_channels', []);
        $out = [];
        foreach ($channels as $ch) {
            $out[] = [
                'id'       => (string) ($ch['id'] ?? ''),
                'name'     => (string) ($ch['name'] ?? ''),
                'type'     => (string) ($ch['type'] ?? 'channel'),
                'category' => (int) ($ch['category'] ?? 0),
                'enabled'  => !empty($ch['enabled']),
            ];
        }
        return new \WP_REST_Response([
            'ts'       => time(),
            'count'    => count($out),
            'channels' => $out,
        ]);
    }

    /**
     * Erzeugt einen neuen 32-char-Token, speichert ihn, liefert ihn zurueck.
     * Wird bei jedem Enable aufgerufen.
     */
    public static function generateToken(): string
    {
        $token = wp_generate_password(32, false, false);
        update_option(self::OPTION_TOKEN, $token, false);
        return $token;
    }

    public static function revokeToken(): void
    {
        delete_option(self::OPTION_TOKEN);
    }

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
}
