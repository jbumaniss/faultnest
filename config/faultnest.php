<?php

use FaultNest\Grouping\DefaultCategoryClassifier;
use FaultNest\Grouping\DefaultFingerprintStrategy;

return [
    'enabled' => (bool) env('FAULTNEST_ENABLED', false),
    'project' => env('FAULTNEST_PROJECT', env('APP_NAME', 'laravel')),
    'environment' => env('APP_ENV', 'production'),
    'capture' => [
        'exceptions' => true,
    ],
    'min_level' => 'warning',
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
        'redact_keys' => ['password', 'token', 'secret', 'authorization', 'cookie', 'api_key'],
    ],
    // History, delivery and reader implementations are future milestones.
    'storage' => ['enabled' => false],
    'notifications' => ['enabled' => false],
];
