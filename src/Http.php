<?php

declare(strict_types=1);

namespace Newss;

/**
 * Wrapper um wp_remote_get der bei gesetzter Proxy-Option den Request
 * via cURL-Proxy schickt. Wird ausschliesslich von YouTube-Calls genutzt
 * (RSS-Polling, Channel-Resolver), nicht von Anthropic/Supadata.
 *
 * Mehrere Proxies (eine Zeile pro Eintrag) werden bei jedem Request
 * randomisiert rotiert.
 */
final class Http
{
    public static function get(string $url, array $args = []): array|\WP_Error
    {
        $proxies = self::loadProxies();
        if ($proxies === []) {
            return wp_remote_get($url, $args);
        }

        $proxy = $proxies[array_rand($proxies)];

        $filter = static function ($handle) use ($proxy) {
            curl_setopt($handle, CURLOPT_PROXY, $proxy);
            curl_setopt($handle, CURLOPT_PROXYAUTH, CURLAUTH_ANY);
            return $handle;
        };
        add_action('http_api_curl', $filter);
        try {
            return wp_remote_get($url, $args);
        } finally {
            remove_action('http_api_curl', $filter);
        }
    }

    /**
     * @return string[] List of proxy URLs ready for CURLOPT_PROXY.
     */
    private static function loadProxies(): array
    {
        $raw = (string) get_option('newss_youtube_proxy', '');
        if ($raw === '') {
            return [];
        }
        $lines = preg_split('/\R/', $raw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $normalized = self::normalize($line);
            if ($normalized !== '') {
                $out[] = $normalized;
            }
        }
        return $out;
    }

    private static function normalize(string $line): string
    {
        if (preg_match('#^(?:https?|socks(?:4|4a|5|5h)?)://#i', $line)) {
            return $line;
        }
        $parts = explode(':', $line);
        $count = count($parts);
        if ($count === 4) {
            return sprintf(
                'http://%s:%s@%s:%s',
                rawurlencode($parts[2]),
                rawurlencode($parts[3]),
                $parts[0],
                $parts[1]
            );
        }
        if ($count === 2) {
            return sprintf('http://%s:%s', $parts[0], $parts[1]);
        }
        return '';
    }
}
