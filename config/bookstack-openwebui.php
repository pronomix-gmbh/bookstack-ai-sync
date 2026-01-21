<?php

declare(strict_types=1);

return [
    'enabled' => env('OPENWEBUI_ENABLED', false),
    'instance_name' => env('OPENWEBUI_INSTANCE_NAME', env('APP_NAME', 'bookstack')),

    'openwebui' => [
        'base_url' => env('OPENWEBUI_BASE_URL', ''),
        'api_key' => env('OPENWEBUI_API_KEY', ''),
        'timeout' => (int) env('OPENWEBUI_TIMEOUT', 30),
        'verify_tls' => env('OPENWEBUI_VERIFY_TLS', true),
        'paths' => [
            'knowledge_list' => '/api/v1/knowledge/',
            'knowledge_create' => '/api/v1/knowledge/create',
            'file_upload' => '/api/v1/files/',
            'knowledge_file_add' => '/api/v1/knowledge/{id}/file/add',
            'file_delete' => '/api/v1/files/{id}',
            'knowledge_delete' => '/api/v1/knowledge/{id}/delete',
        ],
    ],

    'queue' => [
        'dispatch_jobs' => env('OPENWEBUI_QUEUE_DISPATCH', false),
        'max_attempts' => (int) env('OPENWEBUI_MAX_ATTEMPTS', 5),
        'backoff_seconds' => (int) env('OPENWEBUI_BACKOFF_SECONDS', 60),
        'max_tasks_per_run' => (int) env('OPENWEBUI_MAX_TASKS_PER_RUN', 25),
    ],

    'polling' => [
        'enabled' => env('OPENWEBUI_POLL_ENABLED', true),
        'interval_minutes' => (int) env('OPENWEBUI_POLL_INTERVAL', 5),
    ],

    'log_channel' => env('OPENWEBUI_LOG_CHANNEL', null),
];
