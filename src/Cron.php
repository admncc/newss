<?php

declare(strict_types=1);

namespace Newss;

final class Cron
{
    public const HOOK_PERIODIC = 'newss_cron_periodic';

    private const LEGACY_HOOKS = [
        'newss_cron_morning',
        'newss_cron_evening',
        'newss_cron_hourly',
        'newss_cron_daily',
    ];

    public static function register(): void
    {
        add_action(self::HOOK_PERIODIC, [RssPoller::class, 'pollAll']);
        add_action('init', [self::class, 'ensureScheduled']);
    }

    public static function ensureScheduled(): void
    {
        foreach (self::LEGACY_HOOKS as $hook) {
            if (wp_next_scheduled($hook)) {
                wp_clear_scheduled_hook($hook);
            }
        }

        $current = wp_get_scheduled_event(self::HOOK_PERIODIC);
        if ($current && $current->schedule !== 'hourly') {
            wp_clear_scheduled_hook(self::HOOK_PERIODIC);
            $current = false;
        }

        if (!$current) {
            $tz = new \DateTimeZone('Europe/Berlin');
            $now = new \DateTimeImmutable('now', $tz);
            $target = $now->setTime((int) $now->format('H') + 1, 0, 0);
            wp_schedule_event($target->getTimestamp(), 'hourly', self::HOOK_PERIODIC);
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
