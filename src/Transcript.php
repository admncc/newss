<?php

declare(strict_types=1);

namespace Newss;

final class Transcript
{
    public const HEALTH_OPTION_PREFIX = 'newss_health_';

    public static function recordHealth(string $provider, bool $ok, string $note = ''): void
    {
        update_option(self::HEALTH_OPTION_PREFIX . $provider, [
            'ok'     => $ok,
            'note'   => $note,
            'ts'     => time(),
        ], false);
    }

    public string $lastProvider = '';

    /**
     * Pro-Provider-Diagnose des letzten fetch()-Calls.
     * Format: [['provider'=>'supadata','ok'=>false,'detail'=>'HTTP 206'], ...]
     *
     * @var array<int,array{provider:string,ok:bool,detail:string}>
     */
    public array $attemptLog = [];

    private function recordAttempt(string $provider, bool $ok, string $detail): void
    {
        $this->attemptLog[] = ['provider' => $provider, 'ok' => $ok, 'detail' => $detail];
    }

    public function fetch(string $videoId): string
    {
        $this->lastProvider = '';
        $this->attemptLog = [];
        $startTs = microtime(true);
        Logger::info('transcript: fetch start ' . $videoId);

        $supadataKey = (string) get_option('newss_supadata_api_key', '');
        if ($supadataKey !== '') {
            // Bei Supadata-Exception (429/5xx) NICHT zum Caller hochwerfen — sonst
            // retried Action-Scheduler den ganzen Job mit gleicher Provider-Reihenfolge.
            // Stattdessen fallen wir auf yt-dlp / Whisper zurück.
            try {
                $text = $this->fetchSupadata($videoId, $supadataKey);
                if ($text !== '') {
                    $this->lastProvider = 'supadata';
                    Logger::info('transcript: supadata ok ' . mb_strlen($text) . ' chars', ['duration_ms' => (int) ((microtime(true) - $startTs) * 1000)]);
                    return $text;
                }
            } catch (\Throwable $e) {
                Logger::warn('transcript: supadata exception ' . $e->getMessage());
                $this->recordAttempt('supadata', false, 'exception: ' . $e->getMessage());
            }
        } else {
            $this->recordAttempt('supadata', false, 'kein API-Key');
        }

        $text = $this->fetchYtDlp($videoId);
        if ($text !== '') {
            $this->lastProvider = 'yt-dlp';
            Logger::info('transcript: yt-dlp ok ' . mb_strlen($text) . ' chars', ['duration_ms' => (int) ((microtime(true) - $startTs) * 1000)]);
            return $text;
        }
        if ((bool) get_option('newss_whisper_enabled', false)) {
            $text = $this->fetchWhisper($videoId);
            if ($text !== '') {
                $this->lastProvider = 'whisper';
                Logger::info('transcript: whisper ok ' . mb_strlen($text) . ' chars', ['duration_ms' => (int) ((microtime(true) - $startTs) * 1000)]);
                return $text;
            }
        } else {
            $this->recordAttempt('whisper', false, 'deaktiviert');
        }
        Logger::warn('transcript: empty (alle Provider fehlgeschlagen)', ['attempts' => $this->attemptLog]);
        return '';
    }

