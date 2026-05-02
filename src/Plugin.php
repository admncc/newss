<?php

declare(strict_types=1);

namespace Newss;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if (is_admin()) {
            Settings::register();
            Channels::register();
        }

        Cron::register();
        Worker::register();
    }

    public static function onActivate(): void
    {
        Cron::scheduleEvents();
    }

    public static function onDeactivate(): void
    {
        Cron::clearEvents();
    }
}
