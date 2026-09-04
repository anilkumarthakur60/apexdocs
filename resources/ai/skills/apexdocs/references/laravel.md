# Laravel bridge (`ApexDocs\Bridge\Laravel`)

Auto-discovered `ServiceProvider` (+ facade alias `ApexDocs`). Requires Laravel 12 or 13.

## Container bindings (all singletons unless noted)

| Abstract | Concrete |
|---|---|
| `ApexDocs\Config` | `Config::fromArray(config('apexdocs'))` + `APP_URL` server when `servers` empty + the six docs URLs appended to `exclude_paths` |
| `RouteCollectionInterface` | `RouteCollection(Router)`  every route except HEAD/OPTIONS-only; handler from `uses`/`controller` (closures → `''`); middleware via `gatherMiddleware()` (class names for objects); metadata `name`, `wheres`, `domain` |
| `ValidationExtractorInterface` | `ValidationExtractor(new RuleParser)` |
| `SecurityDetectorInterface` | `SecurityDetector` |
| `ApexDocs\ApexDocs` (alias `apexdocs`) | `make(Config)->routes()->validation()->security()` |
| `SpecCache` | PSR-16 store `cache.driver` (or default), ttl `cache.ttl`, prefix `apexdocs.` |
| exporters, `UiRenderer` | bound (not singletons) |
| `SpecValidator`, `SpecDiff` | auto-resolved (no deps) |

## Docs routes (only when `app()->environment(config('apexdocs.environments'))`, and never when routes are cached)

| Name | URI (`ui.path` = `documentation/api`) | Content |
|---|---|---|
| `apexdocs.ui` | `/documentation/api` | HTML UI (`?theme=dark\|light\|auto`) |
| `apexdocs.json` | `/spec.json` | `application/json` |
| `apexdocs.yaml` | `/spec.yaml` | `application/yaml` |
| `apexdocs.postman` | `/postman` | download `postman.json` |
| `apexdocs.insomnia` | `/insomnia` | download |
| `apexdocs.bruno` | `/bruno` | download |

Group middleware: `config('apexdocs.middleware')` (default `['web']`). `DocsController::document()`
uses `SpecCache` when `cache.enabled`; a failing cache store falls back to a live build.

## Facade & immutability

`ApexDocs::generate()`, `::filterRoutes()`, `::transformDocument()`… proxy the singleton, but the
fluent methods return **new instances**. `ApexDocs::filterRoutes($f);` alone does nothing 
either chain `->generate()` or rebind:

```php
// AppServiceProvider::boot()
$this->app->extend(\ApexDocs\ApexDocs::class, fn ($docs) => $docs
    ->filterRoutes(fn ($route) => ! str_contains($route->path, '/internal/'))
    ->transformOperation(fn ($op, $route) => $op->extend('x-team', 'backend')));
```

## FormRequest extraction

The first action parameter whose type is a `FormRequest` subclass is used. `rules()` is invoked
on `newInstanceWithoutConstructor()` with `setContainer(app())`, errors suppressed, any
`Throwable` → no body. `rules()` with required parameters → skipped. `authorize()` and
`validate()` are never called. Results are cached per class per process.

Body = `{required: <any required>, content: {application/json: {schema}}}` plus
`multipart/form-data` when any rule matches `file|image|mimes|mimetypes`. Required fields:
top-level names carrying the `required` rule (nested objects computed by the parser).

Tips for accurate bodies: keep `rules()` free of request/route access (`$this->route('id')`,
`$this->user()`) or guard them with null-safe fallbacks; use `Rule::enum(Status::class)` or
`in:` for enums; add `nullable` explicitly; use `date_format:Y-m-d` for date-only fields.

## API resources → response schemas

A `JsonResource` / `ResourceCollection` subclass is documented from the `toArray()` it declares,
read statically - never instantiated, never called, no model or request needed. Full expression
table in `schemas-and-types.md`; the Laravel-specific parts:

- **Types come from the model.** Put `@mixin \App\Models\User` on the resource and `@property`
  annotations on the model (ide-helper writes them) and `$this->email` types itself.
  `$this->resource` *is* the mixin target, so `$this->resource->email` resolves too.
- **`whenLoaded()`, `when()`, `mergeWhen()`, `whenNotNull()`, `whenCounted()`, `whenAppended()`**
  make the key optional (they return `MissingValue`), including inside
  `PostResource::collection($this->whenLoaded('posts'))`. Their value argument still types the key.
- **`PostResource::collection(…)`** → array of `$ref`; **`new PostResource(…)`** / `::make(…)` →
  `$ref`. Both register the nested resource as its own component.
- **`ResourceCollection`**: `$this->collection` → an array of `$collects`, else Laravel's own
  convention (`UserCollection` → `User`, then `UserResource`). A redeclared `public $collects` is
  never mistaken for a payload key.
- **A resource that does not declare `toArray()`** keeps `{type: object}`: the inherited one lives
  in `/vendor/` and describes nothing.
- **Carbon** attributes are `{type: string, format: date-time}`, not a `Carbon` component.
- **`$this->wrap`, `additional()`, `withResponse()`** are not read. `#[ApiResponse(resource:)]`
  supplies the `{data: …}` envelope (`collection: true` adds `meta`/`links`).
- Escape hatch: `@return array{id: int, name: string, meta?: array{plan: string}}` on `toArray()`
  wins over the body.

## Security detection (`SecurityDetector`)

| Middleware string contains | Scheme (when package present → else) |
|---|---|
| `jwt` | `jwt` → `bearerAuth` |
| `passport`, `oauth`, `client_credentials` | `passport` → `bearerAuth` |
| `auth`, `auth:*`, `auth.*`, `*authenticate*` + `sanctum` | `sanctum` → `bearerAuth` |
| other `auth*` | first detected scheme, else `bearerAuth` |
| `authorize`, `can:` etc. | none (not authentication) |

Package presence = class exists: `Laravel\Sanctum\SanctumServiceProvider`,
`Laravel\Passport\PassportServiceProvider`, `PHPOpenSourceSaver\JWTAuth\...` /
`Tymon\JWTAuth\...`. Override per route with `#[Security]`/`#[NoSecurity]`; add API-key or
custom schemes under `security.schemes` and reference them with `#[Security('apiKey')]`.

## Restricting access

```php
'environments' => ['local', 'staging', 'production'],
'middleware'   => ['web', 'auth', 'can:view-api-docs'],   // or 'role:developer' (Spatie)
```

## Cache invalidation

```php
app(\ApexDocs\Cache\SpecCache::class)->forget();   // in a deploy hook / after migrations
```
