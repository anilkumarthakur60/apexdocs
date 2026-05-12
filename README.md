# ⚡ ApexDocs

**Framework-agnostic OpenAPI 3.1 documentation generator for PHP 8.2+.**

Zero framework dependencies in the core. Works with Laravel, Symfony, Slim, or any PHP project — bring your own route collection.

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
│    Console: generate · validate · export · diff · watch · mock│
├──────────────────────────────────────────────────────────────┤
│  BRIDGE / Symfony                                            │
│    RouteCollection (SymfonyRoute → Route)                    │
└──────────────────────────────────────────────────────────────┘
```

---

## Why not Scramble?

| | ApexDocs | Scramble |
|---|---|---|
| Framework-agnostic core | ✅ | ❌ (Laravel only) |
| `spatie/laravel-package-tools` | ❌ not used | ✅ required |
| `illuminate/*` in core | ❌ none | ✅ required |
| PSR-15 handler | ✅ | ❌ |
| PSR-16 cache | ✅ | ❌ |
| Symfony bridge | ✅ | ❌ |
| 5 UI options | ✅ | 1 |
| Postman + Insomnia export | ✅ | ❌ |
| YAML export | ✅ | ❌ |
| API diff + breaking-change detection | ✅ | ❌ |
| Watch mode | ✅ | ❌ |
| Mock server | ✅ | ❌ |
| `#[Deprecated]`, `#[NoSecurity]`, `#[Webhook]` | ✅ | ❌ |
| Standard error schemas as reusable `$ref` | ✅ | partial |

---

## Installation

```bash
composer require apexdocs/apexdocs
```

### Laravel (auto-discovered)

```bash
php artisan vendor:publish --tag=apexdocs-config
```

Visit `/docs/api`.

---

## Standalone usage (no framework)

```php
use ApexDocs\ApexDocs;
use ApexDocs\Config;
use ApexDocs\Route\ArrayRouteCollection;

$routes = new ArrayRouteCollection();
$routes->add('GET',  '/api/users',     'App\UserController@index');
$routes->add('POST', '/api/users',     'App\UserController@store');
$routes->add('GET',  '/api/users/{id}','App\UserController@show');

$doc = ApexDocs::make(new Config(title: 'My API', version: '2.0.0'))
    ->routes($routes)
    ->generate();

echo $doc->toJson();
```

---

## Slim / any PSR-7 framework

```php
use ApexDocs\Http\Handler;
use ApexDocs\Http\UiRenderer;
use Nyholm\Psr7\Factory\Psr17Factory;

$factory = new Psr17Factory();
$handler = new Handler($apexDocs, $factory, $factory, new UiRenderer());

// Mount on any PSR-15 compatible router
$app->get('/docs/{path:.*}', $handler);
```

---

## PHP 8.2 Attributes

```php
use ApexDocs\Attribute\{Group, Endpoint, Tag, Hidden, Deprecated, NoSecurity};
use ApexDocs\Attribute\{ApiResponse, QueryParam, PathParam, HeaderParam, CookieParam};
use ApexDocs\Attribute\{Security, Webhook, Example};

#[Group('Users')]
class UserController
{
    #[Endpoint('List users', 'Returns paginated list.')]
    #[QueryParam('search', description: 'Name filter')]
    #[QueryParam('per_page', type: 'integer', example: 15)]
    #[ApiResponse(200, resource: UserResource::class, collection: true)]
    public function index(): UserCollection { ... }

    #[ApiResponse(200, resource: UserResource::class)]
    #[ApiResponse(404, 'User not found')]
    public function show(User $user): UserResource { ... }

    #[ApiResponse(201, resource: UserResource::class)]
    public function store(StoreUserRequest $request): UserResource { ... }

    #[Deprecated('Use /v2/users instead')]
    public function legacyList(): JsonResponse { ... }

    #[Hidden]
    public function internalAdmin(): JsonResponse { ... }

    #[NoSecurity]
    public function publicStats(): JsonResponse { ... }

    #[Security('passport', ['read:users'])]
    public function adminIndex(): UserCollection { ... }
}

// Webhook events
#[Webhook('payment.completed', summary: 'Payment done',
    schema: ['type' => 'object', 'properties' => ['amount' => ['type' => 'number']]])]
class PaymentCompletedEvent { ... }
```

---

## Programmatic builder (fluent API)

```php
use ApexDocs\Facades\ApexDocs;  // Laravel
use ApexDocs\ApexDocs;           // Standalone

$docs = ApexDocs::make()
    ->routes($routeCollection)
    ->validation($validationExtractor)      // optional — framework bridge
    ->security($securityDetector)           // optional — framework bridge
    ->filterRoutes(fn ($route) => str_starts_with($route->path, '/api/v2'))
    ->transformDocument(fn ($doc) => $doc->extend('x-build', getenv('CI_COMMIT')))
    ->transformOperation(fn ($op) => $op->extend('x-team', 'backend'))
    ->webhook('user.registered', ['post' => ['summary' => 'New user']]);
    ->generate();
```

---

## Artisan commands (Laravel bridge)

```bash
php artisan apexdocs:generate                     # Print spec to stdout
php artisan apexdocs:generate --format=yaml       # YAML
php artisan apexdocs:generate --output=docs/api.json

php artisan apexdocs:validate                     # Lint the spec

php artisan apexdocs:export postman               # Postman Collection
php artisan apexdocs:export insomnia
php artisan apexdocs:export openapi-yaml --output=docs/openapi.yaml

php artisan apexdocs:diff storage/apexdocs/baseline.json   # Breaking-change detection

php artisan apexdocs:watch                        # Hot-reload on file change
php artisan apexdocs:mock --port=8081             # Mock server
```

---

## Implement your own framework bridge

```php
// 1. Implement RouteCollectionInterface
class MyFrameworkRoutes implements RouteCollectionInterface {
    public function all(): array {
        return array_map(fn ($r) => new Route(
            methods:  $r->getMethods(),
            path:     $r->getUri(),
            handler:  $r->getController(),
        ), $this->router->getRoutes());
    }
}

// 2. Optionally implement ValidationExtractorInterface
class MyValidationExtractor implements ValidationExtractorInterface {
    public function extract(ReflectionMethod $handler, Route $route): ?array { ... }
}

// 3. Build
$doc = ApexDocs::make($config)
    ->routes(new MyFrameworkRoutes($router))
    ->validation(new MyValidationExtractor())
    ->generate();
```

---

## License

MIT
