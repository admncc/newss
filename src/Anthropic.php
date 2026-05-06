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
            Logger::error('anthropic: missing API key');
            return null;
        }

        if (!self::canMakeCall()) {
            Logger::warn('anthropic: daily cap reached, skipping');
            return null;
        }
        $startTs = microtime(true);
        Logger::info('anthropic: rewrite start', [
            'video' => (string) ($payload['video_id'] ?? ''),
            'chars' => mb_strlen($transcript),
        ]);

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

        $maxAttempts = 3;
        $attempt = 0;
        while (true) {
            $attempt++;
            $resp = wp_remote_post(self::API_URL, [
                'timeout' => 60,
                'headers' => [
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'content-type'      => 'application/json',
                ],
                'body' => wp_json_encode($body),
            ]);

            if (is_wp_error($resp)) {
                error_log('[newss] anthropic network error (try ' . $attempt . '): ' . $resp->get_error_message());
                if ($attempt >= $maxAttempts) {
                    throw new \RuntimeException('Anthropic network failure after ' . $maxAttempts . ' attempts');
                }
                sleep(2 ** $attempt);
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code($resp);
            $raw  = (string) wp_remote_retrieve_body($resp);

            if ($code === 200) {
                self::recordSuccessfulCall();
                Logger::info('anthropic: rewrite ok HTTP 200', [
                    'duration_ms' => (int) ((microtime(true) - $startTs) * 1000),
                    'attempts'    => $attempt,
                ]);
                break;
            }

            // 429 + 5xx = retry-worthy
            if ($code === 429 || $code >= 500) {
                Logger::warn("anthropic: HTTP {$code} retry {$attempt}", ['body' => substr($raw, 0, 200)]);
                if ($attempt >= $maxAttempts) {
                    throw new \RuntimeException("Anthropic HTTP {$code} after {$maxAttempts} attempts; will retry via AS");
                }
                sleep(2 ** $attempt);
                continue;
            }

            // Permanent 4xx = skip (no retry, return null)
            Logger::error("anthropic: HTTP {$code} permanent", ['body' => substr($raw, 0, 300)]);
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            Logger::error('anthropic: response not JSON');
            return null;
        }
        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'publish_article') {
                $input = $block['input'] ?? null;
                return is_array($input) ? $input : null;
            }
        }
        Logger::error('anthropic: no publish_article tool_use in response');
        return null;
    }

    /**
     * Lese-Check VOR dem Call. Counter wird erst nach erfolgreichem 200 erhöht
     * (siehe recordSuccessfulCall) — verhindert Quota-Verbrennen bei Network-Fail.
     */
    private static function canMakeCall(): bool
    {
        $cap = (int) get_option('newss_anthropic_daily_cap', 0);
        if ($cap <= 0) {
            return true;
        }
        $today = wp_date('Y-m-d');
        $opt   = get_option('newss_anthropic_calls_today', null);
        $count = (is_array($opt) && ($opt['date'] ?? '') === $today) ? (int) ($opt['count'] ?? 0) : 0;
        return $count < $cap;
    }

    /**
     * Atomic increment via MySQL GET_LOCK — schützt gegen Race zwischen parallelen Workern.
     */
    private static function recordSuccessfulCall(): void
    {
        global $wpdb;
        $today = wp_date('Y-m-d');
        $lockName = 'newss_quota_' . $today;
        $got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $lockName));
        if ((int) $got !== 1) {
            // Konnte Lock nicht holen — fall-through ohne Increment (besser als blockieren)
            return;
        }
        try {
            $opt   = get_option('newss_anthropic_calls_today', null);
            $count = (is_array($opt) && ($opt['date'] ?? '') === $today) ? (int) ($opt['count'] ?? 0) : 0;
            update_option('newss_anthropic_calls_today', [
                'date'  => $today,
                'count' => $count + 1,
            ], false);
        } finally {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName));
        }
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
                    'topic_tags' => [
                        'type'        => 'array',
                        'items'       => [
                            'type' => 'string',
                            'enum' => array_keys(self::topicLabels()),
                        ],
                        'description' => 'Liste aller sensiblen Themen die im Hauptthema vorkommen. Leeres Array wenn keins zutrifft.',
                    ],
                ],
            ],
        ];
    }

    /**
     * Schneller Pre-Filter: nur Video-Titel + Channel an Haiku, nur Topic-Tags
     * zurueck. Spart Transcript-Fetch + grossen Rewrite-Call wenn das Thema
     * blockiert wird (Whisper-$, Anthropic-Token).
     *
     * Returns array<string> der Topic-Tag-Keys oder null bei Fehler / kein
     * API-Key / Cap erreicht. Fail-open: bei null laesst der Caller den
     * normalen Flow weiterlaufen — der Post-Rewrite-Check faengt's dann.
     */
    public function preClassifyTopics(string $title, string $channel): ?array
    {
        $apiKey = (string) get_option('newss_anthropic_api_key', '');
        if ($apiKey === '') {
            return null;
        }
        if (!self::canMakeCall()) {
            error_log('[newss] preclassify skipped: anthropic daily cap reached');
            return null;
        }

        $model = (string) get_option('newss_preclassify_model', 'claude-haiku-4-5-20251001');

        $tool = [
            'name'        => 'classify_topics',
            'description' => 'Klassifiziere das Video-Thema in sensible Kategorien.',
            'input_schema' => [
                'type'     => 'object',
                'required' => ['topic_tags'],
                'properties' => [
                    'topic_tags' => [
                        'type'  => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => array_keys(self::topicLabels()),
                        ],
                        'description' => 'Sensible Themen die das Hauptthema des Videos beruehren. Leeres Array wenn keins zutrifft.',
                    ],
                ],
            ],
        ];

        $userPrompt = sprintf(
            "Klassifiziere ein YouTube-Video anhand von Titel und Kanal in sensible Themen-Kategorien.\n\nTitel: %s\nKanal: %s\n\nGib ALLE zutreffenden Topic-Tags zurueck. Wenn das Hauptthema in keine Kategorie faellt, leeres Array.",
            $title,
            $channel
        );

        $body = [
            'model'       => $model,
            'max_tokens'  => 200,
            'temperature' => 0.0,
            'messages'    => [[
                'role'    => 'user',
                'content' => $userPrompt,
            ]],
            'tools'       => [$tool],
            'tool_choice' => ['type' => 'tool', 'name' => 'classify_topics'],
        ];

        $resp = wp_remote_post(self::API_URL, [
            'timeout' => 30,
            'headers' => [
                'x-api-key'         => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($resp)) {
            error_log('[newss] preclassify network error: ' . $resp->get_error_message());
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            error_log('[newss] preclassify HTTP ' . $code . ': ' . substr((string) wp_remote_retrieve_body($resp), 0, 200));
            return null;
        }
        self::recordSuccessfulCall();

        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($data)) {
            return null;
        }
        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'classify_topics') {
                $tags = (array) ($block['input']['topic_tags'] ?? []);
                return array_values(array_filter($tags, 'is_string'));
            }
        }
        return null;
    }

    public static function topicLabels(): array
    {
        return [
            'krieg-konflikt'           => 'Krieg & militärische Konflikte',
            'gewalt-verbrechen'        => 'Gewalt & Verbrechen',
            'sex-erotik'               => 'Sexuelle / erotische Inhalte',
            'suizid-selbstverletzung'  => 'Suizid & Selbstverletzung',
            'drogen-sucht'             => 'Drogen & Sucht',
            'extremismus-hass'         => 'Extremismus & Hassrede',
            'terror'                   => 'Terrorismus',
        ];
    }

    /**
     * Heuristik-Stichworte pro Topic — wenn klare Treffer im Titel sind,
     * brauchen wir keinen Haiku-Call. Konservativ angesetzt, nur eindeutige
     * Begriffe — bei Grenzfaellen faellt der Caller auf preClassifyTopics()
     * zurueck.
     *
     * @return array<string,array<string>>
     */
    public static function topicKeywords(): array
    {
        return [
            'krieg-konflikt' => [
                'krieg', 'kriegs',
                'panzerangriff', 'raketenangriff', 'luftangriff', 'bombardierung', 'frontlinie',
                'invasion', 'militärschlag', 'gefallen im',
                'ukraine-krieg', 'gaza-krieg', 'nahost-konflikt',
            ],
            'gewalt-verbrechen' => [
                'mord', 'mordfall', 'mordversuch', 'totschlag',
                'erstochen', 'erschossen', 'erschlagen',
                'überfallen', 'überfall', 'amoklauf', 'amokläufer',
                'vergewaltigung', 'missbraucht', 'kindesmissbrauch',
            ],
            'sex-erotik' => [
                'porno', 'pornografi', 'sex-tape', 'onlyfans',
                'erotik', 'erotische', 'fetisch',
                'nacktbild', 'nudes', 'sexszene',
            ],
            'suizid-selbstverletzung' => [
                'suizid', 'selbstmord', 'selbsttötung',
                'selbstverletzung', 'sich das leben', 'sprung in den tod',
                'magersucht', 'essstörung',
            ],
            'drogen-sucht' => [
                'kokain', 'heroin', 'crystal meth', 'crack',
                'fentanyl', 'überdosis', 'drogensucht', 'drogenabhängig',
                'drogentoter',
            ],
            'extremismus-hass' => [
                'rechtsextrem', 'linksextrem', 'neonazi', 'reichsbürger',
                'islamist', 'antisemit', 'rassistisch', 'fremdenfeindlich',
                'volksverhetzung', 'hassrede',
            ],
            'terror' => [
                'terroranschlag', 'terrorist', 'attentat',
                'sprengstoffanschlag', 'selbstmordanschlag',
                'isis-', ' isis ', 'al-qaida', 'hamas-terror',
            ],
        ];
    }

    /**
     * Stage-1-Pre-Filter (gratis, lokal): pruef Video-Titel gegen
     * Topic-Stichworte. Liefert die Liste der Topic-Keys mit Treffer
     * — nur die, die in $blockedTopics sind, werden ueberhaupt geprueft.
     *
     * @param array<string> $blockedTopics
     * @return array<string>
     */
    public static function heuristicTopicCheck(string $title, array $blockedTopics): array
    {
        if ($title === '' || $blockedTopics === []) {
            return [];
        }
        $haystack = ' ' . mb_strtolower($title) . ' ';
        $hits = [];
        $allKeywords = self::topicKeywords();
        foreach ($blockedTopics as $topic) {
            $words = $allKeywords[$topic] ?? [];
            foreach ($words as $word) {
                if (mb_strpos($haystack, mb_strtolower($word)) !== false) {
                    $hits[] = $topic;
                    break;
                }
            }
        }
        return array_values(array_unique($hits));
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
            'Arbeit & Beruf',
            'Verbraucher & Lifestyle',
            'Verkehr & Mobilität',
            'Gesellschaft',
            'Klima & Umwelt',
            'Gesundheit',
            'Justiz & Recht',
            'Kultur & Medien',
            'Technologie & Wissenschaft',
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
- Topic-Tags: gib im Feld topic_tags alle aus der Enum-Liste passenden Tags zurück, wenn das Hauptthema des Videos eines davon ist (z. B. Kriegsberichterstattung → "krieg-konflikt", erotischer Inhalt → "sex-erotik"). Wenn keins zutrifft, leeres Array.

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
