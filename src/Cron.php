<?php

declare(strict_types=1);

namespace Newss;

final class Cron
{
    public const HOOK_MORNING = 'newss_cron_morning';
    public const HOOK_EVENING = 'newss_cron_evening';

    public static function register(): void
    {
        add_action(self::HOOK_MORNING, [RssPoller::class, 'pollAll']);
        add_action(self::HOOK_EVENING, [RssPoller::class, 'pollAll']);
    }

    public static function scheduleEvents(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');
        foreach ([self::HOOK_MORNING => '09:00', self::HOOK_EVENING => '19:00'] as $hook => $time) {
            if (wp_next_scheduled($hook)) {
                continue;
            }
            wp_schedule_event(self::nextRunTimestamp($time, $tz), 'daily', $hook);
        }
    }

    public static function clearEvents(): void
    {
        wp_clear_scheduled_hook(self::HOOK_MORNING);
        wp_clear_scheduled_hook(self::HOOK_EVENING);
    }

    private static function nextRunTimestamp(string $hhmm, \DateTimeZone $tz): int
    {
        $now = new \DateTimeImmutable('now', $tz);
        $target = $now->setTime((int) substr($hhmm, 0, 2), (int) substr($hhmm, 3, 2), 0);
        if ($target <= $now) {
            $target = $target->modify('+1 day');
        }
        return $target->getTimestamp();
    }
}
