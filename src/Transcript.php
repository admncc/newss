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

    public function fetch(string $videoId): string
    {
        $this->lastProvider = '';

        $supadataKey = (string) get_option('newss_supadata_api_key', '');
        if ($supadataKey !== '') {
            $text = $this->fetchSupadata($videoId, $supadataKey);
            if ($text !== '') {
                $this->lastProvider = 'supadata';
                return $text;
            }
        }

        $text = $this->fetchYtDlp($videoId);
        if ($text !== '') {
            $this->lastProvider = 'yt-dlp';
            return $text;
        }
        if ((bool) get_option('newss_whisper_enabled', false)) {
            $text = $this->fetchWhisper($videoId);
            if ($text !== '') {
                $this->lastProvider = 'whisper';
                return $text;
            }
        }
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
            return '';
        }
        self::recordHealth('supadata', true, '');
        $data = json_decode($body, true);
        if (!is_array($data)) {
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
        }
        return $text;
    }

    private function fetchYtDlp(string $videoId): string
    {
        if (!function_exists('shell_exec')) {
            return '';
        }
        $bin = (string) get_option('newss_ytdlp_path', 'yt-dlp');
        $tmpDir = $this->makeTmpDir();
        if ($tmpDir === '') {
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
        @shell_exec($cmd);

        $files = glob($tmpDir . '/sub*.srt') ?: [];
        if (!$files) {
            $this->cleanup($tmpDir);
            return '';
        }
        usort($files, static fn(string $a, string $b): int =>
            (str_contains($b, '.de.') ? 1 : 0) <=> (str_contains($a, '.de.') ? 1 : 0)
        );
        $srt = (string) @file_get_contents($files[0]);
        $this->cleanup($tmpDir);
        return $this->srtToText($srt);
    }

    private function fetchWhisper(string $videoId): string
    {
        $apiKey = (string) get_option('newss_whisper_api_key', '');
        if ($apiKey === '' || !function_exists('shell_exec')) {
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
                return '';
            }
        }

        $bin = (string) get_option('newss_ytdlp_path', 'yt-dlp');
        $tmpDir = $this->makeTmpDir();
        if ($tmpDir === '') {
            return '';
        }
        $url = 'https://www.youtube.com/watch?v=' . $videoId;

        $cmd = sprintf(
            '%s%s -x --audio-format mp3 --audio-quality 9 -o %s %s 2>&1',
            escapeshellarg($bin),
            self::proxyArg(),
            escapeshellarg($tmpDir . '/audio.%(ext)s'),
            escapeshellarg($url)
        );
        @shell_exec($cmd);

        $files = glob($tmpDir . '/audio.mp3') ?: [];
        if (!$files) {
            $this->cleanup($tmpDir);
            return '';
        }
        $mp3 = $files[0];

        $boundary = wp_generate_password(24, false);
        $body = $this->multipartBody($boundary, $mp3, [
            'model'           => 'whisper-1',
            'response_format' => 'text',
        ]);

        $resp = wp_remote_post('https://api.openai.com/v1/audio/transcriptions', [
            'timeout' => 600,
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
        return trim((string) wp_remote_retrieve_body($resp));
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
