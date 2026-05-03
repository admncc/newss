# Newss — Backlog

Status: Sprint 1–6 aus dem QA-Review (2026-05-03) sind durch. Hier die Nice-to-haves die wir bewusst zurückgestellt haben.

## 🟢 Mid-Priority (UX-Boosts)

- [ ] **Test-Single-Video-Button** im Admin — Video-ID rein, sofort Worker triggern (synchron mit Output-Buffer fürs Debugging)
- [ ] **System-Health-Check Box** oben in Settings — Live-Pings: Anthropic / Supadata / YouTube-RSS via Proxy / WP-Cron-Letzter-Lauf, jeweils rot/grün
- [ ] **Per-Channel-Statistiken persistent** — Custom-Tabelle `wp_newss_channel_stats(channel_id, day, polled, found, posted, skipped, failed)` plus Sparkline-Chart pro Kanal
- [ ] **Settings-Reset-Buttons** pro Section (System-Prompt, User-Template, Kategorien, Topics)
- [ ] **Settings-Export/Import** als JSON
- [ ] **Bulk-Aktionen** auf Channels-Tabelle (Aktivieren/Deaktivieren/Löschen mehrere)
- [ ] **Webhook bei completed Posts** — Setting "Webhook-URL" + `do_action('newss_post_published', $postId, $payload, $rewrite)` + simpler `wp_remote_post`
- [ ] **Action-Scheduler-Tabellen-Größe** im Status-Tab anzeigen
- [ ] **Anthropic Prompt-Caching messen** — `cache_read_input_tokens` aus Response auslesen, in `newss_anthropic_stats` aggregieren, im Status anzeigen ("X% cache hit rate, gespart Y tokens")
- [ ] **RSS-Polling parallel** via `Requests::request_multiple()` — bei 14 Channels × 30 s seriell = 7 min worst case → ~30 s parallel
- [ ] **Lookup-Tabelle `wp_newss_videos`** — schnellerer Cache als Postmeta (PK-Lookup statt Index-Scan); ersetzt sowohl Worker::videoAlreadyHasPost als auch Pending-Transient
- [ ] **Whisper-Pfad asynchron splitten** — aktuell blockt yt-dlp+Whisper den Worker minutenlang bei langen Videos; in Sub-Steps splitten

## 💡 Idea-Stash (interessant aber nicht dringend)

- [ ] **Fact-Check-Layer** — zweiter Haiku-Call zur Plausibilitätsprüfung; bei niedriger Konfidenz → Draft statt Publish
- [ ] **A/B-Test verschiedener System-Prompts** — Variants-Setting, zufällig zugewiesen, Tracking via Rank-Math/GSC-API
- [ ] **Schwarzliste für einzelne Video-IDs** — Setting `newss_video_blacklist` + UI-Button "Diese Video-ID nie verarbeiten" auf Status-Page
- [ ] **Per-Channel-Custom-Prompt-Override** — Channel-Entry kriegt optionales `prompt_override`-Feld
- [ ] **Multi-Site-Variante** — Settings pro Subsite, gemeinsamer API-Key auf Network-Level
- [ ] **Fallback auf `youtube-transcript-api` (Python)** — vierter Provider in Adapter-Kette
- [ ] **Slug-Kollisions-Sanity** — pre-check via `get_page_by_path` + Notification statt automatisches `-2`-Suffix
- [ ] **i18n** — `__()` / `_e()` / Text-Domain durchziehen (für WP.org-Submit Pflicht)
- [ ] **Audit-Log mit User-ID** für Channel-Änderungen

## ❌ Bewusst verworfen

- ~~"Anthropic-Modell-IDs `claude-sonnet-4-6` etc. sind Phantasie"~~ — sind die korrekten Bezeichner der Claude-4.x-Familie (QA #1 hatte einen alten Wissensstand)
- ~~"`as_enqueue_async_action` Payload `[[$payload]]` fragil"~~ — semantisch korrekt; AS unpackt das innere Array als positional args, hookt mit `do_action(hook, $payload)`

## Erledigt aus dem 2026-05-03 Review

- ✅ Sprint 1 — Race-Condition-Lock + Pending-Transient
- ✅ Sprint 2 — Sanitization, Tag-Fix, escapeshell, videoId-Whitelist
- ✅ Sprint 3 — Status N+1-Query
- ✅ Sprint 4 — Anthropic Retry/Backoff, Daily-Cap, autoload=no
- ✅ Sprint 5 — Postmeta-Index, AS-Retention 7 Tage, DB-Migration
- ✅ Sprint 6 — Cookie-Setting, DST-Cron, kses-Filter, Featured-Image-Proxy
