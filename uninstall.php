<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$options = [
    'newss_anthropic_api_key',
    'newss_anthropic_model',
    'newss_anthropic_max_tokens',
    'newss_anthropic_daily_cap',
    'newss_anthropic_calls_today',
    'newss_db_version',
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
    'newss_default_category',
    'newss_category_list',
    'newss_default_status',
    'newss_blocked_topics',
    'newss_blocked_action',
    'newss_kill_switch_drafts',
    'newss_post_author',
    'newss_channels',
    'newss_last_poll',
];
foreach ($options as $opt) {
    delete_option($opt);
}

wp_clear_scheduled_hook('newss_cron_morning');
wp_clear_scheduled_hook('newss_cron_evening');
