<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API metadata
    |--------------------------------------------------------------------------
    |
    | Note: read APP_NAME from env(), not config('app.name'). Config files are
    | loaded alphabetically, so `apexdocs.php` is parsed before `app.php` and
    | config('app.*') would still be null here.
    |
    */
    'info' => [
        'title' => env('APEXDOCS_TITLE', env('APP_NAME', 'Laravel').' API'),
        'version' => env('APEXDOCS_VERSION', '1.0.0'),
        'description' => env('APEXDOCS_DESCRIPTION', ''),
        'contact' => ['name' => '', 'email' => '', 'url' => ''],
        'license' => ['name' => '', 'url' => ''],
        'terms_of_service' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Route selection
    |--------------------------------------------------------------------------
    |
    | Only routes whose URI starts with one of these prefixes are documented.
    | A string or an array; an empty array documents every route.
    |
    */
    'api_path_prefix' => env('APEXDOCS_PATH_PREFIX', 'api'),

    // Glob or anchored-regex patterns; matching routes are skipped.
    // Globs: 'api/internal/*'. Regexes are anchored: '.*internal.*'.
    'exclude_paths' => [],

    // Only routes tagged #[ApiGroup('name')] are included when set.
    'spec_group' => env('APEXDOCS_SPEC_GROUP', ''),

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    |
    | Left empty, APP_URL is used as the single server entry.
    |
    */
    'servers' => [
        // ['url' => 'https://api.example.com', 'description' => 'Production'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentation UI
    |--------------------------------------------------------------------------
    */
    'ui' => [
        // URL the docs are served from.
        'path' => env('APEXDOCS_PATH', 'documentation/api'),

        // Hides the whole header bar. The keyboard shortcuts survive it, so the
        // theme is still switchable with "t" and the dialog with "?".
        'show_toolbar' => true,

        // dark | light | auto
        'theme' => env('APEXDOCS_THEME', 'dark'),

        // Replaces the default brand mark.
        'custom_logo' => '',

        // Raw CSS appended to every docs page.
        'custom_css' => '',

        // Notice shown above the toolbar. Plain text or simple HTML.
        'announcement_banner' => '',

        // info | warning | error
        'announcement_banner_type' => 'info',

        // "Try it out" request panel in the native Apex UI.
        'try_it_out' => true,

        // curl | js | python | php | go
        'default_language' => 'curl',
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */
    'security' => [
        // Detect Sanctum / Passport / JWT from route middleware.
        'auto_detect' => true,

        // Extra OpenAPI security scheme objects, keyed by name.
        'schemes' => [
            // 'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Response generation
    |--------------------------------------------------------------------------
    */
    'responses' => [
        // Add 401 for auth-protected routes.
        'infer_error_responses' => true,

        // Add 422 + the ValidationError schema for write routes.
        'include_validation_errors' => true,

        // Add meta/links to #[ApiResponse(collection: true)] payloads.
        'include_pagination_meta' => true,

        // Recursion limit when reflecting nested DTOs.
        'max_depth' => 6,
    ],

    'rate_limits' => [
        // Add 429 + rate-limit headers for throttled routes.
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Directories scanned for classes carrying #[Webhook].
    |
    */
    'webhooks' => [
        'scan_paths' => [
            // app_path('Webhooks'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Building the spec reflects every controller, so cache it outside local
    | development. `driver` accepts any store name from config/cache.php;
    | leave it null for the application default.
    |
    */
    'cache' => [
        'enabled' => env('APEXDOCS_CACHE_ENABLED', env('APP_ENV', 'production') !== 'local'),
        'driver' => env('APEXDOCS_CACHE_DRIVER'),
        'ttl' => (int) env('APEXDOCS_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    |
    | Middleware applied to the docs routes, and the environments they are
    | registered in. An empty `environments` array means every environment —
    | if you enable that, put real auth middleware in front of the docs.
    |
    */
    'middleware' => ['web'],

    'environments' => ['local', 'staging'],

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    */
    'export' => [
        'default_path' => storage_path('apexdocs'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transformers
    |--------------------------------------------------------------------------
    |
    | Class names implementing DocumentTransformerInterface /
    | OperationTransformerInterface. They run last, after everything else.
    |
    */
    'document_transformers' => [],
    'operation_transformers' => [],
];
