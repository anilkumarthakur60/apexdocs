# ApexDocs

[![Latest Stable Version](https://img.shields.io/packagist/v/anil/apexdocs.svg?style=flat-square)](https://packagist.org/packages/anil/apexdocs)
[![Total Downloads](https://img.shields.io/packagist/dt/anil/apexdocs.svg?style=flat-square)](https://packagist.org/packages/anil/apexdocs)
[![PHP Version](https://img.shields.io/packagist/php-v/anil/apexdocs.svg?style=flat-square)](https://packagist.org/packages/anil/apexdocs)
[![License](https://img.shields.io/packagist/l/anil/apexdocs.svg?style=flat-square)](LICENSE)
[![Tests](https://img.shields.io/github/actions/workflow/status/anilkumarthakur60/apexdocs/tests.yml?branch=main&style=flat-square&label=tests)](https://github.com/anilkumarthakur60/apexdocs/actions/workflows/tests.yml)

**Framework-agnostic OpenAPI 3.1 documentation generator for PHP 8.2+.**

Zero framework dependencies in the core. Works with Laravel, Symfony, Slim, or any PHP project  bring your own route collection.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration (Laravel)](#configuration-laravel)
- [PHP Attributes](#php-attributes)
- [DTO Schemas](#dto-schemas)
- [Artisan Commands](#artisan-commands-laravel)
- [AI Assistants (Skills, Agents & MCP)](#ai-assistants-skills-agents--mcp)
- [Standalone Usage](#standalone-usage-no-framework)
- [Symfony](#symfony)
- [PSR-15 Frameworks](#psr-15-slim-mezzio-etc)
- [Custom Framework Bridge](#custom-framework-bridge)
- [Customisation](#customisation)
- [The Documentation UI](#the-documentation-ui)
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
- Serves interactive docs from a **native, CDN-free UI**  sidebar, command palette, try-it-out, code samples, zero outbound requests
- Exports to **Postman Collection v2.1**, **Insomnia**, and **Bruno**
- Breaking change detection, watch mode, and mock server
- **AI-assistant ready**: ships a skill, a subagent and an **MCP server** so Claude Code, Cursor, Copilot and Codex can inspect and improve the generated spec

---

## Requirements

- PHP 8.2+
- Laravel 11 or 12 (for the Laravel bridge)
- Symfony 6.4, 7, or 8 (for the Symfony bridge)

The core depends only on `phpstan/phpdoc-parser`, `symfony/yaml` (6.4 through
8), and the PSR interfaces for caching and HTTP messages  no framework.

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
| `/documentation/api/bruno` | Bruno collection |

No configuration is required. Routes with the `api` prefix are included automatically.

By default the docs are registered only in the `local` and `staging`
environments  see [Restricting Access](#restricting-access).

---

## Configuration (Laravel)

`config/apexdocs.php`  all options with their defaults:

```php
return [

    // API metadata. Read APP_NAME via env(): config files load alphabetically,
    // so config('app.name') is still null while this file is parsed.
    'info' => [
        'title'            => env('APEXDOCS_TITLE', env('APP_NAME', 'Laravel') . ' API'),
        'version'          => env('APEXDOCS_VERSION', '1.0.0'),
        'description'      => env('APEXDOCS_DESCRIPTION', ''),
        'contact'          => ['name' => '', 'email' => '', 'url' => ''],
        'license'          => ['name' => '', 'url' => ''],
        'terms_of_service' => '',
    ],

    // Only routes whose URI starts with these prefixes are documented.
    // String or array; an empty array documents every route.
    'api_path_prefix' => env('APEXDOCS_PATH_PREFIX', 'api'),

    // Glob or anchored-regex patterns; matching routes are skipped.
    // 'api/internal/*' (glob) or '.*internal.*' (regex)
    'exclude_paths' => [],

    // Only include routes tagged #[ApiGroup('name')] when set.
    'spec_group' => env('APEXDOCS_SPEC_GROUP', ''),

    // Server URLs. Empty → APP_URL is used.
    'servers' => [
        // ['url' => 'https://api.example.com', 'description' => 'Production'],
    ],

    'ui' => [
        'path'                     => env('APEXDOCS_PATH', 'documentation/api'),
        'show_toolbar'             => true,     // false hides the header bar
        'theme'                    => env('APEXDOCS_THEME', 'dark'),  // dark | light | auto
        'custom_logo'              => '',
        'custom_css'               => '',
        'announcement_banner'      => '',
        'announcement_banner_type' => 'info',   // info | warning | error
        'try_it_out'               => true,
        'default_language'         => 'curl',   // curl | js | python | php | go
    ],

    'security' => [
        // Auto-detect Sanctum / Passport / JWT from route middleware
        'auto_detect' => true,
        // Extra OpenAPI security scheme objects
        'schemes' => [
            // 'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
        ],
    ],

    'responses' => [
        'infer_error_responses'     => true,  // add 401 for auth-protected routes
        'include_validation_errors' => true,  // add 422 + ValidationError schema
        'include_pagination_meta'   => true,  // add meta/links to collections
        'max_depth'                 => 6,     // DTO recursion limit
    ],

    'rate_limits' => [
        'enabled' => true,  // add 429 + rate-limit headers for throttled routes
    ],

    // Directories scanned for classes carrying #[Webhook]
    'webhooks' => [
        'scan_paths' => [
            // app_path('Webhooks'),
        ],
    ],

    // Building the spec reflects every controller  cache it outside local dev.
    'cache' => [
        'enabled' => env('APEXDOCS_CACHE_ENABLED', env('APP_ENV', 'production') !== 'local'),
        'driver'  => env('APEXDOCS_CACHE_DRIVER'),   // any store from config/cache.php
        'ttl'     => (int) env('APEXDOCS_CACHE_TTL', 3600),
    ],

    // Middleware on the docs routes, and the environments they exist in.
    'middleware'   => ['web'],
    'environments' => ['local', 'staging'],

    'export' => [
        'default_path' => storage_path('apexdocs'),
    ],

    // Transformer class names (see Customisation)
    'document_transformers'  => [],
    'operation_transformers' => [],
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

### DTO Schemas

Response and request schemas are reflected from your classes. Public properties
and promoted constructor parameters become properties; `@var` / `@param`
annotations supply the element type where the PHP type cannot:

```php
use ApexDocs\Attribute\Schema;

#[Schema(description: 'A customer order')]
final class OrderDto
{
    /**
     * @param  OrderLineDto[]  $lines           // → array of $ref
     * @param  array<string, string>  $meta     // → object with additionalProperties
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $note,          // → type: ["string", "null"]
        public readonly OrderStatus $status,    // → enum from the backed enum
        public readonly array $lines = [],
        public readonly array $meta = [],
    ) {}
}
```

Each class is emitted once under `components/schemas` and referenced by `$ref`
everywhere else, including recursive and mutually recursive DTOs. Two classes
sharing a short name get distinct component names. Nesting is bounded by
`responses.max_depth`.

Return types are read the same way, with generics unwrapped:

```php
/** @return OrderDto[] */                          // array of $ref
/** @return Collection<int, OrderDto> */           // array of $ref
/** @return LengthAwarePaginator<OrderDto> */      // array of $ref
/** @return array<string, OrderDto> */             // object map of $ref
```

### API Resource Schemas

An API resource has no public properties - its keys live in `toArray()`. That
method is read **statically**: the class is never instantiated and the method
never called, so documenting a resource needs no model, request or database.

```php
/** @mixin \App\Models\User */                     // where the types come from
final class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => $this->id,             // → integer, from the model's @property
            'email'      => $this->email,          // → string
            'created_at' => $this->created_at,     // → string, format: date-time (Carbon)
            'is_active'  => (bool) $this->active,  // → boolean, from the cast
            'full_name'  => $this->first.' '.$this->last,   // → string, from the concatenation
            'avatar'     => $this->avatar ?? null, // → nullable
            'author'     => new AuthorResource($this->author),          // → $ref
            'posts'      => PostResource::collection($this->whenLoaded('posts')),
                                                   // → array of $ref, and *not* required
            'links'      => ['self' => $this->url] // → a nested object with its own keys
        ];
    }
}
```

The same applies to `jsonSerialize()` on a value object, to a
`ResourceCollection` (`$this->collection` becomes an array of whatever it
collects), and to `...parent::toArray($request)`, `$this->mergeWhen(…)`,
`array_merge(…)` and `array_filter(…)`.

A key is **required** unless it is conditional: `when…()`, `mergeWhen()`,
`array_filter()`, or absent from one of several `return` statements.

Types are read from the expression, never invented - a key nothing can be
learned about is published with no type at all. The exceptions are these naming
conventions, applied only when the expression yields nothing:

| Key | Type |
|---|---|
| `id`, `*_id` | `integer` |
| `*_at` | `string`, `format: date-time` |
| `count`, `*_count` | `integer` |
| `is_*`, `has_*`, `can_*` | `boolean` |
| `email`, `url`, `*_url`, `uuid` | `string` with the matching `format` |

To document a body too dynamic to read - or to override any of the above -
annotate the method. The annotation wins over everything:

```php
/** @return array{id: string, name: string, roles: string[], meta?: array{plan: string}} */
public function toArray($request): array
{
    return $this->resource->toApiPayload();        // unreadable, and it does not matter
}
```

A class with neither public properties nor a readable payload method falls back
to its `@property` / `@property-read` annotations, which is how an Eloquent
model describes its columns. Failing that, it stays `{type: object}` - the
schema says nothing rather than something wrong.

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
| `#[Group(name, description?)]` | Class | Tag all methods under a group (the description lands on the tag) |
| `#[Endpoint(summary, description?)]` | Method | Override summary/description |
| `#[Tag(name, description?)]` | Class, Method | Add OpenAPI tags (repeatable) |
| `#[Hidden]` | Class, Method | Exclude from spec |
| `#[Deprecated(message?, since?)]` | Class, Method | Mark as deprecated |
| `#[NoSecurity]` | Class, Method | Mark as public |
| `#[Security(scheme, scopes?)]` | Class, Method | Declare required security |
| `#[PathParam(name, type?, description?, example?, deprecated?)]` | Class, Method | Document path parameter |
| `#[QueryParam(name, type?, description?, required?, example?, enum?, deprecated?)]` | Class, Method | Document query parameter |
| `#[HeaderParam(name, type?, description?, required?, example?, deprecated?)]` | Class, Method | Document header parameter |
| `#[CookieParam(name, type?, description?, required?, example?, deprecated?)]` | Class, Method | Document cookie parameter |
| `#[ApiResponse(status, description?, resource?, collection?, schema?, headers?, examples?)]` | Method | Document a response |
| `#[Example(name, value, summary?, for?)]` | Method | Attach a request or response example |
| `#[BodyParam(name, type?, description?, required?, example?, enum?, format?, nullable?)]` | Class, Method | Document one body field |
| `#[RequestBody(class, description?, required?, contentType?)]` | Method | Build the body schema from a DTO |
| `#[ResponseHeader(name, type?, description?, example?, required?)]` | Class, Method | Document a response header |
| `#[Produces(contentType, description?, schema?)]` | Method | Override the success response media type |
| `#[Schema(title?, description?, example?, deprecated?, externalDocs?)]` | Class | Describe a DTO |
| `#[ExternalDocs(url, description?)]` | Class, Method | Link to external docs |
| `#[SunsetDate(date, migrationGuide?)]` | Class, Method | Planned removal date (adds a Sunset header) |
| `#[ApiGroup(name)]` | Class, Method | Assign to a named spec group |
| `#[Webhook(name, summary?, description?, schema?, tags?)]` | Class | Register as webhook |

Parameter attributes work on the class (applying to every action) as well as on
a single method; a method-level attribute wins where both define the same
parameter.

---

## Artisan Commands (Laravel)

### Generate

```bash
# Print JSON to stdout  nothing else goes to stdout, so redirection is safe
php artisan apexdocs:generate > public/openapi.json

# YAML format
php artisan apexdocs:generate --format=yaml

# Save to file (the summary then prints normally)
php artisan apexdocs:generate --output=public/openapi.json
```

### Validate

```bash
php artisan apexdocs:validate

# Fail the build on warnings too
php artisan apexdocs:validate --strict
```

Errors (exit code 1): missing `info.title`/`info.version`, no paths, a response
with no `description`, an invalid status key, duplicate `operationId`s, a path
template variable with no matching parameter, an unresolved `$ref`, and a
security requirement naming an undefined scheme.

Warnings: missing `operationId`, missing `summary`.

### Export

```bash
php artisan apexdocs:export openapi-json  --output=storage/apexdocs/spec.json
php artisan apexdocs:export openapi-yaml  --output=storage/apexdocs/spec.yaml
php artisan apexdocs:export postman       --output=storage/apexdocs/postman.json
php artisan apexdocs:export insomnia      --output=storage/apexdocs/insomnia.json
php artisan apexdocs:export bruno         --output=storage/apexdocs/bruno.json
```

Without `--output`, files land in `export.default_path`. An unknown format or a
failed write exits non-zero.

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

Each endpoint answers with an example built from its lowest documented 2xx
response, including any documented response headers. Append `?__status=404` to
any request to get a different documented response instead.

---

## AI Assistants (Skills, Agents & MCP)

Let AI coding agents work with the generated documentation instead of guessing at it. One
command installs a **skill** (how apexdocs works  every attribute, inference rule, config key),
a **subagent** (a documentation specialist), an instructions block for `CLAUDE.md` / `AGENTS.md`,
and registers the **MCP server**:

```bash
php artisan apexdocs:install-ai                 # Claude Code + AGENTS.md (default)
php artisan apexdocs:install-ai --target=all    # + Cursor + GitHub Copilot
```

The MCP server (`php artisan apexdocs:mcp`) rebuilds the spec **from the code on disk in a fresh
process on every call**, so an agent always sees the effect of its last edit:

| Tool | Purpose |
|---|---|
| `spec_summary` | counts, tags, servers, security schemes, how many routes were excluded and why |
| `list_routes` | every framework route with `included` and the exact exclusion reason (`api_path_prefix`, `exclude_paths`, `spec_group`, `filterRoutes`, `hidden`) |
| `list_operations` / `describe_operation` | operations (filter by tag/method/path/security) and the full Operation Object + source route |
| `list_schemas` / `get_schema` | `components/schemas` |
| `validate_spec` / `diff_spec` | the same rules as `apexdocs:validate` / `apexdocs:diff` |
| `export_spec` | write OpenAPI JSON/YAML, Postman, Insomnia or Bruno |
| `get_config` / `attribute_reference` | effective config; live reflection of every `#[Attribute]` |
| `read_reference` / `search_reference` | the bundled reference set (attributes, schemas & types, inference, config, commands, Laravel, Symfony, standalone, customisation, exports, validation & diff, testing) |

Non-Laravel projects get the same server from `vendor/bin/apexdocs-mcp --bootstrap=apexdocs.php`,
where `apexdocs.php` returns a configured `ApexDocs\ApexDocs` instance.

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

// …or fluently, with "Class@method" handlers:
$routes = (new ArrayRouteCollection)
    ->add('GET',  '/api/users',      UserController::class . '@index')
    ->add('POST', '/api/users',      UserController::class . '@store')
    ->add('GET',  '/api/users/{id}', UserController::class . '@show');

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

Register the bundle in `config/bundles.php`:

```php
return [
    // …
    ApexDocs\Bridge\Symfony\ApexDocsBundle::class => ['all' => true],
];
```

Configure it in `config/packages/apex_docs.yaml`:

```yaml
apex_docs:
    info:
        title: My API
        version: '2.0.0'
    api_path_prefix: api
    ui:
        default: apex
    responses:
        max_depth: 6
```

The bundle registers `ApexDocs\ApexDocs` as a public service, wired to Symfony's
router, `#[MapRequestPayload]` bodies, and `#[IsGranted]` security. It does not
register a docs route  mount the PSR-15 handler (below) or write a thin
controller:

```php
use ApexDocs\ApexDocs;
use ApexDocs\Http\SpecPayload;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DocsController
{
    public function __construct(private ApexDocs $apexDocs) {}

    #[Route('/documentation/api/spec.json')]
    public function spec(): Response
    {
        $payload = SpecPayload::json($this->apexDocs);

        return new Response($payload->body, 200, ['Content-Type' => $payload->contentType]);
    }
}
```

Or use it standalone, with no container:

```php
use ApexDocs\ApexDocs;
use ApexDocs\Config;
use ApexDocs\Bridge\Symfony\RouteCollection;

$doc = ApexDocs::make(Config::fromArray(['title' => 'My API']))
    ->routes(new RouteCollection($symfonyRouter))
    ->generate();

echo $doc->toJson();
```

Inline route requirements are understood: `/users/{id<\d+>}` is documented as
`/users/{id}` with an integer parameter.

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
| `/docs/bruno` | Bruno collection download |

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
                methods:  $r->getMethods(),          // ['GET']  case-insensitive
                path:     $r->getUri(),              // /api/users/{id}
                handler:  $r->getController(),       // "Class@method", "Class", or [Class::class, 'method']
                metadata: ['name' => $r->getName()], // optional: also 'wheres' for param constraints
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

ApexDocs values are immutable: every fluent call returns a new instance. In
Laravel, extend the container binding so the filter survives:

```php
// AppServiceProvider::boot()
use ApexDocs\ApexDocs;

$this->app->extend(ApexDocs::class, fn (ApexDocs $docs) => $docs->filterRoutes(
    fn ($route) => str_starts_with($route->path, '/api/v2'),
));
```

### Document Transformer

Modify the entire OpenAPI document before it is returned:

```php
namespace App\OpenApi;

use ApexDocs\Contract\DocumentTransformerInterface;
use ApexDocs\Spec\Document;

class AddBuildMetaTransformer implements DocumentTransformerInterface
{
    // Mutate in place and return nothing  the document is passed by handle.
    public function transform(Document $document): void
    {
        $document->extend('x-build-sha', env('GIT_SHA', 'local'));
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

class AddOwnerTagTransformer implements OperationTransformerInterface
{
    public function transform(Operation $operation): void
    {
        $operation->extend('x-owner', 'backend-team');
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
use ApexDocs\ApexDocs;

$doc = ApexDocs::make()
    ->routes($routeCollection)
    ->filterRoutes(fn ($route) => ! str_contains($route->path, '/internal/'))
    ->transformDocument(fn ($doc) => $doc->extend('x-build', env('CI_COMMIT')))
    ->transformOperation(fn ($op, $route) => $op->extend('x-team', 'backend'))
    ->generate();
```

Closure transformers receive the `Operation` and, for operation transformers,
the `ApexDocs\Route\Route` it came from. Class-based transformers implement the
interfaces above, which take the spec object alone.

Inside Laravel the same thing, via the facade  note that each call returns a
new instance, so the chain must end in `generate()`:

```php
use ApexDocs; // the facade alias registered by the service provider

$doc = ApexDocs::transformDocument(fn ($doc) => $doc->extend('x-build', 'abc'))->generate();
```

---

## The Documentation UI

There is one UI, rendered entirely by PHP: a sidebar endpoint tree, a command
palette, schema browser, code samples in five languages, and try-it-out. It
makes **no outbound request**  no CDN script, no web font, no remote
stylesheet  so it works behind a strict CSP and on an air-gapped host.

`ui.theme` takes `dark`, `light` or `auto`; `auto` tracks the operating system
preference. The theme switches instantly through CSS custom properties. A
`?theme=dark|light|auto` query parameter overrides it for one page load, which
is what you want when linking someone to the docs from a light-themed app.

```php
// config/apexdocs.php
'ui' => [
    'show_toolbar' => true,     // false hides the header bar entirely
    'theme'        => 'dark',   // dark | light | auto
    'custom_css'   => '.axi-path { font-weight: 600 }',
    'try_it_out'   => true,
],
```

---

## Restricting Access

The docs routes are registered only in the environments you list. The default
keeps your API surface out of production:

```php
// config/apexdocs.php

// Default  no docs routes exist at all outside these environments
'environments' => ['local', 'staging'],

// Allow in production too (then put real auth in front of them)
'environments' => ['local', 'staging', 'production'],

// Every environment
'environments' => [],

// Require authentication
'middleware' => ['web', 'auth'],

// Require a specific role (with spatie/laravel-permission)
'middleware' => ['web', 'auth', 'role:developer'],
```

The gate applies to the HTTP routes only  `php artisan apexdocs:generate` and
the other commands work in every environment, so CI can still build the spec.

---

## Caching

Building the spec reflects every controller, DTO, and FormRequest in the
application, so the result is cached everywhere except `local`.

```bash
# .env
APEXDOCS_CACHE_ENABLED=true
APEXDOCS_CACHE_DRIVER=redis
APEXDOCS_CACHE_TTL=3600
```

Or in config:

```php
'cache' => [
    'enabled' => true,
    'driver'  => 'redis',   // any store name from config/cache.php; null = default
    'ttl'     => 3600,
],
```

The cache holds the serialised document, so `/spec.json`, `/spec.yaml`, and every
export share one build. Clear it after a deploy:

```php
app(\ApexDocs\Cache\SpecCache::class)->forget();
```

Outside Laravel, wire any PSR-16 cache yourself:

```php
use ApexDocs\Cache\SpecCache;

$cache = new SpecCache($psr16, ttl: 3600);
$doc   = $cache->get() ?? tap($apexDocs->generate(), fn ($d) => $cache->put('default', $d));
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
│           InsomniaExporter · BrunoExporter · SchemaExample    │
│                                                              │
│  Http:    PSR-15 Handler · SpecPayload                       │
│           UiRenderer (native UI, no templates)               │
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
│    ApexDocsBundle · ApexDocsExtension (container wiring)      │
│    RouteCollection (SymfonyRoute → Route)                    │
│    ValidationExtractor (#[MapRequestPayload] → schema)        │
│    SecurityDetector (#[IsGranted] → bearer)                  │
└──────────────────────────────────────────────────────────────┘
```

---

## License

MIT
