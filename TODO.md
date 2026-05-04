# Newss — Backlog

Status: Sprint 1–6 aus dem QA-Review (2026-05-03) sind durch.
Sprint A–I aus dem 2nd-Pass QA-Review (2026-05-04, 4-Agent-Parallel-Audit) sind durch.
Hier die Nice-to-haves die wir bewusst zurückgestellt haben.

## 🟢 Mid-Priority (UX-Boosts, nächster Sprint-Kandidat)

- [ ] **Test-Single-Video-Button** im Admin — Video-ID rein, sofort Worker triggern (synchron mit Output-Buffer fürs Debugging)
- [ ] **Per-Channel-Statistiken persistent** — Custom-Tabelle `wp_newss_channel_stats(channel_id, day, polled, found, posted, skipped, failed)` plus Sparkline-Chart pro Kanal
- [ ] **Settings-Reset-Buttons** pro Section (System-Prompt, User-Template, Kategorien, Topics)
- [ ] **Settings-Export/Import** als JSON
- [ ] **Webhook bei completed Posts** — Setting "Webhook-URL" + `do_action('newss_post_published', $postId, $payload, $rewrite)` + simpler `wp_remote_post`
- [ ] **Action-Scheduler-Tabellen-Größe** im Status-Tab anzeigen
- [ ] **Anthropic Prompt-Caching messen** — `cache_read_input_tokens` aus Response auslesen, in `newss_anthropic_stats` aggregieren, im Status anzeigen ("X% cache hit rate, gespart Y tokens")
- [ ] **RSS-Polling parallel** via `Requests::request_multiple()` — bei 14 Channels × 30 s seriell = 7 min worst case → ~30 s parallel
- [ ] **Lookup-Tabelle `wp_newss_videos`** — schnellerer Cache als Postmeta (PK-Lookup statt Index-Scan); ersetzt sowohl Worker::videoAlreadyHasPost als auch Pending-Transient
- [ ] **Whisper-Pfad asynchron splitten** — aktuell blockt yt-dlp+Whisper den Worker minutenlang bei langen Videos; in Sub-Steps splitten
- [ ] **Adapter-Pattern für Transcript-Provider** — `TranscriptProvider`-Interface (Supadata, YtDlp, Whisper als Klassen) statt monolithischer `Transcript`-Klasse → leichter neue Provider hinzuzufügen
- [ ] **Adapter-Pattern für KI-Provider** — falls wir später mal OpenAI/Gemini-Fallback brauchen
- [ ] **i18n / Übersetzbarkeit** — `__()` / `_e()` / Text-Domain durchziehen (Pflicht für WP.org-Submit)

## 💡 Idea-Stash (interessant aber nicht dringend)

- [ ] **Fact-Check-Layer** — zweiter Haiku-Call zur Plausibilitätsprüfung; bei niedriger Konfidenz → Draft statt Publish
- [ ] **A/B-Test verschiedener System-Prompts** — Variants-Setting, zufällig zugewiesen, Tracking via Rank-Math/GSC-API
- [ ] **Schwarzliste für einzelne Video-IDs** — Setting `newss_video_blacklist` + UI-Button "Diese Video-ID nie verarbeiten" auf Status-Page
- [ ] **Per-Channel-Custom-Prompt-Override** — Channel-Entry kriegt optionales `prompt_override`-Feld
- [ ] **Multi-Site-Variante** — Settings pro Subsite, gemeinsamer API-Key auf Network-Level
- [ ] **Fallback auf `youtube-transcript-api` (Python)** — vierter Provider in Adapter-Kette
- [ ] **Slug-Kollisions-Sanity** — pre-check via `get_page_by_path` + Notification statt automatisches `-2`-Suffix
- [ ] **Audit-Log mit User-ID** für Channel-Änderungen (analog zum bereits vorhandenen Updater-Audit-Log)
- [ ] **Status-Page Drill-Down** — Klick auf Health-Tile öffnet Detail-View mit letzten 10 Calls und Latency-Chart
- [ ] **Channel-Auto-Disable bei N Fehlern in Folge** — z. B. 5× 404 in Folge → Channel deaktivieren + Admin-Notification
- [ ] **Soft-Delete für Channels** — gelöschte Channel-IDs in History für Statistik-Kontinuität
- [ ] **Admin-Bar-Indicator** — kleines Newss-Icon in WP-Admin-Bar mit Live-Status-Badge (running / idle / error)
- [ ] **Selective Re-Enqueue** — in der Pipeline-Tabelle "failed"-Jobs einzeln per Klick neu starten (aktuell nur via AS-Native-UI)
- [ ] **Auto-Tagging via NER** — Personen/Orte aus dem Transkript als Tags; via Claude-Tool-Use oder einfacher Regex-Liste

## ❌ Bewusst verworfen

- ~~"Anthropic-Modell-IDs `claude-sonnet-4-6` etc. sind Phantasie"~~ — sind die korrekten Bezeichner der Claude-4.x-Familie (QA #1 hatte einen alten Wissensstand)
- ~~"`as_enqueue_async_action` Payload `[[$payload]]` fragil"~~ — semantisch korrekt; AS unpackt das innere Array als positional args, hookt mit `do_action(hook, $payload)`

## Erledigt aus dem 2026-05-03 Review (1st-Pass)

- ✅ Sprint 1 — Race-Condition-Lock + Pending-Transient
- ✅ Sprint 2 — Sanitization, Tag-Fix, escapeshell, videoId-Whitelist
- ✅ Sprint 3 — Status N+1-Query
- ✅ Sprint 4 — Anthropic Retry/Backoff, Daily-Cap, autoload=no
- ✅ Sprint 5 — Postmeta-Index, AS-Retention 7 Tage, DB-Migration
- ✅ Sprint 6 — Cookie-Setting, DST-Cron, kses-Filter, Featured-Image-Proxy

## Erledigt aus dem 2026-05-04 Review (2nd-Pass, 4-Agent-Parallel-Audit)

- ✅ Sprint A — Live-Status-Polling (AJAX, kein Reload-Loop) + Lock-TTL-Cleanup + atomarer Cost-Cap (MySQL GET_LOCK) + XSS-Fix Channel-Delete + Image-Proxy-Whitelist (kein ytimg) + API-Key-Logging-Redaction
- ✅ Sprint B — Status::lookupLogsByActionIds Batch-Query (N+1 weg) + statusCounts() 60s-Transient-Cache
- ✅ Sprint C — YT-Quota-Detection (transient bis Pacific-Midnight) + Provider-Health-Telemetrie (Transcript::recordHealth) + Cron-Watchdog
- ✅ Sprint D — Whisper-Daily-Cap (Counter erst nach Erfolg) + uninstall.php-Wildcard-DELETE + wp_unslash-Hygiene + wp_timezone()
- ✅ Sprint E — Pending-Transient TTL=7d (=AS-Retention) + Supadata-Throw-statt-Hochwerfen-Fallback auf yt-dlp + Updater-Audit-Log (user/IP)
- ✅ Sprint F UX — Status-Page Onboarding-Checkliste + Health-Tiles + KPI-Kacheln (24h-Stats)
- ✅ Sprint G UX — Test-Buttons für Anthropic, YouTube, Supadata (zusätzlich zu bestehendem Whisper-Test)
- ✅ Sprint H UX — Pipeline-Status-Filter + Channels-Bulk-Aktionen + Empty-State-Boxen + relative Zeit (human_time_diff)
- ✅ Sprint I UX — Submenu-Reorder (logische Pipeline) + Channel-Test-Button per Zeile + Hilfe-Page (Quick-Start, Errors, Doku-Links)
