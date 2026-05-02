<?php

declare(strict_types=1);

namespace Newss;

final class Anthropic
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function rewrite(string $transcript, array $payload): ?array
    {
        $apiKey = (string) get_option('newss_anthropic_api_key', '');
        if ($apiKey === '') {
            error_log('[newss] missing Anthropic API key');
            return null;
        }

        $model       = (string) get_option('newss_anthropic_model', 'claude-sonnet-4-6');
        $maxTokens   = (int) get_option('newss_anthropic_max_tokens', 2000);
        $temperature = (float) get_option('newss_anthropic_temperature', 1.0);
        $system      = (string) get_option('newss_system_prompt', self::defaultSystemPrompt());
        $template    = (string) get_option('newss_user_prompt_template', self::defaultUserTemplate());

        $userPrompt = strtr($template, [
            '{title}'        => (string) ($payload['video_title'] ?? ''),
            '{channel}'      => (string) ($payload['channel_name'] ?? ''),
            '{published_at}' => (string) ($payload['published'] ?? ''),
            '{transcript}'   => $transcript,
        ]);

        $body = [
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
            'system'      => [[
                'type'          => 'text',
                'text'          => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'messages'    => [[
                'role'    => 'user',
                'content' => $userPrompt,
            ]],
            'tools'       => [self::publishArticleTool()],
            'tool_choice' => ['type' => 'tool', 'name' => 'publish_article'],
        ];

        $resp = wp_remote_post(self::API_URL, [
            'timeout' => 120,
            'headers' => [
                'x-api-key'         => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($resp)) {
            error_log('[newss] anthropic error: ' . $resp->get_error_message());
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw  = (string) wp_remote_retrieve_body($resp);
        if ($code !== 200) {
            error_log("[newss] anthropic HTTP {$code}: {$raw}");
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'publish_article') {
                $input = $block['input'] ?? null;
                return is_array($input) ? $input : null;
            }
        }
        return null;
    }

    private static function publishArticleTool(): array
    {
        return [
            'name'        => 'publish_article',
            'description' => 'Veröffentliche den umgeschriebenen Artikel als strukturierte Daten.',
            'input_schema' => [
                'type'     => 'object',
                'required' => ['title', 'slug', 'excerpt', 'body_html', 'tags'],
                'properties' => [
                    'title'     => ['type' => 'string', 'maxLength' => 100],
                    'slug'      => ['type' => 'string', 'maxLength' => 80],
                    'excerpt'   => ['type' => 'string', 'maxLength' => 200],
                    'body_html' => ['type' => 'string'],
                    'tags'      => [
                        'type'     => 'array',
                        'items'    => ['type' => 'string', 'maxLength' => 40],
                        'maxItems' => 6,
                    ],
                ],
            ],
        ];
    }

    public static function defaultSystemPrompt(): string
    {
        return <<<PROMPT
Du bist ein erfahrener Online-Redakteur für ein deutschsprachiges Nachrichtenportal.

Aufgabe: Aus dem Transkript eines YouTube-Videos einen eigenständigen Artikel von 250–350 Wörtern verfassen.

Regeln:
- Sachlich, präzise, journalistisch — keine Floskeln wie „Im Video sehen wir" oder „Der Sprecher sagt"
- Schreibe direkt über das Thema, nicht über das Video
- 4–6 wichtige Begriffe in <strong>fett</strong>
- Strukturiere in 4–6 kurze Absätze mit <p>...</p>
- Erlaubte HTML-Tags: <p>, <strong>, <em> — keine anderen
- Wenn das Transkript zu kurz oder unklar ist, gib einen kürzeren Artikel zurück; erfinde keine Fakten

Antworte ausschließlich über das Tool `publish_article` mit gültigen Werten gemäß Schema.
PROMPT;
    }

    public static function defaultUserTemplate(): string
    {
        return <<<TPL
Original-Titel: {title}
Kanal: {channel}
Veröffentlicht: {published_at}

Transkript:
{transcript}
TPL;
    }
}
