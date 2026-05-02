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
        $maxTokens   = (int) get_option('newss_anthropic_max_tokens', 4000);
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
        $categories = self::categoryList();
        $categoryProp = [
            'type'        => 'string',
            'description' => 'Wähle die thematisch passendste Kategorie aus der vorgegebenen Liste.',
        ];
        if ($categories !== []) {
            $categoryProp['enum'] = $categories;
        }

        return [
            'name'        => 'publish_article',
            'description' => 'Veröffentliche den umgeschriebenen Artikel als strukturierte Daten (SEO-optimiert).',
            'input_schema' => [
                'type'     => 'object',
                'required' => ['title', 'slug', 'focus_keyword', 'meta_description', 'category', 'body_html', 'tags', 'image_alt'],
                'properties' => [
                    'category' => $categoryProp,
                    'title' => [
                        'type'        => 'string',
                        'minLength'   => 30,
                        'maxLength'   => 65,
                        'description' => 'SEO-Title, 30–65 Zeichen, Fokus-Keyword möglichst weit vorne.',
                    ],
                    'slug' => [
                        'type'        => 'string',
                        'minLength'   => 10,
                        'maxLength'   => 60,
                        'pattern'     => '^[a-z0-9-]+$',
                        'description' => 'URL-Slug, 3–6 Wörter, kleinbuchstaben + Bindestriche, mit Fokus-Keyword.',
                    ],
                    'focus_keyword' => [
                        'type'        => 'string',
                        'minLength'   => 3,
                        'maxLength'   => 50,
                        'description' => 'Wichtigstes Suchwort (1–4 Wörter, möglichst Singular).',
                    ],
                    'meta_description' => [
                        'type'        => 'string',
                        'minLength'   => 120,
                        'maxLength'   => 160,
                        'description' => 'Meta-Description für SERPs, 120–160 Zeichen, Fokus-Keyword einmal, aktive Sprache, anderer Wortlaut als der Title.',
                    ],
                    'body_html' => [
                        'type'        => 'string',
                        'description' => 'Artikel als HTML, 400–600 Wörter, mit <h2>-Zwischenüberschriften.',
                    ],
                    'tags' => [
                        'type'     => 'array',
                        'items'    => ['type' => 'string', 'maxLength' => 40],
                        'minItems' => 3,
                        'maxItems' => 6,
                    ],
                    'image_alt' => [
                        'type'        => 'string',
                        'minLength'   => 40,
                        'maxLength'   => 125,
                        'description' => 'Alt-Text für das Featured Image, beschreibt Bildinhalt + enthält Fokus-Keyword.',
                    ],
                ],
            ],
        ];
    }

    public static function categoryList(): array
    {
        $raw = (string) get_option('newss_category_list', self::defaultCategoriesText());
        $names = array_map('trim', preg_split('/\R/', $raw) ?: []);
        return array_values(array_filter($names, static fn(string $n): bool => $n !== ''));
    }

    public static function defaultCategoriesText(): string
    {
        return implode("\n", [
            'Weltpolitik',
            'Innenpolitik',
            'Europa',
            'Wirtschaft & Finanzen',
            'Gesellschaft',
            'Klima & Umwelt',
            'Kultur & Medien',
            'Technologie',
            'Sport',
        ]);
    }

    public static function defaultSystemPrompt(): string
    {
        return <<<PROMPT
Du bist ein erfahrener Online-Redakteur und SEO-Texter für ein deutschsprachiges Nachrichtenportal.

Aufgabe: Aus dem Transkript eines YouTube-Videos einen SEO-optimierten, eigenständigen Artikel verfassen.

SEO-Vorgaben (Pflicht):
- Fokus-Keyword: das wichtigste Suchwort des Themas, 1–4 Wörter, möglichst Singular
- Title: 30–65 Zeichen, Fokus-Keyword möglichst weit vorne, klickstark aber nicht reißerisch, kein Clickbait
- Slug: 3–6 Wörter, nur Kleinbuchstaben und Bindestriche, mit Fokus-Keyword
- Meta-Description: 120–160 Zeichen, Fokus-Keyword genau einmal, aktive Sprache, in eigenen Worten — nicht den Title wiederholen
- Image-Alt: 40–125 Zeichen, beschreibt was im Bild zu sehen wäre, enthält Fokus-Keyword
- Tags: 3–6 thematisch relevante Begriffe (Personennamen, Orte, Schlüsselthemen)
- Kategorie: wähle exakt eine Kategorie aus der vorgegebenen Liste (siehe Tool-Schema enum). Wenn nichts perfekt passt, nimm die thematisch nächstliegende — keine eigene Kategorie erfinden.

Body-Struktur (700–1000 Wörter):
- Lead-Absatz (80–120 Wörter): Fokus-Keyword in den ersten 100 Wörtern, beantwortet die wichtigsten W-Fragen direkt
- 3–4 <h2>-Zwischenüberschriften, jede mit eigenem Themen-Aspekt; das Fokus-Keyword oder ein Synonym in mindestens einer <h2>
- Pro <h2>-Sektion 150–250 Wörter substantieller Inhalt
- 6–10 wichtige Begriffe im Body in <strong>fett</strong>
- Mindestens eine <ul>-Aufzählungsliste mit 3–5 Punkten wenn inhaltlich passend (z. B. Fakten, Standpunkte, Zahlen)
- Kurze Absätze (2–4 Sätze)
- Abschluss-Absatz mit Ausblick / Einordnung
- Erlaubte HTML-Tags ausschließlich: <p>, <h2>, <h3>, <strong>, <em>, <ul>, <ol>, <li>, <br>
- Keine <a>-Links, keine externen Verweise erfinden

Stil:
- Sachlich, präzise, journalistisch
- Keine Floskeln wie „Im Video sehen wir" oder „Der Sprecher sagt" — schreib direkt über das Thema
- Wenn Transkript zu kurz oder unklar: einen kürzeren Artikel zurückgeben; keine Fakten halluzinieren

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
