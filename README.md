# ApexDocs

[![Latest Stable Version](https://img.shields.io/packagist/v/anil/apexdocs.svg?style=flat-square)](https://packagist.org/packages/anil/apexdocs)
[![Total Downloads](https://img.shields.io/packagist/dt/anil/apexdocs.svg?style=flat-square)](https://packagist.org/packages/anil/apexdocs)
[![PHP Version](https://img.shields.io/packagist/php-v/anil/apexdocs.svg?style=flat-square)](https://packagist.org/packages/anil/apexdocs)
[![License](https://img.shields.io/packagist/l/anil/apexdocs.svg?style=flat-square)](LICENSE)
[![Tests](https://img.shields.io/github/actions/workflow/status/anilkumarthakur60/apexdocs/tests.yml?branch=main&style=flat-square&label=tests)](https://github.com/anilkumarthakur60/apexdocs/actions/workflows/tests.yml)

**Framework-agnostic OpenAPI 3.1 documentation generator for PHP 8.2+.**

Zero framework dependencies in the core. Works with Laravel, Symfony, Slim, or any PHP project — bring your own route collection.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration (Laravel)](#configuration-laravel)
- [PHP Attributes](#php-attributes)
- [Artisan Commands](#artisan-commands-laravel)
- [Standalone Usage](#standalone-usage-no-framework)
- [Symfony](#symfony)
- [PSR-15 Frameworks](#psrr-15-slim-mezzio-etc)
- [Custom Framework Bridge](#custom-framework-bridge)
- [Customisation](#customisation)
- [UI Options](#ui-options)
- [Restricting Access](#restricting-access)
- [Caching](#caching)
- [Architecture](#architecture)

---

## Features

- Generates **OpenAPI 3.1** specs automatically from routes and code
- Full **Laravel** integration with auto-discovery (no setup beyond publish)
- **Symfony** route collection support
- Any **PSR-7/PSR-15** framework via the built-in HTTP handler
- Auto-detects **Sanctum**, **Passport**, and **JWT** security from middleware
- Extracts request schemas from Laravel **FormRequest** rules
- Serves interactive docs with **5 UI options** (Scalar, Swagger UI, ReDoc, Stoplight Elements, RapiDoc)
- Exports to **Postman Collection** and **Insomnia**
- Breaking change detection, watch mode, and mock server

---

## Requirements

- PHP 8.2+
- Laravel 11 or 12 (for the Laravel bridge)

---

## Installation

```bash
composer require anil/apexdocs
```

### Laravel

The service provider is auto-discovered. Publish the config file:

```bash
php artisan vendor:publish --tag=apexdocs-config
```

This creates `config/apexdocs.php`. Then visit `/documentation/api` in your browser.

### Local Development Install

Add a path repository to your project's `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "/path/to/apexdocs"
    }
]
```

Then require it:

```bash
composer require anil/apexdocs:@dev
```

---

## Quick Start

### Laravel

After installing, visit these URLs:

| URL | Description |
|-----|-------------|
| `/documentation/api` | Interactive docs UI |
| `/documentation/api/spec.json` | OpenAPI 3.1 JSON |
| `/documentation/api/spec.yaml` | OpenAPI 3.1 YAML |
| `/documentation/api/postman` | Postman Collection v2.1 |
| `/documentation/api/insomnia` | Insomnia export |

No configuration is required. Routes with the `api` prefix are included automatically.

---

## Configuration (Laravel)

`config/apexdocs.php` — all options with their defaults:

```php
return [

    // API metadata
    'info' => [
        'title'           => env('APP_NAME') . ' API',
        'version'         => '1.0.0',
        'description'     => '',
        'contact'         => ['name' => '', 'email' => '', 'url' => ''],
        'license'         => ['name' => '', 'url' => ''],
        'terms_of_service' => '',
    ],

    // Only routes whose URI starts with these prefixes are included
    'api_path_prefix' => 'api',    // string or array: ['api', 'v2']

    // Paths to exclude (prefix match)
    'exclude_paths' => [],

    // Manually declare server URLs (auto-detected from APP_URL when empty)
    'servers' => [
        // ['url' => 'https://api.example.com', 'description' => 'Production'],
    ],

    'ui' => [
        'default'          => 'scalar',    // scalar | swagger | redoc | stoplight | rapidoc
        'path'             => 'documentation/api',  // URL where docs are served
        'show_ui_switcher' => true,        // show UI switcher toolbar
    ],

    'security' => [
        // Auto-detect Sanctum / Passport / JWT from route middleware
        'auto_detect' => true,
        // Add custom OpenAPI security scheme objects
        'schemes' => [
            // 'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
        ],
    ],

    'responses' => [
        'infer_error_responses'     => true,  // auto-add 4xx/5xx responses
        'include_validation_errors' => true,  // include validation error schema
        'include_pagination_meta'   => true,  // include pagination metadata
        'max_depth'                 => 6,     // max nested schema depth
    ],

    'rate_limits' => [
        'enabled' => true,  // document rate limit response headers
    ],

    'webhooks' => [
        'enabled'    => true,
        'scan_paths' => [
            // app_path('Events'),
        ],
    ],

    'cache' => [
        'enabled' => env('APEXDOCS_CACHE_ENABLED', ! app()->environment('local')),
        'driver'  => env('APEXDOCS_CACHE_DRIVER', 'file'),
        'ttl'     => 3600,
    ],

    // Middleware applied to the /docs routes
    'middleware' => ['web'],

    // Docs are only visible in these environments
    'environments' => ['local', 'staging'],

    // Transformer classes (see Customisation section)
    'document_transformers'  => [],
    'operation_transformers' => [],

    'export' => [
        'default_path' => storage_path('apexdocs'),
    ],
];
```

---

## PHP Attributes

Annotate controllers and methods to enrich the generated spec.

### Grouping and Metadata

```php
use ApexDocs\Attribute\Group;
use ApexDocs\Attribute\Endpoint;
use ApexDocs\Attribute\Tag;
use ApexDocs\Attribute\Hidden;
use ApexDocs\Attribute\Deprecated;

#[Group(name: 'Users', description: 'User management')]
class UserController extends Controller
{
    #[Endpoint(summary: 'List users', description: 'Returns a paginated list.')]
    public function index() { ... }

    #[Tag('Admin')]
    public function adminIndex() { ... }

    #[Deprecated(message: 'Use /v2/users instead', since: '1.5.0')]
    public function oldList() { ... }

    #[Hidden]
    public function internalEndpoint() { ... }
}
```

### Security

```php
use ApexDocs\Attribute\Security;
use ApexDocs\Attribute\NoSecurity;

// Require a specific security scheme for this endpoint
#[Security(scheme: 'sanctum')]
public function profile() { ... }

// Require specific OAuth2 scopes
#[Security(scheme: 'passport', scopes: ['read:users'])]
public function adminList() { ... }

// Mark as public (override global security)
#[NoSecurity]
public function publicFeed() { ... }
```

### Parameters

```php
use ApexDocs\Attribute\PathParam;
use ApexDocs\Attribute\QueryParam;
use ApexDocs\Attribute\HeaderParam;
use ApexDocs\Attribute\CookieParam;

#[PathParam(name: 'id', type: 'integer', description: 'User ID')]
#[QueryParam(name: 'status', type: 'string', required: false, enum: ['active', 'inactive'])]
#[QueryParam(name: 'page', type: 'integer', example: 1)]
#[QueryParam(name: 'per_page', type: 'integer', example: 15)]
#[HeaderParam(name: 'X-Tenant-ID', type: 'string', required: true)]
#[CookieParam(name: 'session_id')]
public function show(int $id) { ... }
```

### Responses

```php
use ApexDocs\Attribute\ApiResponse;
use ApexDocs\Attribute\Example;

// Single resource response
#[ApiResponse(status: 200, description: 'User found', resource: UserResource::class)]
#[ApiResponse(status: 404, description: 'User not found')]
public function show(User $user) { ... }

// Collection response
#[ApiResponse(status: 200, description: 'User list', resource: UserResource::class, collection: true)]
public function index() { ... }

// With inline schema
#[ApiResponse(status: 200, description: 'Token', schema: ['type' => 'object', 'properties' => ['token' => ['type' => 'string']]])]
public function login() { ... }

// With examples
#[ApiResponse(status: 200, resource: UserResource::class)]
#[Example(name: 'active_user', value: ['id' => 1, 'name' => 'Jane', 'status' => 'active'])]
#[Example(name: 'inactive_user', value: ['id' => 2, 'name' => 'John', 'status' => 'inactive'])]
public function show(User $user) { ... }
```

### Webhooks

```php
use ApexDocs\Attribute\Webhook;

#[Webhook(
    name: 'payment.completed',
    summary: 'Fired when a payment completes',
    tags: ['payments'],
    schema: [
        'type'       => 'object',
        'properties' => [
            'id'     => ['type' => 'integer'],
            'amount' => ['type' => 'number'],
            'status' => ['type' => 'string'],
        ],
    ]
)]
class PaymentCompletedEvent { ... }
```

Enable webhook scanning in config:

```php
'webhooks' => [
    'enabled'    => true,
    'scan_paths' => [app_path('Events')],
],
```

### All Available Attributes

| Attribute | Target | Purpose |
|-----------|--------|---------|
| `#[Group(name, description?)]` | Class | Tag all methods under a group |
| `#[Endpoint(summary, description?)]` | Method | Override summary/description |
| `#[Tag(name, description?)]` | Class, Method | Add OpenAPI tags |
| `#[Hidden]` | Class, Method | Exclude from spec |
| `#[Deprecated(message?, since?)]` | Class, Method | Mark as deprecated |
| `#[NoSecurity]` | Class, Method | Mark as public |
| `#[Security(scheme, scopes?)]` | Class, Method | Declare required security |
| `#[PathParam(name, type?, description?, example?)]` | Method | Document path parameter |
| `#[QueryParam(name, type?, description?, required?, example?, enum?, deprecated?)]` | Method | Document query parameter |
| `#[HeaderParam(name, type?, description?, required?, example?)]` | Method | Document header parameter |
| `#[CookieParam(name, type?, description?, required?, example?)]` | Method | Document cookie parameter |
| `#[ApiResponse(status, description?, resource?, collection?, schema?, headers?)]` | Method | Document a response |
| `#[Example(name, value, summary?, for?)]` | Method | Attach an example |
| `#[Webhook(name, summary?, description?, schema?, tags?)]` | Class | Register as webhook |

---

## Artisan Commands (Laravel)

### Generate

```bash
# Print JSON to stdout
php artisan apexdocs:generate

# YAML format
php artisan apexdocs:generate --format=yaml

# Save to file
php artisan apexdocs:generate --output=public/openapi.json
```

### Validate

```bash
php artisan apexdocs:validate
```

Checks for missing operation IDs, empty summaries, unreachable paths, and other issues.

### Export

```bash
php artisan apexdocs:export openapi-json  --output=storage/apexdocs/spec.json
php artisan apexdocs:export openapi-yaml  --output=storage/apexdocs/spec.yaml
php artisan apexdocs:export postman       --output=storage/apexdocs/postman.json
php artisan apexdocs:export insomnia      --output=storage/apexdocs/insomnia.json
```

### Detect Breaking Changes

```bash
# Save a baseline first
php artisan apexdocs:generate --output=storage/apexdocs/baseline.json

# Compare against it later
php artisan apexdocs:diff storage/apexdocs/baseline.json

# JSON output (useful in CI)
php artisan apexdocs:diff storage/apexdocs/baseline.json --format=json
```

### Watch Mode

Auto-regenerates the spec whenever files in `app/` or `routes/` change:

```bash
php artisan apexdocs:watch
php artisan apexdocs:watch --output=public/openapi.json --interval=3
```

### Mock Server

Starts a local HTTP server that returns example responses from the spec:

```bash
php artisan apexdocs:mock
php artisan apexdocs:mock --host=127.0.0.1 --port=8081
```

---

## Standalone Usage (No Framework)

```php
use ApexDocs\ApexDocs;
use ApexDocs\Config;
use ApexDocs\Route\ArrayRouteCollection;
use ApexDocs\Route\Route;

$routes = new ArrayRouteCollection([
    new Route(['GET'],    '/api/users',      [UserController::class, 'index']),
    new Route(['POST'],   '/api/users',      [UserController::class, 'store']),
    new Route(['GET'],    '/api/users/{id}', [UserController::class, 'show']),
    new Route(['DELETE'], '/api/users/{id}', [UserController::class, 'destroy']),
]);

$config = Config::fromArray([
    'title'   => 'My API',
    'version' => '2.0.0',
]);

$doc = ApexDocs::make($config)->routes($routes)->generate();

// Get as JSON string
echo $doc->toJson();

// Get as array
$array = $doc->toArray();
```

---

## Symfony

```php
use ApexDocs\ApexDocs;
use ApexDocs\Config;
use ApexDocs\Bridge\Symfony\RouteCollection;

$routes = new RouteCollection($symfonyRouter);

$doc = ApexDocs::make(Config::fromArray(['title' => 'My API']))
    ->routes($routes)
    ->generate();

echo $doc->toJson();
```

---

## PSR-15 (Slim, Mezzio, etc.)

```php
use ApexDocs\Http\Handler;
use ApexDocs\Http\UiRenderer;

$handler = new Handler(
    $apexDocs,
    $psr17ResponseFactory,
    $psr17StreamFactory,
    new UiRenderer(),
);

// Mount at any path in your PSR-15 middleware stack
$app->get('/docs/{path:.*}', $handler);
```

The handler serves:

| Path | Response |
|------|----------|
| `/docs/` | Interactive UI |
| `/docs/spec.json` | OpenAPI JSON |
| `/docs/spec.yaml` | OpenAPI YAML |
| `/docs/postman` | Postman Collection download |
| `/docs/insomnia` | Insomnia export download |

---

## Custom Framework Bridge

Implement `RouteCollectionInterface` to support any router:

```php
use ApexDocs\Contract\RouteCollectionInterface;
use ApexDocs\Route\Route;

class MyFrameworkRouteCollection implements RouteCollectionInterface
{
    public function __construct(private MyRouter $router) {}

    public function all(): array
    {
        return array_map(
            fn ($r) => new Route(
                methods: $r->getMethods(),
                path:    $r->getUri(),
                handler: $r->getController(),
            ),
            $this->router->getRoutes()
        );
    }
}
```

Optionally implement `ValidationExtractorInterface` to extract request body schemas:

```php
use ApexDocs\Contract\ValidationExtractorInterface;
use ApexDocs\Route\Route;

class MyValidationExtractor implements ValidationExtractorInterface
{
    public function extract(\ReflectionMethod $handler, Route $route): ?array
    {
        // Return an OpenAPI requestBody object array, or null
        return [
            'content' => [
                'application/json' => [
                    'schema' => ['type' => 'object', 'properties' => [...]],
                ],
            ],
        ];
    }
}
```

Wire it all together:

```php
$doc = ApexDocs::make($config)
    ->routes(new MyFrameworkRouteCollection($router))
    ->validation(new MyValidationExtractor())
    ->generate();
```

---

## Customisation

### Filter Routes

```php
// Laravel — in a service provider or AppServiceProvider boot()
use ApexDocs\Facades\ApexDocs;

ApexDocs::filterRoutes(fn ($route) => str_starts_with($route->path, '/api/v2'));
```

### Document Transformer

Modify the entire OpenAPI document before it is returned:

```php
namespace App\OpenApi;

use ApexDocs\Contract\DocumentTransformerInterface;
use ApexDocs\Spec\Document;

class AddBuildMetaTransformer implements DocumentTransformerInterface
{
    public function transform(Document $document): Document
    {
        return $document->extend('x-build-sha', env('GIT_SHA', 'local'));
    }
}
```

Register in `config/apexdocs.php`:

```php
'document_transformers' => [
    \App\OpenApi\AddBuildMetaTransformer::class,
],
```

### Operation Transformer

Modify individual operations (endpoints):

```php
namespace App\OpenApi;

use ApexDocs\Contract\OperationTransformerInterface;
use ApexDocs\Spec\Operation;
use ApexDocs\Route\Route;

class AddOwnerTagTransformer implements OperationTransformerInterface
{
    public function transform(Operation $operation, Route $route): Operation
    {
        return $operation->extend('x-owner', 'backend-team');
    }
}
```

Register in `config/apexdocs.php`:

```php
'operation_transformers' => [
    \App\OpenApi\AddOwnerTagTransformer::class,
],
```

### Programmatic / Fluent API

```php
use ApexDocs\Facades\ApexDocs;

$doc = ApexDocs::make()
    ->filterRoutes(fn ($route) => ! str_contains($route->path, '/internal/'))
    ->transformDocument(fn ($doc) => $doc->extend('x-build', env('CI_COMMIT')))
    ->transformOperation(fn ($op, $route) => $op->extend('x-team', 'backend'))
    ->generate();
```

---

## UI Options

Five documentation UIs are supported. Change the default in config or switch live using the in-browser toolbar:

| Value | UI |
|-------|----|
| `scalar` | [Scalar](https://scalar.com) *(default)* |
| `swagger` | Swagger UI |
| `redoc` | ReDoc |
| `stoplight` | Stoplight Elements |
| `rapidoc` | RapiDoc |

```php
// config/apexdocs.php
'ui' => [
    'default'          => 'redoc',
    'show_ui_switcher' => true,
],
```

---

## Restricting Access

By default, docs are visible only in `local` and `staging` environments.

```php
// config/apexdocs.php

// Allow in production
'environments' => ['local', 'staging', 'production'],

// Require authentication
'middleware' => ['web', 'auth'],

// Require a specific role (with spatie/laravel-permission)
'middleware' => ['web', 'auth', 'role:developer'],
```

---

## Caching

Caching is **disabled in `local`** by default and enabled in all other environments.

```bash
# .env
APEXDOCS_CACHE_ENABLED=true
APEXDOCS_CACHE_DRIVER=redis
```

Or in config:

```php
'cache' => [
    'enabled' => true,
    'driver'  => 'redis',   // any Laravel cache driver
    'ttl'     => 3600,
],
```

---

## Architecture

```
┌──────────────────────────────────────────────────────────────┐
│  CORE  (zero framework dependencies)                         │
│                                                              │
│  ApexDocs  ←  Config                                         │
│      ↓                                                       │
│  SpecBuilder  ──→  OperationBuilder  ──→  Spec objects       │
│      ↓                  ↓                                    │
│  Extractors      AttributeReader, DocBlockReader,            │
│                  TypeInferrer, SchemaBuilder, ...            │
│                                                              │
│  Export:  JsonExporter · YamlExporter · PostmanExporter      │
│           InsomniaExporter                                   │
│                                                              │
│  Http:    PSR-15 Handler · UiRenderer (5 UIs, no templates)  │
│  Cache:   PSR-16 SpecCache                                   │
│  Routes:  Route value object · ArrayRouteCollection          │
│                                                              │
│  Contract interfaces:                                        │
│    RouteCollectionInterface                                  │
│    ValidationExtractorInterface                              │
│    SecurityDetectorInterface                                 │
│    DocumentTransformerInterface                              │
│    OperationTransformerInterface                             │
│                                                              │
├──────────────────────────────────────────────────────────────┤
│  BRIDGE / Laravel                                            │
│    ServiceProvider · Facade · DocsController                 │
│    RouteCollection (Illuminate → Route)                      │
│    ValidationExtractor (FormRequest → schema)                │
│    SecurityDetector (Sanctum / Passport / JWT)               │
│    RuleParser (Laravel rules → OpenAPI schema)               │
│    Console: generate · validate · export · diff · watch·mock │
├──────────────────────────────────────────────────────────────┤
│  BRIDGE / Symfony                                            │
│    RouteCollection (SymfonyRoute → Route)                    │
└──────────────────────────────────────────────────────────────┘
```

---

## License

MIT
