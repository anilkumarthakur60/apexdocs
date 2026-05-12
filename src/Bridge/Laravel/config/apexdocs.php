<?php

return [
    'info' => [
        'title' => env('APEXDOCS_TITLE', config('app.name').' API'),
        'version' => env('APEXDOCS_VERSION', '1.0.0'),
        'description' => env('APEXDOCS_DESCRIPTION', ''),
        'contact' => ['name' => '', 'email' => '', 'url' => ''],
        'license' => ['name' => '', 'url' => ''],
        'terms_of_service' => '',
    ],

    'api_path_prefix' => env('APEXDOCS_PATH_PREFIX', 'api'),

    'exclude_paths' => [],

    'servers' => [],

    'ui' => [
        'default' => env('APEXDOCS_UI', 'scalar'),
        'path' => 'documentation/api',
        'show_ui_switcher' => true,
    ],

    'security' => [
        'auto_detect' => true,
        'schemes' => [],
    ],

    'responses' => [
        'infer_error_responses' => true,
        'include_validation_errors' => true,
        'include_pagination_meta' => true,
        'max_depth' => 6,
    ],

    'rate_limits' => [
        'enabled' => true,
    ],

    'webhooks' => [
        'enabled' => true,
        'scan_paths' => [
            // app_path('Webhooks'),
        ],
    ],

    'cache' => [
        'enabled' => env('APEXDOCS_CACHE', env('APP_ENV', 'production') !== 'local'),
        'driver' => env('APEXDOCS_CACHE_DRIVER', 'file'),
        'ttl' => env('APEXDOCS_CACHE_TTL', 3600),
    ],

    'middleware' => ['web'],
    'environments' => ['local', 'staging'],

    'export' => [
        'default_path' => storage_path('apexdocs'),
    ],

    'document_transformers' => [],
    'operation_transformers' => [],
];
