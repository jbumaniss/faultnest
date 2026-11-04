<?php

use FaultNest\Grouping\DefaultCategoryClassifier;
use FaultNest\Grouping\DefaultFingerprintStrategy;
use FaultNest\Notifications\Channels\MailChannel;

return [
    'enabled' => (bool) env('FAULTNEST_ENABLED', false),
    'project' => env('FAULTNEST_PROJECT', env('APP_NAME', 'laravel')),
    'environment' => env('APP_ENV', 'production'),
    'capture' => [
        'exceptions' => true,
    ],
    'min_level' => 'warning',
    // Host owns the HTTP route and its auth/rate limiting; disabled by default.
    'browser' => [
        'enabled' => (bool) env('FAULTNEST_BROWSER_ENABLED', false),
        'max_payload_bytes' => 16384,
        'max_level' => 'error',
    ],
    'ignore_exceptions' => [],
    'filters' => [],
    'transformers' => [],
    'classification' => ['driver' => DefaultCategoryClassifier::class],
    'grouping' => ['driver' => DefaultFingerprintStrategy::class],
    'privacy' => [
        'max_message_bytes' => 512,
        'max_stack_frames' => 20,
        'max_context_bytes' => 8192,
        'max_context_depth' => 8,
        'redact_keys' => ['password', 'passwd', 'token', 'secret', 'authorization', 'cookie', 'api_key', 'api-key', 'apikey'],
    ],
    // Host Cache/Redis only. Disable to run capture without persistence.
    'storage' => [
        'enabled' => true,
        'cache_store' => env('FAULTNEST_CACHE_STORE') ?: null,
        'ttl_seconds' => (int) env('FAULTNEST_GROUP_TTL', 86400),
        // Optional host-database retention and local PHP readers; never a remote store.
        'database' => [
            'enabled' => (bool) env('FAULTNEST_DATABASE_ENABLED', false),
            'connection' => env('FAULTNEST_DATABASE_CONNECTION') ?: null,
            'retention_days' => (int) env('FAULTNEST_DATABASE_RETENTION_DAYS', 30),
            'max_samples_per_group_per_day' => (int) env('FAULTNEST_DATABASE_SAMPLES_PER_DAY', 3),
        ],
    ],
    // Nothing is sent unless BOTH notifications and the channel are explicitly enabled.
    'notifications' => [
        'enabled' => (bool) env('FAULTNEST_NOTIFICATIONS_ENABLED', false),
        'channels' => [
            'mail' => [
                'enabled' => (bool) env('FAULTNEST_MAIL_ENABLED', false),
                'driver' => MailChannel::class,
                'recipient_resolver' => null,
                'recipients' => array_values(array_filter(array_map(
                    'trim', explode(',', (string) env('FAULTNEST_MAIL_TO', '')),
                ))),
                'min_level' => env('FAULTNEST_MAIL_MIN_LEVEL', 'error'),
                // Event sources this channel alerts on. Add `browser` and `react` to opt in for browser events.
                'allowed_sources' => array_values(array_filter(array_map(
                    'trim', explode(',', (string) env('FAULTNEST_MAIL_SOURCES', 'http,console,queue,manual')),
                ))),
                'cooldown_seconds' => (int) env('FAULTNEST_MAIL_COOLDOWN', 1800),
                'level_cooldowns' => [],
                'pending_ttl_seconds' => 3600,
                'tries' => 3,
                'backoff_seconds' => 60,
                'cache_store' => env('FAULTNEST_CACHE_STORE') ?: null,
                'queue_connection' => null,
                'queue' => 'default',
                'allow_sync' => false,
                'include_context' => false,
            ],
        ],
    ],
];
