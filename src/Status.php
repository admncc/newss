<?php

declare(strict_types=1);

namespace Newss;

final class Status
{
    private const PER_PAGE = 50;

    public static function renderChannelPoll(): void
    {
        $last = get_option('newss_last_poll', null);
        if (!is_array($last) || empty($last['channels'])) {
            return;
        }
        ?>
        <h2>Letzte Channel-Polls</h2>
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
        <hr style="margin:32px 0">
        <?php
    }

    public static function renderPipeline(): void
    {
        if (!function_exists('as_get_scheduled_actions') || !class_exists('\\ActionScheduler')) {
            return;
        }

        $statusCounts  = self::statusCounts();
        $channelFilter = isset($_GET['newss_channel']) ? sanitize_text_field(wp_unslash((string) $_GET['newss_channel'])) : '';

        $page    = max(1, absint($_GET['newss_page'] ?? 1));
        $perPage = self::PER_PAGE;

        $since = new \DateTime('24 hours ago', new \DateTimeZone('UTC'));

        if ($channelFilter !== '') {
            $allRows = self::fetchSince($since, 500, 0);
            $allRows = array_values(array_filter(
                $allRows,
                static fn(array $r): bool => ($r['channel'] ?? '') === $channelFilter
            ));
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
        $filterUrlBase = admin_url('admin.php?page=newss-settings');

        $statsLabels = [
            'pending'     => 'Pending',
            'in-progress' => 'Läuft',
            'complete'    => 'Complete (gesamt)',
            'failed'      => 'Failed',
        ];
        ?>
        <h2>Job-Pipeline (letzte 24h)</h2>
        <p class="description" style="max-width:1280px;margin-bottom:8px">
            <?php foreach ($statusCounts as $key => $count): ?>
                <span style="margin-right:14px"><strong><?php echo esc_html($statsLabels[$key] ?? $key); ?>:</strong> <?php echo (int) $count; ?></span>
            <?php endforeach; ?>
            <span style="margin-left:auto;color:#888;font-size:11px">— „complete" enthält <em>posted</em>, <em>skipped</em>, <em>orphan</em></span>
        </p>

        <form method="get" action="" style="margin:8px 0 12px 0;display:flex;align-items:center;gap:8px">
            <input type="hidden" name="page" value="newss-settings">
            <label for="newss_channel" style="font-weight:600">Filter nach Kanal:</label>
            <select name="newss_channel" id="newss_channel" onchange="this.form.submit()">
                <option value="">— Alle Kanäle —</option>
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
            <?php if ($channelFilter !== ''): ?>
                <a href="<?php echo esc_url($filterUrlBase); ?>" class="button button-small">Filter zurücksetzen</a>
            <?php endif; ?>
        </form>
        <?php if (!$rows): ?>
            <p><em>Keine Jobs in der letzten 24 Stunden.</em></p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:1280px">
                <thead>
                    <tr>
                        <th style="width:90px">Status</th>
                        <th style="width:120px">Geplant</th>
                        <th style="width:120px">Letztes Update</th>
                        <th>Video</th>
                        <th style="width:140px">Channel</th>
                        <th style="width:160px">Artikel / Grund</th>
                        <th>Letzter Log-Eintrag</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo self::statusBadge($r['effective']); ?></td>
                        <td style="font-size:11px"><?php echo esc_html($r['scheduled']); ?></td>
                        <td style="font-size:11px"><?php echo esc_html($r['updated'] ?: '—'); ?></td>
                        <td>
                            <strong><?php echo esc_html($r['title']); ?></strong><br>
                            <a href="https://www.youtube.com/watch?v=<?php echo esc_attr($r['video_id']); ?>" target="_blank" rel="noopener" style="font-size:11px"><?php echo esc_html($r['video_id']); ?></a>
                        </td>
                        <td><?php echo esc_html($r['channel']); ?></td>
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
            <?php echo self::renderPagination($page, $totalPages, $total, $channelFilter); ?>
        <?php endif; ?>
        <hr style="margin:32px 0">
        <?php
    }

    private static function renderPagination(int $page, int $totalPages, int $total, string $channelFilter = ''): string
    {
        $base = admin_url('admin.php?page=newss-settings');
        if ($channelFilter !== '') {
            $base = add_query_arg('newss_channel', $channelFilter, $base);
        }
        if ($totalPages <= 1) {
            return '<p class="description" style="margin-top:8px">' . (int) $total . ' Jobs in letzter 24h' . ($channelFilter !== '' ? ' (gefiltert)' : '') . '.</p>';
        }
        $out = '<p style="margin-top:12px;display:flex;align-items:center;gap:8px">';
        if ($page > 1) {
            $out .= sprintf('<a class="button" href="%s">‹ Zurück</a>', esc_url(add_query_arg('newss_page', $page - 1, $base)));
        }
        $out .= sprintf('<span class="description">Seite %d / %d &nbsp;·&nbsp; %d Jobs gesamt%s (%d pro Seite)</span>', $page, $totalPages, $total, $channelFilter !== '' ? ' im gefilterten Kanal' : '', self::PER_PAGE);
        if ($page < $totalPages) {
            $out .= sprintf('<a class="button" href="%s">Weiter ›</a>', esc_url(add_query_arg('newss_page', $page + 1, $base)));
        }
        $out .= '</p>';
        return $out;
    }

    private static function statusCounts(): array
    {
        $statuses = ['pending', 'in-progress', 'complete', 'failed'];
        $out = [];
        foreach ($statuses as $s) {
            $out[$s] = self::countActions($s);
        }
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
        $logger = \ActionScheduler::logger();

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
            $logs     = $logger->get_logs($actionId);

            $videoId = (string) ($payload['video_id'] ?? '');
            if ($status === 'complete' && $videoId !== '') {
                $videoIdsToLookup[] = $videoId;
            }

            $rawRows[] = [
                'status'   => $status,
                'payload'  => $payload,
                'next'     => $next,
                'logs'     => $logs,
                'video_id' => $videoId,
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
            if ($lastLog) {
                try {
                    $d = $lastLog->get_date();
                    if ($d) {
                        $updated = wp_date('Y-m-d H:i', $d->getTimestamp());
                    }
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
            if ($status === 'complete') {
                if ($postUrl !== '') {
                    $effective = 'posted';
                } else {
                    foreach (array_reverse($logs ?: []) as $log) {
                        $msg = $log->get_message();
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
                'video_id'   => $videoId,
                'title'      => self::shorten((string) ($payload['video_title'] ?? ''), 80),
                'channel'    => (string) ($payload['channel_name'] ?? ''),
                'channel_id' => (string) ($payload['channel_id'] ?? ''),
                'scheduled'  => $next ? $next->format('Y-m-d H:i') : '—',
                'updated'    => $updated,
                'last_log'   => $lastLog ? self::shorten($lastLog->get_message(), 200) : '',
                'post_url'   => $postUrl,
                'edit_url'   => $editUrl,
            ];
        }
        return $out;
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
