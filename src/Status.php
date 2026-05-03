<?php

declare(strict_types=1);

namespace Newss;

final class Status
{
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
        if (!function_exists('as_get_scheduled_actions')) {
            return;
        }

        $statusCounts = self::statusCounts();
        $rows = self::collectActions(40);
        ?>
        <h2>Job-Pipeline</h2>
        <p class="description" style="max-width:880px;margin-bottom:8px">
            <?php foreach ($statusCounts as $status => $count): ?>
                <span style="margin-right:14px"><strong><?php echo esc_html($status); ?>:</strong> <?php echo (int) $count; ?></span>
            <?php endforeach; ?>
        </p>
        <?php if (!$rows): ?>
            <p><em>Keine Jobs in der Pipeline.</em></p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:1080px">
                <thead>
                    <tr>
                        <th style="width:90px">Status</th>
                        <th style="width:130px">Geplant für</th>
                        <th>Video</th>
                        <th style="width:160px">Channel</th>
                        <th>Letzter Log-Eintrag</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo self::statusBadge($r['status']); ?></td>
                        <td style="font-size:11px"><?php echo esc_html($r['scheduled']); ?></td>
                        <td>
                            <strong><?php echo esc_html($r['title']); ?></strong><br>
                            <a href="https://www.youtube.com/watch?v=<?php echo esc_attr($r['video_id']); ?>" target="_blank" rel="noopener" style="font-size:11px"><?php echo esc_html($r['video_id']); ?></a>
                        </td>
                        <td><?php echo esc_html($r['channel']); ?></td>
                        <td style="font-size:11px;<?php echo $r['status'] === 'failed' ? 'color:#c00' : 'color:#666'; ?>">
                            <?php echo esc_html($r['last_log']); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <hr style="margin:32px 0">
        <?php
    }

    private static function statusCounts(): array
    {
        $statuses = ['pending', 'in-progress', 'complete', 'failed'];
        $out = [];
        foreach ($statuses as $s) {
            $count = as_get_scheduled_actions([
                'hook'     => Worker::HOOK_PROCESS,
                'group'    => 'newss',
                'status'   => $s,
                'per_page' => 1,
            ], 'ids');
            $out[$s] = is_array($count) ? self::countActions($s) : 0;
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
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function collectActions(int $limit): array
    {
        $rows = [];

        foreach (['pending', 'in-progress'] as $status) {
            foreach (self::fetch($status, 15, 'ASC') as $r) {
                $rows[] = $r;
            }
        }
        foreach (['failed', 'complete'] as $status) {
            foreach (self::fetch($status, 12, 'DESC') as $r) {
                $rows[] = $r;
            }
        }
        return array_slice($rows, 0, $limit);
    }

    private static function fetch(string $status, int $perPage, string $order): array
    {
        $ids = as_get_scheduled_actions([
            'hook'     => Worker::HOOK_PROCESS,
            'group'    => 'newss',
            'status'   => $status,
            'per_page' => $perPage,
            'order'    => $order,
            'orderby'  => 'date',
        ], 'ids');
        if (!is_array($ids) || $ids === []) {
            return [];
        }
        $store  = \ActionScheduler::store();
        $logger = \ActionScheduler::logger();
        $out = [];
        foreach ($ids as $actionId) {
            try {
                $action = $store->fetch_action($actionId);
            } catch (\Throwable) {
                continue;
            }
            $args = $action ? $action->get_args() : [];
            $payload = $args[0] ?? [];
            $schedule = $action ? $action->get_schedule() : null;
            $next = $schedule && method_exists($schedule, 'get_date') ? $schedule->get_date() : null;
            $logs = $logger->get_logs($actionId);
            $lastLog = $logs ? end($logs) : null;
            $out[] = [
                'status'     => $status,
                'video_id'   => (string) ($payload['video_id'] ?? ''),
                'title'      => self::shorten((string) ($payload['video_title'] ?? ''), 80),
                'channel'    => (string) ($payload['channel_name'] ?? ''),
                'scheduled'  => $next ? $next->format('Y-m-d H:i') : '—',
                'last_log'   => $lastLog ? self::shorten($lastLog->get_message(), 200) : '',
            ];
        }
        return $out;
    }

    private static function statusBadge(string $status): string
    {
        return match ($status) {
            'pending'     => '<span style="display:inline-block;padding:2px 8px;background:#fffbe6;color:#7a5b00;border-radius:3px;font-size:11px">pending</span>',
            'in-progress' => '<span style="display:inline-block;padding:2px 8px;background:#e6f4ff;color:#003a80;border-radius:3px;font-size:11px">in-progress</span>',
            'complete'    => '<span style="display:inline-block;padding:2px 8px;background:#eaf7e6;color:#0a5a00;border-radius:3px;font-size:11px">complete</span>',
            'failed'      => '<span style="display:inline-block;padding:2px 8px;background:#ffe6e6;color:#7a0000;border-radius:3px;font-size:11px">failed</span>',
            default       => esc_html($status),
        };
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
