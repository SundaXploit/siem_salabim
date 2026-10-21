<?php

return [

    'opensearch' => [
        'host' => env('OPENSEARCH_HOST', 'https://localhost:9200'),
        'username' => env('OPENSEARCH_USERNAME', ''),
        'password' => env('OPENSEARCH_PASSWORD', ''),
        'index' => env('OPENSEARCH_INDEX', 'wazuh-alerts-*'),
        // Stable logical name for the source cluster. Do not derive this from
        // the host: endpoints can change while the source identity stays put.
        'source_cluster' => env('OPENSEARCH_SOURCE_CLUSTER', 'wazuh-primary'),
        'verify_ssl' => filter_var(env('OPENSEARCH_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
        'min_level' => (int) env('OPENSEARCH_MIN_LEVEL', 12),
        'size' => (int) env('OPENSEARCH_SIZE', 100),
        // Pace scroll pages so a manual backfill does not send a tight burst.
        'fetch_page_delay_ms' => (int) env('OPENSEARCH_FETCH_PAGE_DELAY_MS', 250),
        // Routine ingestion replays this much event time on every run so an
        // alert indexed a little late is still observed and de-duplicated.
        'fetch_overlap_seconds' => (int) env('OPENSEARCH_FETCH_OVERLAP_SECONDS', 900),
        // A periodic bounded rescan catches documents indexed after their
        // event-time window has moved behind the incremental checkpoint.
        'reconcile_lookback_days' => (int) env('OPENSEARCH_RECONCILE_LOOKBACK_DAYS', 2),
        'fetch_insert_batch_size' => (int) env('OPENSEARCH_FETCH_INSERT_BATCH_SIZE', 100),
        'fetch_lock_seconds' => (int) env('OPENSEARCH_FETCH_LOCK_SECONDS', 3600),
        // PIT/search_after is safe only when the final sort field is a
        // guaranteed-unique keyword with doc values. Wazuh/custom pipelines
        // differ, so scroll remains the fail-safe default until an operator
        // explicitly supplies that field.
        'pit_enabled' => filter_var(env('OPENSEARCH_PIT_ENABLED', false), FILTER_VALIDATE_BOOL),
        'pit_tiebreaker_field' => trim((string) env('OPENSEARCH_PIT_TIEBREAKER_FIELD', '')),
        'pit_keep_alive_seconds' => (int) env('OPENSEARCH_PIT_KEEP_ALIVE_SECONDS', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram Bot API
    |--------------------------------------------------------------------------
    |
    | Network resilience belongs on the server side. IPv4 is the safe default
    | for the current Windows/Laragon host and can be disabled through .env.
    |
    */
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
        'base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
        'connect_timeout' => (float) env('TELEGRAM_CONNECT_TIMEOUT', 5),
        'timeout' => (float) env('TELEGRAM_TIMEOUT', 20),
        'max_attempts' => (int) env('TELEGRAM_MAX_ATTEMPTS', 3),
        'force_ipv4' => filter_var(env('TELEGRAM_FORCE_IPV4', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | AmanAI
    |--------------------------------------------------------------------------
    |
    | This configuration is intentionally server-side only. Never prefix the
    | key with VITE_ or expose it in Blade/JavaScript.
    |
    */
    'amanai' => [
        'base_url' => rtrim((string) env('AMANAI_BASE_URL', 'https://api.amanai.dev/v1'), '/'),
        'api_key' => env('AMANAI_API_KEY'),
        'model' => env('AMANAI_MODEL', 'amanai/deepseek-v4.1-flash'),
        'timeout' => (int) env('AMANAI_TIMEOUT', 90),
        'refresh_days' => (int) env('AMANAI_REFRESH_DAYS', 10),
        'snapshot_limit' => (int) env('AMANAI_SNAPSHOT_LIMIT', 10000),
        'reasoning_effort' => env('AMANAI_REASONING_EFFORT', 'none'),
        'max_output_tokens' => (int) env('AMANAI_MAX_OUTPUT_TOKENS', 4096),
        'alert_timeout' => (int) env('AMANAI_ALERT_TIMEOUT', 60),
        // Optional override; choose a model that supports "none" to disable thinking.
        'alert_model' => env('AMANAI_ALERT_MODEL'),
        'alert_reasoning_effort' => env('AMANAI_ALERT_REASONING_EFFORT', 'none'),
        'alert_max_output_tokens' => (int) env('AMANAI_ALERT_MAX_OUTPUT_TOKENS', 2800),
    ],

];