    private function fetchSupadata(string $videoId, string $apiKey): string
    {
        $url = add_query_arg([
            'url'  => 'https://www.youtube.com/watch?v=' . $videoId,
            'lang' => 'de',
            'text' => 'true',
        ], 'https://api.supadata.ai/v1/youtube/transcript');

        $resp = wp_remote_get($url, [
            'timeout' => 60,
            'headers' => [
                'x-api-key' => $apiKey,
                'Accept'    => 'application/json',
            ],
        ]);

        if (is_wp_error($resp)) {
            $msg = $resp->get_error_message();
            error_log('[newss] supadata error: ' . $msg);
            self::recordHealth('supadata', false, 'network: ' . $msg);
            $this->recordAttempt('supadata', false, 'network: ' . $msg);
            return '';
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        if ($code !== 200) {
            error_log("[newss] supadata HTTP {$code}: " . substr($body, 0, 500));
            self::recordHealth('supadata', false, "HTTP {$code}");
            if ($code === 429 || $code >= 500) {
                throw new \RuntimeException("Supadata HTTP {$code} — retryable");
            }
            $this->recordAttempt('supadata', false, "HTTP {$code}");
            return '';
        }
        self::recordHealth('supadata', true, '');
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $this->recordAttempt('supadata', false, 'HTTP 200, kein JSON');
            return '';
        }

        $text = (string) ($data['content'] ?? $data['text'] ?? '');
        if ($text === '' && isset($data['transcript']) && is_array($data['transcript'])) {
            $parts = array_map(
                static fn($seg): string => is_array($seg) ? (string) ($seg['text'] ?? '') : '',
                $data['transcript']
            );
            $text = implode(' ', $parts);
        }
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            error_log("[newss] supadata 200-empty for {$videoId}: " . substr($body, 0, 200));
            $this->recordAttempt('supadata', false, 'HTTP 200, leerer Transcript');
        } else {
            $this->recordAttempt('supadata', true, mb_strlen($text) . ' chars');
        }
        return $text;
    }

    private function fetchYtDlp(string $videoId): string
    {
        if (!function_exists('shell_exec')) {
            $this->recordAttempt('yt-dlp', false, 'shell_exec deaktiviert');
            return '';
        }
        $bin = (string) get_option('newss_ytdlp_path', 'yt-dlp');
        $tmpDir = $this->makeTmpDir();
        if ($tmpDir === '') {
            $this->recordAttempt('yt-dlp', false, 'tmp-dir fail');
            return '';
        }
        $url = 'https://www.youtube.com/watch?v=' . $videoId;

        $cmd = sprintf(
            '%s%s --skip-download --write-auto-subs --write-subs --sub-langs %s --sub-format %s --convert-subs srt -o %s %s 2>&1',
            escapeshellarg($bin),
            self::proxyArg(),
            escapeshellarg('de.*,de,en.*,en'),
            escapeshellarg('vtt/srt/best'),
            escapeshellarg($tmpDir . '/sub'),
            escapeshellarg($url)
        );
        $stdout = (string) @shell_exec($cmd);

        $files = glob($tmpDir . '/sub*.srt') ?: [];
        if (!$files) {
            // Hint aus stdout extrahieren -- yt-dlp loggt z.B. 'Sign in to confirm
            // you are not a bot' oder 'No subtitles found' direkt
            $hint = '';
            if (preg_match('/(Sign in to confirm|HTTP Error \d+|No subtitles|This live event|members-only|Video unavailable)[^\n]{0,80}/i', $stdout, $m)) {
                $hint = ': ' . trim($m[0]);
            }
            $this->cleanup($tmpDir);
            $this->recordAttempt('yt-dlp', false, 'keine Captions' . $hint);
            return '';
        }
        usort($files, static fn(string $a, string $b): int =>
            (str_contains($b, '.de.') ? 1 : 0) <=> (str_contains($a, '.de.') ? 1 : 0)
        );
        $srt = (string) @file_get_contents($files[0]);
        $this->cleanup($tmpDir);
        $text = $this->srtToText($srt);
        if ($text === '') {
            $this->recordAttempt('yt-dlp', false, 'srt parsed, leer');
        } else {
            $this->recordAttempt('yt-dlp', true, mb_strlen($text) . ' chars');
        }
        return $text;
    }

    private function fetchWhisper(string $videoId): string
    {
        $apiKey = (string) get_option('newss_whisper_api_key', '');
        if ($apiKey === '') {
            $this->recordAttempt('whisper', false, 'kein API-Key');
            return '';
        }
        if (!function_exists('shell_exec')) {
            $this->recordAttempt('whisper', false, 'shell_exec deaktiviert');
            return '';
        }

        $cap = (int) get_option('newss_whisper_daily_cap', 0);
        if ($cap > 0) {
            $today = wp_date('Y-m-d');
            $opt   = get_option('newss_whisper_calls_today', null);
            $count = (is_array($opt) && ($opt['date'] ?? '') === $today) ? (int) ($opt['count'] ?? 0) : 0;
            if ($count >= $cap) {
                error_log('[newss] whisper daily cap reached; skipping');
                self::recordHealth('whisper', false, 'daily cap reached (' . $count . '/' . $cap . ')');
                $this->recordAttempt('whisper', false, "Cap erreicht ({$count}/{$cap})");
                return '';
            }
        }

        $bin = (string) get_option('newss_ytdlp_path', 'yt-dlp');
        $tmpDir = $this->makeTmpDir();
        if ($tmpDir === '') {
            $this->recordAttempt('whisper', false, 'tmp-dir fail');
            return '';
        }
        $url = 'https://www.youtube.com/watch?v=' . $videoId;

        // Whisper akzeptiert max 25 MB. mp3 mono 16kHz 32kbps -> ca. 60 Min.
        // bevor wir das Limit reissen. ffmpeg-postprocessor-args konvertiert
        // direkt nach dem yt-dlp-Audio-Extract.
        $cmd = sprintf(
            '%s%s -x --audio-format mp3 --postprocessor-args %s -o %s %s 2>&1',
            escapeshellarg($bin),
            self::proxyArg(),
            escapeshellarg('ffmpeg:-ar 16000 -ac 1 -b:a 32k'),
            escapeshellarg($tmpDir . '/audio.%(ext)s'),
            escapeshellarg($url)
        );
        $stdout = (string) @shell_exec($cmd);

        $files = glob($tmpDir . '/audio.mp3') ?: [];
        if (!$files) {
            $hint = '';
            if (preg_match('/(Sign in to confirm|HTTP Error \d+|This live event|members-only|Video unavailable)[^\n]{0,80}/i', $stdout, $m)) {
                $hint = ': ' . trim($m[0]);
            }
            $this->cleanup($tmpDir);
            $this->recordAttempt('whisper', false, 'audio-download fail' . $hint);
            return '';
        }
        $mp3 = $files[0];

        // Pre-Size-Check: Whisper-API-Limit ist 25 MB (26214400 Bytes).
        // Wir nehmen 24 MB als Sicherheits-Threshold.
        $size = (int) @filesize($mp3);
        $maxBytes = 24 * 1024 * 1024;
        if ($size > $maxBytes) {
            $this->cleanup($tmpDir);
            $this->recordAttempt('whisper', false, sprintf(
                'Audio %.1f MB > 24 MB (Whisper-Limit) — Video zu lang',
                $size / 1024 / 1024
            ));
            self::recordHealth('whisper', false, 'audio too large (' . round($size / 1024 / 1024, 1) . ' MB)');
            return '';
        }

        $boundary = wp_generate_password(24, false);
        $body = $this->multipartBody($boundary, $mp3, [
            'model'           => 'whisper-1',
            'response_format' => 'text',
        ]);

        $resp = wp_remote_post('https://api.openai.com/v1/audio/transcriptions', [
            'timeout' => 180,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => "multipart/form-data; boundary={$boundary}",
            ],
            'body' => $body,
        ]);

        $this->cleanup($tmpDir);

        if (is_wp_error($resp)) {
            $msg = $resp->get_error_message();
            error_log('[newss] whisper error: ' . $msg);
            self::recordHealth('whisper', false, 'network: ' . $msg);
            $this->recordAttempt('whisper', false, 'network: ' . $msg);
            return '';
        }
        $whCode = (int) wp_remote_retrieve_response_code($resp);
        if ($whCode !== 200) {
            $body = (string) wp_remote_retrieve_body($resp);
            error_log('[newss] whisper HTTP ' . $whCode . ': ' . $body);
            $errCode = '';
            $j = json_decode($body, true);
            if (is_array($j) && isset($j['error']['code'])) {
                $errCode = (string) $j['error']['code'];
            }
            self::recordHealth('whisper', false, "HTTP {$whCode}" . ($errCode ? " · {$errCode}" : ''));
            $this->recordAttempt('whisper', false, "HTTP {$whCode}" . ($errCode ? " · {$errCode}" : ''));
            return '';
        }
        self::recordHealth('whisper', true, '');
        // Counter erst nach Erfolg inkrementieren (verhindert Cap-Verbrennen bei Fail)
        if ((int) get_option('newss_whisper_daily_cap', 0) > 0) {
            $today = wp_date('Y-m-d');
            $opt   = get_option('newss_whisper_calls_today', null);
            $count = (is_array($opt) && ($opt['date'] ?? '') === $today) ? (int) ($opt['count'] ?? 0) : 0;
            update_option('newss_whisper_calls_today', ['date' => $today, 'count' => $count + 1], false);
        }
        $text = trim((string) wp_remote_retrieve_body($resp));
        $this->recordAttempt('whisper', true, mb_strlen($text) . ' chars');
        return $text;
    }

    private function srtToText(string $srt): string
    {
        $lines = preg_split('/\R/', $srt) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_contains($line, '-->') || preg_match('/^\d+$/', $line)) {
                continue;
            }
            $line = preg_replace('/<[^>]+>/', '', $line);
            $out[] = $line;
        }
        $text = implode(' ', $out);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim((string) $text);
    }

    private function multipartBody(string $boundary, string $filePath, array $fields): string
    {
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $body .= $value . "\r\n";
        }
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"" . basename($filePath) . "\"\r\n";
        $body .= "Content-Type: audio/mpeg\r\n\r\n";
        $body .= (string) file_get_contents($filePath) . "\r\n";
        $body .= "--{$boundary}--\r\n";
        return $body;
    }

    private function makeTmpDir(): string
    {
        $base = sys_get_temp_dir() . '/newss_' . wp_generate_password(10, false);
        return @mkdir($base, 0700, true) ? $base : '';
    }

    /**
     * Returns ' --proxy <escaped-url>' (with leading space) or '' if no proxy configured.
     */
    private static function proxyArg(): string
    {
        $proxy = Http::randomProxy();
        if ($proxy === null) {
            return '';
        }
        return ' --proxy ' . escapeshellarg($proxy);
    }

    private function cleanup(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
