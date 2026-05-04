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

        add_filter('action_scheduler_retention_period', static fn(): int => 7 * DAY_IN_SECONDS);
        // Default-AS-Runner-Limit ist 30s -- pollAll mit 18 Channels +
        // Worker mit Whisper-Transcription brauchen deutlich mehr.
        add_filter('action_scheduler_queue_runner_time_limit', static fn(): int => 600);
        add_action('init', [self::class, 'maybeRunMigrations'], 1);

        if (is_admin()) {
            Settings::register();
            Channels::register();
            Updater::register();
        }

        Cron::register();
        Worker::register();
    }

    public static function maybeRunMigrations(): void
    {
        if (get_option('newss_db_version') === '1') {
            return;
        }
        self::ensurePostmetaIndex();
        update_option('newss_db_version', '1', false);
    }

    public static function onActivate(): void
    {
        self::ensurePostmetaIndex();
        Cron::scheduleEvents();
    }

    private static function ensurePostmetaIndex(): void
    {
        global $wpdb;
        $table = $wpdb->postmeta;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
            $table,
            'newss_video_id_idx'
        ));
        if ((int) $exists > 0) {
            return;
        }
        $wpdb->query("ALTER TABLE {$table} ADD INDEX newss_video_id_idx (meta_value(16), meta_key(20))");
    }

    public static function onDeactivate(): void
    {
        Cron::clearEvents();
    }
}
