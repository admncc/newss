<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$options = [
    'newss_anthropic_api_key',
    'newss_anthropic_model',
    'newss_anthropic_max_tokens',
    'newss_anthropic_daily_cap',
    'newss_anthropic_calls_today',
    'newss_anthropic_temperature',
    'newss_system_prompt',
    'newss_user_prompt_template',
    'newss_youtube_proxy',
    'newss_youtube_cookie',
    'newss_youtube_api_key',
    'newss_youtube_method',
    'newss_supadata_api_key',
    'newss_ytdlp_path',
    'newss_whisper_enabled',
    'newss_whisper_api_key',
    'newss_whisper_daily_cap',
    'newss_whisper_calls_today',
    'newss_default_category',
    'newss_category_list',
    'newss_default_status',
    'newss_blocked_topics',
    'newss_blocked_action',
    'newss_kill_switch_drafts',
    'newss_post_author',
    'newss_channels',
    'newss_last_poll',
    'newss_last_cron_run',
    'newss_poll_progress',
    'newss_db_version',
];
foreach ($options as $opt) {
    delete_option($opt);
}

// Wildcard-Cleanup für alles was wir mit Prefix newss_ angelegt haben
// (Locks, Health-Status pro Provider, dynamische Channel-Caches etc.)
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'newss\\_lock\\_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'newss\\_health\\_%'");

// Transients (newss_pending_*, newss_uploads_*, newss_yt_quota_exhausted,
// newss_poll_running, newss_status_counts, newss_channel_notice,
// newss_whisper_test, newss_update_notice)
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_newss_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_newss_%'");

// Cron-Hooks (alle bekannten + legacy)
foreach (['newss_cron_periodic', 'newss_cron_morning', 'newss_cron_evening', 'newss_cron_hourly', 'newss_cron_daily', 'newss_run_poll_now', 'newss_process_video'] as $hook) {
    wp_clear_scheduled_hook($hook);
}

// Action-Scheduler-Group leerräumen falls verfügbar
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions(null, [], 'newss');
}
