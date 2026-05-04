<?php

declare(strict_types=1);

namespace Newss;

final class Status
{
    private const PER_PAGE = 50;

    public static function renderChannelPoll(): void
    {
        $last         = get_option('newss_last_poll', null);
        $pollProgress = get_option('newss_poll_progress', null);
        $hasProgress  = is_array($pollProgress);
        $hasLast      = is_array($last) && !empty($last['channels']);
        $ajaxUrl      = admin_url('admin-ajax.php?action=newss_poll_progress');
        ?>
        <h2>Letzte Channel-Polls</h2>

        <div id="newss-poll-progress-box" data-state="<?php echo $hasProgress ? 'running' : 'idle'; ?>" style="margin:8px 0 16px 0;padding:14px 18px;border:1px solid #dcdcde;border-left:4px solid #999;background:#f6f7f7;max-width:880px">
            <h3 style="margin:0 0 8px 0;font-size:14px" id="newss-progress-heading">○ Bereit — kein aktiver Poll</h3>
            <p style="margin:0 0 6px 0"><strong id="newss-progress-text">Klick „Jetzt manuell pollen" oben — Status erscheint hier live.</strong></p>
            <div id="newss-progress-bar-wrap" style="background:#dcdcde;height:10px;border-radius:4px;overflow:hidden;display:none">
                <div id="newss-progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.3s ease"></div>
            </div>
            <p id="newss-progress-hint" style="margin:8px 0 0 0;font-size:11px;color:#999">
                Live-Status pollt alle 3 Sekunden, sobald ein Lauf gestartet wird.
            </p>
            <ul id="newss-progress-channels" style="margin:10px 0 0 0;padding:0;list-style:none;font-size:12px;max-height:180px;overflow:auto"></ul>
        </div>

        <?php if ($hasLast): ?>
            <p class="description" style="max-width:880px;margin-bottom:8px">
                Stand: <strong><?php echo esc_html(wp_date('Y-m-d H:i', (int) $last['time'])); ?></strong>
                (<?php echo (int) ($last['stats']['new'] ?? 0); ?> neue Videos enqueued, <?php echo (int) ($last['stats']['errors'] ?? 0); ?> Fehler)
            </p>
            <table class="widefat striped" style="max-width:880px">
                <thead>
                    <tr>
                        <th style="width:30%">Kanal</th>
                        <th>Channel-ID</th>
                        <th style="width:80px">Status</th>
                        <th style="width:80px">Neue Videos</th>
                        <th>Fehler</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($last['channels'] as $ch): ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) ($ch['name'] ?? '')); ?></strong></td>
                        <td><code style="font-size:11px"><?php echo esc_html((string) ($ch['id'] ?? '')); ?></code></td>
                        <td><?php echo !empty($ch['ok'])
                            ? '<span style="color:#0a7">✓ OK</span>'
                            : '<span style="color:#c00">✗ Fehler</span>'; ?></td>
                        <td><?php echo (int) ($ch['count'] ?? 0); ?></td>
                        <td style="font-size:11px;color:#c00"><?php echo esc_html((string) ($ch['error'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif (!$hasProgress): ?>
            <div style="margin:8px 0 16px 0;padding:14px 18px;border:1px solid #dcdcde;background:#f6f7f7;max-width:880px">
                <p style="margin:0">Noch kein Poll-Lauf abgeschlossen. Klick „Jetzt manuell pollen" oben — der Live-Status erscheint dann hier.</p>
            </div>
        <?php endif; ?>

        <script>
        (function(){
            var box = document.getElementById('newss-poll-progress-box');
            if (!box) return;
            var barWrap = document.getElementById('newss-progress-bar-wrap');
            var bar     = document.getElementById('newss-progress-bar');
            var txt     = document.getElementById('newss-progress-text');
            var hint    = document.getElementById('newss-progress-hint');
            var heading = document.getElementById('newss-progress-heading');
            var list    = document.getElementById('newss-progress-channels');
            var ajaxUrl = <?php echo wp_json_encode($ajaxUrl); ?>;
            var initiallyRunning = <?php echo $hasProgress ? 'true' : 'false'; ?>;
            var wasRunning = initiallyRunning;
            var wasQueued = false;
            var stopped = false;
            var pageLoadedAt = Date.now();
            var IDLE_LIMIT_MS = 10 * 60 * 1000;
            var timer = null;

            function stop() {
                stopped = true;
                if (timer) { clearInterval(timer); timer = null; }
            }

            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, function(c){
                    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
                });
            }

            function renderChannelList(channels) {
                if (!list) return;
                list.innerHTML = '';
                channels.forEach(function(c){
                    var li = document.createElement('li');
                    li.style.cssText = 'padding:3px 0;border-bottom:1px dotted #dcdcde';
                    var icon = c.ok ? '<span style="color:#0a7">✓</span>' :
                               (c.error ? '<span style="color:#c00">✗</span>' : '<span style="color:#888">·</span>');
                    var info = c.error
                        ? '<span style="color:#c00">' + escapeHtml(c.error) + '</span>'
                        : (c.count + ' neue Videos');
                    li.innerHTML = icon + ' <strong>' + escapeHtml(c.name) + '</strong> — ' + info;
                    list.appendChild(li);
                });
            }

            function showIdle() {
                box.dataset.state = 'idle';
                box.style.background = '#f6f7f7';
                box.style.borderLeftColor = '#999';
                heading.textContent = '○ Bereit — kein aktiver Poll';
                txt.textContent = 'Klick „Jetzt manuell pollen" oben — Status erscheint hier live.';
                barWrap.style.display = 'none';
                hint.innerHTML = 'Live-Status pollt alle 3 Sekunden, sobald ein Lauf gestartet wird.';
                if (list) list.innerHTML = '';
            }

            function showQueued() {
                box.dataset.state = 'queued';
                box.style.background = '#fff8e1';
                box.style.borderLeftColor = '#ffb900';
                heading.textContent = '⏳ In Queue — Worker startet …';
                txt.textContent = 'Action-Scheduler hat den Job aufgenommen, Background-Worker startet beim nächsten Tick (meist binnen 60s).';
                barWrap.style.display = 'none';
                hint.innerHTML = 'Status aktualisiert sich automatisch sobald pollAll() loslegt.';
            }

            function showRunning(p) {
                box.dataset.state = 'running';
                box.style.background = '#f0f6fc';
                box.style.borderLeftColor = '#2271b1';
                heading.textContent = '⏳ Polling läuft …';
                barWrap.style.display = 'block';
                bar.style.background = '#2271b1';
                var pct = p.total > 0 ? Math.round(p.done / p.total * 100) : 0;
                bar.style.width = pct + '%';
                txt.textContent = 'Kanal ' + p.done + ' / ' + p.total +
                    (p.current ? ' — gerade: ' + p.current : '');
                hint.innerHTML = 'Aktualisiert sich alle 3 Sekunden — kein Page-Reload nötig.';
                renderChannelList(p.channels || []);
            }

            function showFinished() {
                box.dataset.state = 'finished';
                box.style.background = '#eaf7e6';
                box.style.borderLeftColor = '#0a7';
                heading.textContent = '✓ Polling abgeschlossen';
                barWrap.style.display = 'block';
                bar.style.width = '100%';
                bar.style.background = '#0a7';
                txt.textContent = 'Alle Kanäle verarbeitet.';
                hint.innerHTML = '<a href="' + window.location.href + '">Seite jetzt neu laden</a> um die aktualisierte Tabelle zu sehen.';
            }

            function tick() {
                if (stopped) return;
                fetch(ajaxUrl, {credentials: 'same-origin', cache: 'no-store'})
                    .then(function(r){ return r.ok ? r.json() : null; })
                    .then(function(d){
                        if (!d) return;
                        if (d.in_progress && d.progress) {
                            wasRunning = true;
                            wasQueued = false;
                            showRunning(d.progress);
                        } else if (wasRunning) {
                            showFinished();
                            stop();
                        } else if (d.queued) {
                            wasQueued = true;
                            showQueued();
                        } else if (wasQueued) {
                            // Queued-Marker weg ohne dass running -> evtl. Worker-Mutex blockt.
                            // Box zurueck zu Idle, weiter pollen falls neuer Klick.
                            showIdle();
                        } else {
                            showIdle();
                            if (Date.now() - pageLoadedAt > IDLE_LIMIT_MS) {
                                stop();
                            }
                        }
                    })
                    .catch(function(){ /* network blip — beim nächsten Tick erneut */ });
            }

            tick();
            timer = setInterval(tick, 3000);
            // Sicherheits-Stop nach 30 Min auch im running-Fall
            setTimeout(stop, 30 * 60 * 1000);
        })();
        </script>

        <hr style="margin:32px 0">
        <?php
    }

    /**
     * Live-Daten fuer aktuell laufende Pipeline-Jobs (Worker-Stage).
     *
     * @return array{running:array<int,array<string,mixed>>, pending:int, failed:int, ts:int}
     */
    public static function pipelineLiveData(): array
    {
        $out = ['running' => [], 'pending' => 0, 'failed' => 0, 'ts' => time()];
        if (!class_exists('\\ActionScheduler')) {
            return $out;
        }
        try {
            $store = \ActionScheduler::store();
            $runningIds = (array) $store->query_actions([
                'hook'     => Worker::HOOK_PROCESS,
                'group'    => 'newss',
                'status'   => 'in-progress',
                'per_page' => 20,
                'order'    => 'ASC',
                'orderby'  => 'date',
            ]);
            $out['pending'] = (int) $store->query_actions([
                'hook'   => Worker::HOOK_PROCESS,
                'group'  => 'newss',
                'status' => 'pending',
            ], 'count');
            // Failed nur letzte 24h zaehlen, sonst sammelt sich das ueber Wochen
            $since24h = new \DateTime('24 hours ago', new \DateTimeZone('UTC'));
            $out['failed'] = (int) $store->query_actions([
                'hook'         => Worker::HOOK_PROCESS,
                'group'        => 'newss',
                'status'       => 'failed',
                'date'         => $since24h,
                'date_compare' => '>=',
            ], 'count');

            if ($runningIds === []) {
                return $out;
            }

            $logsMap = self::lookupLogsByActionIds(array_map('intval', $runningIds));
            $createdMap = self::lookupCreatedDates(array_map('intval', $runningIds));

            foreach ($runningIds as $aid) {
                $aid = (int) $aid;
                try {
                    $action = $store->fetch_action($aid);
                } catch (\Throwable) {
                    continue;
                }
                if (!$action) continue;
                $args = $action->get_args();
                $payload = $args[0] ?? [];
                $logs = $logsMap[$aid] ?? [];
                $lastLog = $logs ? end($logs) : null;
                $startedTs = 0;
                if (isset($createdMap[$aid])) {
                    try {
                        $startedTs = (new \DateTime($createdMap[$aid], new \DateTimeZone('UTC')))->getTimestamp();
                    } catch (\Throwable) {}
                }
                $out['running'][] = [
                    'action_id'   => $aid,
                    'video_id'    => (string) ($payload['video_id'] ?? ''),
                    'video_title' => self::shorten((string) ($payload['video_title'] ?? ''), 80),
                    'channel'     => (string) ($payload['channel_name'] ?? ''),
                    'started_ts'  => $startedTs,
                    'started_ago' => $startedTs > 0 ? self::timeAgoDe($startedTs) : '—',
                    'last_log'    => $lastLog ? self::shorten((string) ($lastLog['message'] ?? ''), 140) : '',
                ];
            }
        } catch (\Throwable $e) {
            error_log('[newss] pipelineLiveData error: ' . $e->getMessage());
        }
        return $out;
    }

    public static function renderPipelineLive(): void
    {
        $ajaxUrl = admin_url('admin-ajax.php?action=newss_pipeline_live');
        ?>
        <div id="newss-pipeline-live" style="margin:8px 0 20px 0;padding:14px 18px;border:1px solid #dcdcde;border-left:4px solid #999;background:#f6f7f7;max-width:1280px">
            <h3 style="margin:0 0 8px 0;font-size:14px" id="newss-pl-heading">○ Aktuell keine laufenden Jobs</h3>
            <p style="margin:0 0 8px 0;font-size:12px;color:#666" id="newss-pl-counts">—</p>
            <ul id="newss-pl-list" style="margin:0;padding:0;list-style:none;font-size:12px;max-height:280px;overflow:auto"></ul>
            <p style="margin:8px 0 0 0;font-size:11px;color:#999">Aktualisiert sich alle 4 Sekunden — kein Page-Reload nötig.</p>
        </div>
        <script>
        (function(){
            var box = document.getElementById('newss-pipeline-live');
            if (!box) return;
            var heading = document.getElementById('newss-pl-heading');
            var counts  = document.getElementById('newss-pl-counts');
            var list    = document.getElementById('newss-pl-list');
            var ajaxUrl = <?php echo wp_json_encode($ajaxUrl); ?>;
            var stopped = false;
            var idleTicks = 0;
            var IDLE_MAX_TICKS = 75; // 75 * 4s = 5 min ohne running -> stop

            function escapeHtml(s){
                return String(s).replace(/[&<>"']/g, function(c){
                    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
                });
            }

            function tick(){
                if (stopped) return;
                fetch(ajaxUrl, {credentials:'same-origin', cache:'no-store'})
                    .then(function(r){ return r.ok ? r.json() : null; })
                    .then(function(d){
                        if (!d) return;
                        var running = d.running || [];
                        var pending = d.pending || 0;
                        var failed  = d.failed || 0;

                        if (running.length > 0) {
                            idleTicks = 0;
                            box.style.background = '#f0f6fc';
                            box.style.borderLeftColor = '#2271b1';
                            heading.textContent = '⏳ ' + running.length + ' Job' + (running.length === 1 ? '' : 's') + ' laufen gerade';
                        } else {
                            idleTicks++;
                            box.style.background = '#f6f7f7';
                            box.style.borderLeftColor = '#999';
                            heading.textContent = '○ Aktuell keine laufenden Jobs';
                        }
                        counts.textContent = pending + ' Pending · ' + failed + ' Failed (24h)';

                        list.innerHTML = '';
                        running.forEach(function(j){
                            var li = document.createElement('li');
                            li.style.cssText = 'padding:8px 10px;margin:6px 0;background:#fff;border:1px solid #dcdcde;border-radius:3px';
                            li.innerHTML =
                                '<div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px">' +
                                  '<strong>' + escapeHtml(j.video_title || j.video_id) + '</strong>' +
                                  '<span style="font-size:11px;color:#666;white-space:nowrap">' + escapeHtml(j.started_ago) + '</span>' +
                                '</div>' +
                                '<div style="font-size:11px;color:#666;margin-top:2px">' +
                                  escapeHtml(j.channel) +
                                  ' · <a href="https://www.youtube.com/watch?v=' + encodeURIComponent(j.video_id) + '" target="_blank" rel="noopener">' + escapeHtml(j.video_id) + '</a>' +
                                '</div>' +
                                (j.last_log
                                  ? '<div style="font-size:11px;color:#0040b0;margin-top:4px;font-family:monospace">' + escapeHtml(j.last_log) + '</div>'
                                  : '');
                            list.appendChild(li);
                        });

                        if (idleTicks > IDLE_MAX_TICKS) {
                            stopped = true;
                        }
                    })
                    .catch(function(){ /* network blip */ });
            }
            tick();
            var timer = setInterval(tick, 4000);
            // Hard-Stop nach 30 Min damit der Tab nicht ewig pollt
            setTimeout(function(){ stopped = true; clearInterval(timer); }, 30 * 60 * 1000);
        })();
        </script>
        <?php
    }

    public static function renderPipeline(): void
    {
        if (!function_exists('as_get_scheduled_actions') || !class_exists('\\ActionScheduler')) {
            return;
        }

        $statusCounts   = self::statusCounts();
        $channelFilter  = isset($_GET['newss_channel'])    ? sanitize_text_field(wp_unslash((string) $_GET['newss_channel']))    : '';
        $effectiveFilter= isset($_GET['newss_effective'])  ? sanitize_key(wp_unslash((string) $_GET['newss_effective']))         : '';

        $page    = max(1, absint(wp_unslash($_GET['newss_page'] ?? 1)));
        $perPage = self::PER_PAGE;

        $since = new \DateTime('24 hours ago', new \DateTimeZone('UTC'));

        $hasFilter = ($channelFilter !== '') || ($effectiveFilter !== '');
        if ($hasFilter) {
            $allRows = self::fetchSince($since, 500, 0);
            if ($channelFilter !== '') {
                $allRows = array_values(array_filter($allRows, static fn(array $r): bool => ($r['channel'] ?? '') === $channelFilter));
            }
            if ($effectiveFilter !== '') {
                $allRows = array_values(array_filter($allRows, static fn(array $r): bool => ($r['effective'] ?? '') === $effectiveFilter));
            }
            $total = count($allRows);
            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $rows = array_slice($allRows, ($page - 1) * $perPage, $perPage);
        } else {
            $total = self::countSince($since);
            $totalPages = max(1, (int) ceil($total / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $rows = self::fetchSince($since, $perPage, ($page - 1) * $perPage);
        }

        $channels = get_option('newss_channels', []);
        $filterUrlBase = admin_url('admin.php?page=newss-pipeline');

        $statsLabels = [
            'pending'     => 'Pending',
            'in-progress' => 'Läuft',
            'complete'    => 'Complete (gesamt)',
            'failed'      => 'Failed',
        ];
        ?>
        <h2>Job-Pipeline (letzte 24h)</h2>
        <?php self::renderPipelineLive(); ?>
        <p class="description" style="max-width:1280px;margin-bottom:8px">
            <?php foreach ($statusCounts as $key => $count): ?>
                <span style="margin-right:14px"><strong><?php echo esc_html($statsLabels[$key] ?? $key); ?>:</strong> <?php echo (int) $count; ?></span>
            <?php endforeach; ?>
            <span style="margin-left:auto;color:#888;font-size:11px">— „complete" enthält <em>posted</em>, <em>skipped</em>, <em>orphan</em></span>
        </p>

        <form method="get" action="" style="margin:8px 0 12px 0;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <input type="hidden" name="page" value="newss-pipeline">
            <label for="newss_channel" style="font-weight:600">Kanal:</label>
            <select name="newss_channel" id="newss_channel" onchange="this.form.submit()">
                <option value="">— Alle —</option>
                <?php
                $names = [];
                foreach ($channels as $ch) {
                    $name = trim((string) ($ch['name'] ?? ''));
                    if ($name !== '') {
                        $names[$name] = true;
                    }
                }
                ksort($names);
                foreach (array_keys($names) as $name): ?>
                    <option value="<?php echo esc_attr($name); ?>" <?php selected($channelFilter, $name); ?>>
                        <?php echo esc_html($name); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="newss_effective" style="font-weight:600;margin-left:12px">Status:</label>
            <select name="newss_effective" id="newss_effective" onchange="this.form.submit()">
                <option value="">— Alle —</option>
                <?php foreach (['pending'=>'Pending','in-progress'=>'Läuft','posted'=>'Posted','skipped'=>'Skipped','orphan'=>'Orphan','failed'=>'Failed'] as $val => $lbl): ?>
                    <option value="<?php echo esc_attr($val); ?>" <?php selected($effectiveFilter, $val); ?>><?php echo esc_html($lbl); ?></option>
                <?php endforeach; ?>
            </select>

            <?php if ($hasFilter): ?>
                <a href="<?php echo esc_url($filterUrlBase); ?>" class="button button-small">Filter zurücksetzen</a>
            <?php endif; ?>
        </form>
        <?php if (!$rows): ?>
            <?php
            $channels = (array) get_option('newss_channels', []);
            $hasChannels = false;
            foreach ($channels as $c) { if (!empty($c['enabled'])) { $hasChannels = true; break; } }
            ?>
            <div style="margin:20px 0;padding:14px 18px;border:1px solid #dcdcde;background:#f6f7f7;max-width:880px">
                <?php if (!$hasChannels): ?>
                    <p style="margin:0">Noch keine aktiven Kanäle — <a href="<?php echo esc_url(admin_url('admin.php?page=newss-channels')); ?>">jetzt einen Kanal hinzufügen</a>.</p>
                <?php elseif ($hasFilter): ?>
                    <p style="margin:0">Kein Job entspricht dem Filter. <a href="<?php echo esc_url($filterUrlBase); ?>">Filter zurücksetzen</a>.</p>
                <?php else: ?>
                    <p style="margin:0">Keine Jobs in der letzten 24 Stunden — der nächste Cron-Lauf legt neue Jobs an.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <table class="widefat striped" style="max-width:1280px">
                <thead>
                    <tr>
                        <th style="width:90px">Status</th>
                        <th style="width:120px">Erstellt</th>
                        <th style="width:120px">Letztes Update</th>
                        <th>Video</th>
                        <th style="width:140px">Channel</th>
                        <th style="width:80px">Quelle</th>
                        <th style="width:160px">Artikel / Grund</th>
                        <th>Letzter Log-Eintrag</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo self::statusBadge($r['effective']); ?></td>
                        <td style="font-size:11px"><?php echo esc_html($r['created'] ?: '—'); echo !empty($r['created_ts']) ? '<br><span style="color:#999">' . esc_html(self::timeAgoDe((int) $r['created_ts'])) . '</span>' : ''; ?></td>
                        <td style="font-size:11px"><?php echo esc_html($r['updated'] ?: '—'); echo !empty($r['updated_ts']) ? '<br><span style="color:#999">' . esc_html(self::timeAgoDe((int) $r['updated_ts'])) . '</span>' : ''; ?></td>
                        <td>
                            <strong><?php echo esc_html($r['title']); ?></strong><br>
                            <a href="https://www.youtube.com/watch?v=<?php echo esc_attr($r['video_id']); ?>" target="_blank" rel="noopener" style="font-size:11px"><?php echo esc_html($r['video_id']); ?></a>
                        </td>
                        <td><?php echo esc_html($r['channel']); ?></td>
                        <td style="font-size:11px"><?php echo $r['provider'] !== '' ? self::providerBadge($r['provider']) : '—'; ?></td>
                        <td style="font-size:11px">
                            <?php if (!empty($r['post_url'])): ?>
                                <a href="<?php echo esc_url($r['post_url']); ?>" target="_blank" rel="noopener">→ Ansehen</a>
                                <?php if (!empty($r['edit_url'])): ?>
                                    <br><a href="<?php echo esc_url($r['edit_url']); ?>">→ Bearbeiten</a>
                                <?php endif; ?>
                            <?php elseif ($r['effective'] === 'skipped'): ?>
                                <span style="color:#7a5b00"><?php echo esc_html($r['reason'] ?: '—'); ?></span>
                            <?php elseif ($r['effective'] === 'failed'): ?>
                                <span style="color:#c00">Exception</span>
                            <?php elseif ($r['effective'] === 'orphan'): ?>
                                <span style="color:#c66">orphan: complete ohne Post & ohne Skip-Log</span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td style="font-size:11px;<?php echo in_array($r['effective'], ['failed', 'orphan'], true) ? 'color:#c00' : 'color:#666'; ?>">
                            <?php echo esc_html($r['last_log']); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php echo self::renderPagination($page, $totalPages, $total, $channelFilter, $effectiveFilter); ?>
        <?php endif; ?>
        <hr style="margin:32px 0">
        <?php
    }

    private static function renderPagination(int $page, int $totalPages, int $total, string $channelFilter = '', string $effectiveFilter = ''): string
    {
        $base = admin_url('admin.php?page=newss-pipeline');
        if ($channelFilter !== '') {
            $base = add_query_arg('newss_channel', $channelFilter, $base);
        }
        if ($effectiveFilter !== '') {
            $base = add_query_arg('newss_effective', $effectiveFilter, $base);
        }
        $isFiltered = $channelFilter !== '' || $effectiveFilter !== '';
        if ($totalPages <= 1) {
            return '<p class="description" style="margin-top:8px">' . (int) $total . ' Jobs in letzter 24h' . ($isFiltered ? ' (gefiltert)' : '') . '.</p>';
        }
        $out = '<p style="margin-top:12px;display:flex;align-items:center;gap:8px">';
        if ($page > 1) {
            $out .= sprintf('<a class="button" href="%s">‹ Zurück</a>', esc_url(add_query_arg('newss_page', $page - 1, $base)));
        }
        $out .= sprintf('<span class="description">Seite %d / %d &nbsp;·&nbsp; %d Jobs gesamt%s (%d pro Seite)</span>', $page, $totalPages, $total, $isFiltered ? ' (gefiltert)' : '', self::PER_PAGE);
        if ($page < $totalPages) {
            $out .= sprintf('<a class="button" href="%s">Weiter ›</a>', esc_url(add_query_arg('newss_page', $page + 1, $base)));
        }
        $out .= '</p>';
        return $out;
    }

    public const STATUS_COUNTS_CACHE_KEY = 'newss_status_counts';

    /**
     * Liefert "vor X Min./Std./Tagen" auf Deutsch.
     * Ersetzt WP's human_time_diff das je nach Locale englisch ausgibt.
     */
    public static function timeAgoDe(int $ts): string
    {
        if ($ts <= 0) {
            return '—';
        }
        $diff = abs(time() - $ts);
        if ($diff < 60) {
            return $diff . ' Sek. her';
        }
        if ($diff < 3600) {
            return (int) round($diff / 60) . ' Min. her';
        }
        if ($diff < 86400) {
            return (int) round($diff / 3600) . ' Std. her';
        }
        $days = (int) round($diff / 86400);
        return $days . ($days === 1 ? ' Tag her' : ' Tage her');
    }

    /**
     * Onboarding-Checkliste. Zeigt nur an wenn Setup unvollständig.
     */
    public static function renderOnboarding(): void
    {
        $missing = self::onboardingChecklist();
        if ($missing === []) {
            return;
        }
        ?>
        <div style="margin:16px 0;padding:14px 18px;border:1px solid #ffb900;border-left:4px solid #ffb900;background:#fff8e1;max-width:880px">
            <h2 style="margin:0 0 8px 0">⚙ Setup nicht abgeschlossen</h2>
            <p style="margin:0 0 8px 0">Folgende Schritte fehlen noch bis das Plugin Artikel produziert:</p>
            <ol style="margin:0 0 0 18px">
                <?php foreach ($missing as $item): ?>
                    <li style="margin-bottom:4px">
                        <strong><?php echo esc_html($item['label']); ?></strong>
                        — <a href="<?php echo esc_url(admin_url('admin.php?page=' . $item['page'])); ?>"><?php echo esc_html($item['cta']); ?></a>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
        <?php
    }

    /**
     * Kompakte Health-Tiles oben auf der Status-Page.
     */
    public static function renderHealthTiles(): void
    {
        $checks = self::healthChecks();
        ?>
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin:16px 0;max-width:880px">
            <?php foreach ($checks as $c):
                $bg = $c['ok'] ? '#eaf7e6' : ($c['warn'] ? '#fff8e1' : '#ffe6e6');
                $color = $c['ok'] ? '#0a5a00' : ($c['warn'] ? '#7a5b00' : '#7a0000');
                $icon = $c['ok'] ? '✓' : ($c['warn'] ? '⚠' : '✗');
                ?>
                <div style="flex:1 1 200px;min-width:180px;padding:12px;background:<?php echo esc_attr($bg); ?>;color:<?php echo esc_attr($color); ?>;border-radius:4px;font-size:13px">
                    <div style="font-size:16px;font-weight:600"><?php echo $icon; ?> <?php echo esc_html($c['label']); ?></div>
                    <div style="margin-top:4px;font-size:11px;color:#666"><?php echo esc_html($c['detail']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * KPI-Kacheln: schnelle Zahlen.
     */
    public static function renderKpiTiles(): void
    {
        $kpis = self::computeKpis();
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:16px 0;max-width:1080px">
            <?php foreach ($kpis as $k): ?>
                <div style="padding:12px 14px;background:#fff;border:1px solid #dcdcde;border-radius:4px">
                    <div style="font-size:11px;color:#666;text-transform:uppercase;letter-spacing:0.5px"><?php echo esc_html($k['label']); ?></div>
                    <div style="font-size:24px;font-weight:600;margin-top:4px;color:<?php echo esc_attr($k['color'] ?? '#1d2327'); ?>"><?php echo esc_html((string) $k['value']); ?></div>
                    <?php if (!empty($k['sub'])): ?>
                        <div style="font-size:11px;color:#999;margin-top:2px"><?php echo esc_html($k['sub']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function onboardingChecklist(): array
    {
        $missing = [];
        if (trim((string) get_option('newss_anthropic_api_key', '')) === '') {
            $missing[] = ['label' => 'Anthropic-API-Key fehlt', 'cta' => 'In Claude-Settings setzen', 'page' => 'newss-claude'];
        }
        $hasSupa    = trim((string) get_option('newss_supadata_api_key', '')) !== '';
        $hasWhisper = (int) get_option('newss_whisper_enabled', 0) === 1
                   && trim((string) get_option('newss_whisper_api_key', '')) !== '';
        $hasYtdlp   = self::isYtDlpAvailable();
        if (!$hasSupa && !$hasWhisper && !$hasYtdlp) {
            $missing[] = ['label' => 'Kein Transkript-Provider konfiguriert', 'cta' => 'Supadata oder Whisper aktivieren', 'page' => 'newss-transcript'];
        }
        $method = (string) get_option('newss_youtube_method', 'rss');
        if ($method === 'api' && trim((string) get_option('newss_youtube_api_key', '')) === '') {
            $missing[] = ['label' => 'YouTube Data API-Key fehlt (Methode = API gewählt)', 'cta' => 'API-Key setzen', 'page' => 'newss-youtube'];
        }
        $channels = (array) get_option('newss_channels', []);
        $enabledCount = 0;
        foreach ($channels as $c) {
            if (!empty($c['enabled'])) $enabledCount++;
        }
        if ($enabledCount === 0) {
            $missing[] = ['label' => 'Kein aktiver Kanal', 'cta' => 'Kanal hinzufügen', 'page' => 'newss-channels'];
        }
        return $missing;
    }

    private static function healthChecks(): array
    {
        $hasAnthropic = trim((string) get_option('newss_anthropic_api_key', '')) !== '';
        $channels = (array) get_option('newss_channels', []);
        $enabledChannels = array_filter($channels, static fn($c) => !empty($c['enabled']));
        $hasChannel = $enabledChannels !== [];
        $hasSupa    = trim((string) get_option('newss_supadata_api_key', '')) !== '';
        $hasWhisper = (int) get_option('newss_whisper_enabled', 0) === 1
                   && trim((string) get_option('newss_whisper_api_key', '')) !== '';
        $hasYtdlp   = self::isYtDlpAvailable();
        $hasProvider = $hasSupa || $hasWhisper || $hasYtdlp;

        $lastCron = (int) get_option('newss_last_cron_run', 0);
        $cronAge  = $lastCron > 0 ? time() - $lastCron : -1;

        return [
            [
                'label'  => 'Claude konfiguriert',
                'detail' => $hasAnthropic ? 'API-Key gesetzt' : 'kein API-Key',
                'ok'     => $hasAnthropic,
                'warn'   => false,
            ],
            [
                'label'  => 'Transkript-Provider',
                'detail' => trim(implode(', ', array_filter([
                    $hasSupa ? 'Supadata' : '',
                    $hasYtdlp ? 'yt-dlp' : '',
                    $hasWhisper ? 'Whisper' : '',
                ]))) ?: 'kein Provider',
                'ok'     => $hasProvider,
                'warn'   => false,
            ],
            [
                'label'  => 'Aktive Kanäle',
                'detail' => count($enabledChannels) . ' aktiviert',
                'ok'     => $hasChannel,
                'warn'   => false,
            ],
            [
                'label'  => 'Cron läuft',
                'detail' => $cronAge < 0
                    ? 'noch nie gelaufen'
                    : self::timeAgoDe($lastCron),
                'ok'     => $cronAge >= 0 && $cronAge < 12 * HOUR_IN_SECONDS,
                'warn'   => $cronAge >= 12 * HOUR_IN_SECONDS && $cronAge < 16 * HOUR_IN_SECONDS,
            ],
        ];
    }

    private static function computeKpis(): array
    {
        // Posts via Postmeta
        global $wpdb;
        $todayStart = (new \DateTimeImmutable('today', wp_timezone()))->getTimestamp();
        $weekAgo    = (new \DateTimeImmutable('-7 days', wp_timezone()))->getTimestamp();

        $postsToday = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE pm.meta_key = %s
               AND p.post_status = 'publish'
               AND p.post_date_gmt >= %s",
            '_newss_video_id',
            gmdate('Y-m-d H:i:s', $todayStart)
        ));
        $postsWeek = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE pm.meta_key = %s
               AND p.post_status = 'publish'
               AND p.post_date_gmt >= %s",
            '_newss_video_id',
            gmdate('Y-m-d H:i:s', $weekAgo)
        ));

        // AS-Counts (cached)
        $counts = self::statusCounts();

        // Anthropic-Cap heute
        $cap = (int) get_option('newss_anthropic_daily_cap', 0);
        $callsOpt = get_option('newss_anthropic_calls_today', null);
        $callsToday = (is_array($callsOpt) && ($callsOpt['date'] ?? '') === wp_date('Y-m-d')) ? (int) $callsOpt['count'] : 0;
        $callsLabel = $cap > 0 ? "{$callsToday} / {$cap}" : (string) $callsToday;
        $callsColor = ($cap > 0 && $callsToday >= $cap * 0.9) ? '#c00' : '#1d2327';

        return [
            ['label' => 'Posts heute',         'value' => $postsToday,                'color' => '#0a5a00'],
            ['label' => 'Posts 7 Tage',        'value' => $postsWeek],
            ['label' => 'Pending Jobs',        'value' => (int) ($counts['pending'] ?? 0)],
            ['label' => 'Failed Jobs',         'value' => (int) ($counts['failed'] ?? 0), 'color' => ((int) ($counts['failed'] ?? 0) > 0 ? '#c00' : '#1d2327')],
            ['label' => 'Claude-Calls heute',  'value' => $callsLabel, 'color' => $callsColor, 'sub' => $cap > 0 ? 'Cap aktiv' : 'kein Cap'],
        ];
    }

    private static function isYtDlpAvailable(): bool
    {
        $bin = (string) get_option('newss_ytdlp_path', 'yt-dlp');
        if (str_starts_with($bin, '/')) {
            return @is_executable($bin);
        }
        foreach (['/usr/local/bin', '/usr/bin', '/snap/bin'] as $dir) {
            if (@is_executable($dir . '/' . $bin)) return true;
        }
        return false;
    }

    private static function statusCounts(): array
    {
        $cached = get_transient(self::STATUS_COUNTS_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        $statuses = ['pending', 'in-progress', 'complete', 'failed'];
        $out = [];
        foreach ($statuses as $s) {
            $out[$s] = self::countActions($s);
        }
        set_transient(self::STATUS_COUNTS_CACHE_KEY, $out, 60);
        return $out;
    }

    private static function countActions(string $status): int
    {
        if (!class_exists('\\ActionScheduler')) {
            return 0;
        }
        try {
            return (int) \ActionScheduler::store()->query_actions([
                'hook'   => Worker::HOOK_PROCESS,
                'group'  => 'newss',
                'status' => $status,
            ], 'count');
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function countSince(\DateTime $since): int
    {
        try {
            return (int) \ActionScheduler::store()->query_actions([
                'hook'         => Worker::HOOK_PROCESS,
                'group'        => 'newss',
                'date'         => $since,
                'date_compare' => '>=',
            ], 'count');
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function fetchSince(\DateTime $since, int $perPage, int $offset): array
    {
        try {
            $ids = as_get_scheduled_actions([
                'hook'         => Worker::HOOK_PROCESS,
                'group'        => 'newss',
                'date'         => $since,
                'date_compare' => '>=',
                'per_page'     => $perPage,
                'offset'       => $offset,
                'order'        => 'DESC',
                'orderby'      => 'date',
            ], 'ids');
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($ids) || $ids === []) {
            return [];
        }
        return self::buildRows($ids);
    }

    private static function buildRows(array $ids): array
    {
        $store  = \ActionScheduler::store();

        $createdMap = self::lookupCreatedDates($ids);
        $logsMap    = self::lookupLogsByActionIds($ids);

        $rawRows = [];
        $videoIdsToLookup = [];
        foreach ($ids as $actionId) {
            try {
                $action = $store->fetch_action($actionId);
            } catch (\Throwable) {
                continue;
            }
            if (!$action) {
                continue;
            }

            $status   = (string) $store->get_status($actionId);
            $args     = $action->get_args();
            $payload  = $args[0] ?? [];
            $schedule = $action->get_schedule();
            $next     = $schedule && method_exists($schedule, 'get_date') ? $schedule->get_date() : null;
            $logs     = $logsMap[(int) $actionId] ?? [];

            $videoId = (string) ($payload['video_id'] ?? '');
            if ($status === 'complete' && $videoId !== '') {
                $videoIdsToLookup[] = $videoId;
            }

            $rawRows[] = [
                'action_id' => (int) $actionId,
                'status'    => $status,
                'payload'   => $payload,
                'next'      => $next,
                'logs'      => $logs,
                'video_id'  => $videoId,
            ];
        }

        $postMap = self::lookupPostsByVideoIds(array_values(array_unique($videoIdsToLookup)));

        $out = [];
        foreach ($rawRows as $r) {
            $status   = $r['status'];
            $payload  = $r['payload'];
            $next     = $r['next'];
            $logs     = $r['logs'];
            $videoId  = $r['video_id'];
            $lastLog  = $logs ? end($logs) : null;

            $updated = '';
            $updatedTs = 0;
            if ($lastLog && !empty($lastLog['date'])) {
                $updatedTs = $lastLog['date']->getTimestamp();
                $updated = wp_date('Y-m-d H:i', $updatedTs);
            }

            $created = '';
            $createdTs = 0;
            if (isset($createdMap[$r['action_id']])) {
                try {
                    $cd = new \DateTime($createdMap[$r['action_id']], new \DateTimeZone('UTC'));
                    $createdTs = $cd->getTimestamp();
                    $created = wp_date('Y-m-d H:i', $createdTs);
                } catch (\Throwable) {
                    // ignore
                }
            }

            $postUrl = '';
            $editUrl = '';
            if ($status === 'complete' && isset($postMap[$videoId])) {
                $postId  = (int) $postMap[$videoId];
                $postUrl = (string) get_permalink($postId);
                $editUrl = (string) get_edit_post_link($postId, '');
            }

            $effective = $status;
            $reason = '';
            $provider = '';
            foreach ($logs ?: [] as $log) {
                $msg = (string) ($log['message'] ?? '');
                if (preg_match('/\[newss\]\s+transcript via (\S+)/', $msg, $m)) {
                    $provider = $m[1];
                }
            }
            if ($status === 'complete') {
                if ($postUrl !== '') {
                    $effective = 'posted';
                } else {
                    foreach (array_reverse($logs ?: []) as $log) {
                        $msg = (string) ($log['message'] ?? '');
                        if (preg_match('/\[newss\]\s+SKIP:\s*(.+)/', $msg, $m)) {
                            $effective = 'skipped';
                            $reason = trim($m[1]);
                            break;
                        }
                    }
                    if ($effective === 'complete') {
                        $effective = 'orphan';
                    }
                }
            }

            $out[] = [
                'status'     => $status,
                'effective'  => $effective,
                'reason'     => $reason,
                'provider'   => $provider,
                'video_id'   => $videoId,
                'title'      => self::shorten((string) ($payload['video_title'] ?? ''), 80),
                'channel'    => (string) ($payload['channel_name'] ?? ''),
                'channel_id' => (string) ($payload['channel_id'] ?? ''),
                'created'    => $created,
                'created_ts' => $createdTs,
                'updated'    => $updated,
                'updated_ts' => $updatedTs,
                'last_log'   => $lastLog ? self::shorten((string) ($lastLog['message'] ?? ''), 200) : '',
                'post_url'   => $postUrl,
                'edit_url'   => $editUrl,
            ];
        }
        return $out;
    }

    /**
     * Batch-Logs-Lookup: 1 Query statt N (eine pro Action).
     *
     * @param int[] $actionIds
     * @return array<int,array<int,array{message:string,date:?\DateTime}>>
     */
    private static function lookupLogsByActionIds(array $actionIds): array
    {
        if ($actionIds === []) {
            return [];
        }
        global $wpdb;
        $ids = array_map('intval', $actionIds);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT action_id, log_date_gmt, message FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ($placeholders) ORDER BY action_id ASC, log_id ASC",
            $ids
        );
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $aid = (int) $row['action_id'];
            $dt = null;
            try {
                if (!empty($row['log_date_gmt'])) {
                    $dt = new \DateTime($row['log_date_gmt'], new \DateTimeZone('UTC'));
                }
            } catch (\Throwable) {
                // ignore
            }
            $map[$aid] ??= [];
            $map[$aid][] = [
                'message' => (string) $row['message'],
                'date'    => $dt,
            ];
        }
        return $map;
    }

    /**
     * @param int[] $actionIds
     * @return array<int,string> map action_id -> scheduled_date_gmt (UTC)
     */
    private static function lookupCreatedDates(array $actionIds): array
    {
        if ($actionIds === []) {
            return [];
        }
        global $wpdb;
        $ids = array_map('intval', $actionIds);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT action_id, scheduled_date_gmt FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id IN ($placeholders)",
            $ids
        );
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['action_id']] = (string) $row['scheduled_date_gmt'];
        }
        return $map;
    }

    /**
     * @param string[] $videoIds
     * @return array<string,int> map video_id -> post_id (only "real" posts, no trash/auto-draft)
     */
    private static function lookupPostsByVideoIds(array $videoIds): array
    {
        if ($videoIds === []) {
            return [];
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($videoIds), '%s'));
        $sql = $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND pm.meta_value IN ($placeholders)
               AND p.post_status IN ('publish','draft','private','pending','future')
               AND p.post_type = 'post'",
            array_merge(['_newss_video_id'], $videoIds)
        );
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $vid = (string) ($row['meta_value'] ?? '');
            if ($vid !== '' && !isset($map[$vid])) {
                $map[$vid] = (int) $row['post_id'];
            }
        }
        return $map;
    }

    private static function statusBadge(string $status): string
    {
        return match ($status) {
            'pending'     => self::badge('pending', '#fffbe6', '#7a5b00'),
            'in-progress' => self::badge('läuft',   '#e6f4ff', '#003a80'),
            'posted'      => self::badge('posted',  '#eaf7e6', '#0a5a00'),
            'skipped'     => self::badge('skipped', '#fff4d6', '#7a5b00'),
            'orphan'      => self::badge('orphan',  '#ffe6cc', '#7a4000'),
            'complete'    => self::badge('complete','#eaf7e6', '#0a5a00'),
            'failed'      => self::badge('failed',  '#ffe6e6', '#7a0000'),
            default       => esc_html($status),
        };
    }

    private static function providerBadge(string $provider): string
    {
        return match ($provider) {
            'supadata' => self::badge('supadata', '#e6f0ff', '#0040b0'),
            'yt-dlp'   => self::badge('yt-dlp',   '#e6f7ff', '#005a7a'),
            'whisper'  => self::badge('whisper',  '#f0e6ff', '#5a1a8a'),
            default    => esc_html($provider),
        };
    }

    private static function badge(string $label, string $bg, string $color): string
    {
        return sprintf(
            '<span style="display:inline-block;padding:2px 8px;background:%s;color:%s;border-radius:3px;font-size:11px;font-weight:500">%s</span>',
            esc_attr($bg),
            esc_attr($color),
            esc_html($label)
        );
    }

    private static function shorten(string $s, int $max): string
    {
        $s = trim($s);
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 1) . '…';
    }
}
