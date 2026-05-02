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
        $asFile = NEWSS_DIR . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
        if (file_exists($asFile) && !function_exists('as_enqueue_async_action')) {
            require_once $asFile;
        }

        if (is_admin()) {
            Settings::register();
            Channels::register();
            Updater::register();
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
