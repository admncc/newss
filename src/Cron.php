<?php

declare(strict_types=1);

namespace Newss;

final class Cron
{
    public const HOOK_HOURLY = 'newss_cron_hourly';

    private const LEGACY_HOOKS = ['newss_cron_morning', 'newss_cron_evening'];

    public static function register(): void
    {
        add_action(self::HOOK_HOURLY, [RssPoller::class, 'pollAll']);
        add_action('init', [self::class, 'ensureScheduled']);
    }

    public static function ensureScheduled(): void
    {
        foreach (self::LEGACY_HOOKS as $hook) {
            if (wp_next_scheduled($hook)) {
                wp_clear_scheduled_hook($hook);
            }
        }
        if (!wp_next_scheduled(self::HOOK_HOURLY)) {
            wp_schedule_event(time() + 60, 'hourly', self::HOOK_HOURLY);
        }
    }

    public static function scheduleEvents(): void
    {
        self::ensureScheduled();
    }

    public static function clearEvents(): void
    {
        wp_clear_scheduled_hook(self::HOOK_HOURLY);
        foreach (self::LEGACY_HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }
}
