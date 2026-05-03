<?php

declare(strict_types=1);

namespace Newss;

final class Cron
{
    public const HOOK_PERIODIC   = 'newss_cron_periodic';
    public const SCHEDULE_NAME   = 'newss_6hours';

    private const LEGACY_HOOKS = [
        'newss_cron_morning',
        'newss_cron_evening',
        'newss_cron_hourly',
        'newss_cron_daily',
    ];

    public static function register(): void
    {
        add_filter('cron_schedules', [self::class, 'addSchedule']);
        add_action(self::HOOK_PERIODIC, [RssPoller::class, 'pollAll']);
        add_action('init', [self::class, 'ensureScheduled']);
    }

    public static function addSchedule(array $schedules): array
    {
        if (!isset($schedules[self::SCHEDULE_NAME])) {
            $schedules[self::SCHEDULE_NAME] = [
                'interval' => 6 * HOUR_IN_SECONDS,
                'display'  => 'Alle 6 Stunden (Newss)',
            ];
        }
        return $schedules;
    }

    public static function ensureScheduled(): void
    {
        foreach (self::LEGACY_HOOKS as $hook) {
            if (wp_next_scheduled($hook)) {
                wp_clear_scheduled_hook($hook);
            }
        }
        if (!wp_next_scheduled(self::HOOK_PERIODIC)) {
            $tz = new \DateTimeZone('Europe/Berlin');
            $now = new \DateTimeImmutable('now', $tz);
            $hour = (int) $now->format('H');
            $nextHour = ((int) ceil(($hour + 1) / 6)) * 6;
            if ($nextHour >= 24) {
                $target = $now->modify('+1 day')->setTime(0, 0, 0);
            } else {
                $target = $now->setTime($nextHour, 0, 0);
            }
            wp_schedule_event($target->getTimestamp(), self::SCHEDULE_NAME, self::HOOK_PERIODIC);
        }
    }

    public static function scheduleEvents(): void
    {
        self::ensureScheduled();
    }

    public static function clearEvents(): void
    {
        wp_clear_scheduled_hook(self::HOOK_PERIODIC);
        foreach (self::LEGACY_HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }
}
