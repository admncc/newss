# Newss

WordPress-Plugin: Pollt YouTube-Kanäle, transkribiert neue Videos und erstellt automatisch Artikel via Anthropic Claude.

## Funktionsweise

1. WP-Cron läuft täglich um **09:00 und 19:00 (Europe/Berlin)**
2. Pro konfiguriertem Kanal wird der öffentliche YouTube-RSS-Feed (`https://www.youtube.com/feeds/videos.xml?channel_id=…`) gepollt
3. Jedes neue Video wird als asynchroner Job in die Action-Scheduler-Queue gelegt
4. Worker pro Video:
   - Transkript via `yt-dlp --write-auto-subs` (Deutsch/Englisch); Fallback OpenAI Whisper API
   - Rewrite via Anthropic Claude (Tool-Use → strukturierter Output)
   - WordPress-Post anlegen (Auto-Publish), YouTube-Embed oben, KI-Disclosure unten, Featured Image vom YouTube-Thumbnail

## Server-Voraussetzungen

- PHP ≥ 8.1
- WordPress ≥ 6.4
- `yt-dlp` und `ffmpeg` im PATH des PHP-Users
- Composer (einmalig zum Installieren der Dependencies)
- Eigener VPS / Root oder Hosting mit aktiviertem `shell_exec`

```bash
apt install ffmpeg python3-pip
pip install -U yt-dlp
```

## Installation

```bash
cd /pfad/zu/wp-content/plugins/
git clone <repo-url> newss
cd newss
composer install --no-dev --optimize-autoloader
```

Dann im WP-Backend unter **Plugins** → **Newss** aktivieren.

## Konfiguration

**Newss → Einstellungen** (im Admin-Menü):

- **Anthropic Claude:** API-Key, Modell (Default `claude-sonnet-4-6`), Max-Tokens, Temperature, System-Prompt, User-Prompt-Template
- **Transkript:** yt-dlp-Pfad, Whisper-Fallback an/aus + OpenAI-Key
- **Veröffentlichung:** Default-Kategorie, Status (Publish/Draft), Kill-Switch, Autor

**Newss → Kanäle:** Channel-IDs (`UC…`, 24 Zeichen) hinzufügen. Pro Kanal optional eigene Kategorie.

## Server-Cron (empfohlen)

WP-Cron ist eventbasiert — feuert nur bei Seitenbesuchen. Für zuverlässige 09:00 / 19:00-Ausführung:

```bash
# /etc/cron.d/newss
0 9,19 * * * www-data curl -fsS https://deine-domain.de/wp-cron.php?doing_wp_cron > /dev/null
```

Und in `wp-config.php`:

```php
define('DISABLE_WP_CRON', true);
```

## Action Scheduler – Queue beobachten

Im WP-Backend unter **Tools → Scheduled Actions** sind alle gequeuten / fehlgeschlagenen Jobs sichtbar. Action Scheduler retried fehlgeschlagene Jobs automatisch mit Backoff.

## Rechtliche Hinweise

- Du bist für die Auswahl der Kanäle und die Einhaltung von Urheber-/Markenrechten **selbst** verantwortlich. Das Plugin trifft keine Annahmen über Lizenzen.
- Die KI-Disclosure am Artikelende ist Pflicht nach EU AI Act Art. 50 (Transparenzpflicht für synthetische Inhalte). Nicht entfernen.
- YouTube-Embed über `youtube-nocookie.com` setzt erst nach Klick Cookies — trotzdem im Cookie-Banner als Drittinhalt deklarieren.
- Keine Garantie auf Faktentreue — Halluzinationsrisiko ist nicht null.

## Entwicklung

- Pure PHP-Plugin, namespaced `Newss\…`, PSR-4 Autoload via Composer
- Job-Queue: `woocommerce/action-scheduler`
- Keine externen Anthropic-/OpenAI-SDKs — alles via `wp_remote_post`
- Strukturierter Claude-Output via Tool-Use (`publish_article`)
- Prompt-Caching auf System-Prompt aktiv (~90 % Input-Cost-Ersparnis)
