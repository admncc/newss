<?php

declare(strict_types=1);

namespace Newss;

final class Cron
{
    public const HOOK_DAILY = 'newss_cron_daily';

    private const LEGACY_HOOKS = [
        'newss_cron_morning',
        'newss_cron_evening',
        'newss_cron_hourly',
    ];

    public static function register(): void
    {
        add_action(self::HOOK_DAILY, [RssPoller::class, 'pollAll']);
        add_action('init', [self::class, 'ensureScheduled']);
    }

    public static function ensureScheduled(): void
    {
        foreach (self::LEGACY_HOOKS as $hook) {
            if (wp_next_scheduled($hook)) {
                wp_clear_scheduled_hook($hook);
            }
        }
        if (!wp_next_scheduled(self::HOOK_DAILY)) {
            $tz = new \DateTimeZone('Europe/Berlin');
            $now = new \DateTimeImmutable('now', $tz);
            $target = $now->setTime(9, 0, 0);
            if ($target <= $now) {
                $target = $target->modify('+1 day');
            }
            wp_schedule_event($target->getTimestamp(), 'daily', self::HOOK_DAILY);
        }
    }

    public static function scheduleEvents(): void
    {
        self::ensureScheduled();
    }

    public static function clearEvents(): void
    {
        wp_clear_scheduled_hook(self::HOOK_DAILY);
        foreach (self::LEGACY_HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }
}
