---
name: apexdocs
description: Complete guide to anil/apexdocs, the framework-agnostic OpenAPI 3.1 generator for PHP 8.2+ (Laravel, Symfony, PSR-15, standalone). Covers every PHP attribute (#[Endpoint], #[ApiResponse], #[QueryParam], #[RequestBody], #[Schema], #[Security], #[Hidden], #[Webhook]…), how DTOs/return types/FormRequest rules become JSON Schema, what is inferred without attributes, every config key, the artisan commands (generate, validate, export, diff, watch, mock), transformers, exports (Postman/Insomnia/Bruno), the docs UI, caching, and the exact validation/diff rules. Use whenever a task touches API documentation, an OpenAPI spec, a controller/DTO/FormRequest in a project that has this package, an `apexdocs` config key, or a docs endpoint under /documentation/api.
---

# apexdocs

Generates an **OpenAPI 3.1** document from routes + code. Nothing is hand-written: the route
collection gives paths/methods, reflection gives parameters/bodies/responses, PHP attributes
(`ApexDocs\Attribute\*`) fill in what code cannot say. Core has zero framework deps; bridges
exist for Laravel (auto-discovered) and Symfony (bundle); a PSR-15 handler serves any other stack.

Everything here is derived from the package source. Live facts (what *this* app's spec contains
right now, why a route is missing) come from the `apexdocs` MCP server  prefer it over guessing.

## Reference map

| Area | File | Covers |
|---|---|---|
| Attributes | `references/attributes.md` | all 22 attributes: signature, target, repeatable, precedence, exact emitted output |
| Schemas & types | `references/schemas-and-types.md` | DTO reflection rules, API resource `toArray()`/`jsonSerialize()` shape reading, `@property`/`@mixin`, required-ness, `@var`/`@param`, generics unwrapping, enums, nullability, allOf inheritance, max_depth, component naming |
| Inference | `references/inference.md` | operationId, tags, summary/description, path params, request body sources & order, responses (200/401/422/429), security detection |
| Config | `references/config.md` | every key of `config/apexdocs.php`, the Symfony tree, `Config` object + `fromArray`/`with` |
| Commands | `references/commands.md` | `apexdocs:generate|validate|export|diff|watch|mock|mcp|install-ai|snapshot`, exit codes, stdout hygiene |
| Laravel bridge | `references/laravel.md` | service bindings, docs routes & names, environments/middleware gate, cache, facade immutability, FormRequest rule → schema table, security detection table |
| Symfony bridge | `references/symfony.md` | bundle, config tree, services, `#[MapRequestPayload]`, `#[IsGranted]`, route requirements |
| Standalone / PSR-15 | `references/standalone.md` | `ApexDocs`, `Config`, `Route`, `ArrayRouteCollection`, `Handler`, `SpecPayload`, the five contracts |
| Customisation | `references/customisation.md` | transformers (class & closure), `filterRoutes`, `webhook()`, `Document`/`Operation`/`Components` API, `x-` extensions |
| Exports & HTTP | `references/exports-and-http.md` | exporters, download endpoints, UI options, theme override, `SpecCache` |
| Validation & diff | `references/validation-and-diff.md` | every validator message with cause & fix; every diff classification |
| Testing | `references/testing.md` | Pest recipes against the generated document |

## Golden rules

1. **The code is the source of truth.** Never hand-edit or commit generated `openapi.json` as the
   canonical spec. Fix the controller, DTO, FormRequest, route or config, then regenerate.
2. **Inference first, attributes second.** Check what the generator already derives (PHPDoc summary,
   return type, FormRequest rules, `auth`/`throttle` middleware)  add attributes only for what it
   cannot know: non-200 responses, query params, examples, deprecation, hiding, explicit security.
3. **Type your DTOs.** Public typed properties / promoted ctor params + `@var Item[]` produce exact
   schemas; return `Dto[]`, `Collection<int, Dto>`, `LengthAwarePaginator<Dto>` for lists.
   Untyped `array` → `{type: array, items: {}}`.
   An **API resource** is read from its `toArray()` (or `jsonSerialize()`) without being run, so
   its keys are documented already; give the resource a `@mixin \App\Models\X` — or the model
   `@property` annotations — and the keys get real types too. `@return array{…}` on the payload
   method overrides everything and is the fix for a body too dynamic to read.
4. **`ApexDocs` is immutable** — `routes()`, `filterRoutes()`, `transformDocument()`… return a
   *new* instance. In Laravel: `app()->extend(ApexDocs::class, fn ($d) => $d->filterRoutes(...))`
   or chain straight into `->generate()`.
5. **Route selection is layered**: `api_path_prefix` → `exclude_paths` → `spec_group` →
   `filterRoutes()` → `#[Hidden]`. A missing endpoint is always one of these  `list_routes`
   tells you which.
6. **Docs routes exist only in `environments`** (default `local`, `staging`) and behind
   `middleware` (default `web`); artisan commands work everywhere. The docs paths themselves are
   auto-excluded from the spec.
7. **Cache** is on outside `local`  after changing code on staging/production run
   `app(SpecCache::class)->forget()` or the spec is stale for `cache.ttl` seconds.
8. Run `php artisan apexdocs:validate --strict` after every documentation change; CI can `apexdocs:diff`
   against a committed baseline to catch breaking changes.

## Workflow: document an endpoint

1. `describe_operation` (MCP) or `apexdocs:generate | jq '.paths["/api/users/{id}"]'`  see what exists.
2. Make the action's **return type** precise (`UserDto`, `@return UserDto[]`); type-hint the
   **FormRequest** for writes (rules → body schema, 422 added automatically).
3. Add attributes:
   ```php
   #[Endpoint(summary: 'Show a user', description: 'Returns one user by id.')]
   #[QueryParam('include', description: 'Comma-separated relations', example: 'roles,teams')]
   #[ApiResponse(200, description: 'User', resource: UserDto::class)]
   #[ApiResponse(404, description: 'User not found')]
   #[Example('typical', value: ['id' => 1, 'name' => 'Ada'])]
   public function show(int $id): UserDto { … }
   ```
4. Controller-wide: `#[Group('Users', 'User management')]` (tag + description), class-level
   `#[Security('sanctum')]`, `#[HeaderParam('X-Tenant-ID', required: true)]`.
5. `validate_spec` strict → fix → done. Add a Pest assertion (`references/testing.md`).

## Attribute cheat-sheet (targets: C = class, M = method, R = repeatable)

| Attribute | Target | Emits |
|---|---|---|
| `Group(name, description='')` | C | tag on every method (unless `Tag` present) + tag description |
| `Tag(name, description='')` | C M R | operation tags (method-level replaces class-level) |
| `Endpoint(summary='', description='')` | M | summary/description (beats PHPDoc) |
| `Hidden` | C M | operation omitted |
| `Deprecated(message='', since='')` | C M | `deprecated: true`, `x-deprecation-notice`, `x-deprecated-since` |
| `SunsetDate(date, migrationGuide='')` | C M | `x-sunset-date`, `x-migration-guide`, `Sunset` response header |
| `ExternalDocs(url, description='')` | C M | `externalDocs` |
| `ApiGroup(name)` | C M R | route kept only when `spec_group` matches (or is empty) |
| `Security(scheme, scopes=[])` | C M R | `security: [{scheme: scopes}]` (several = OR; method beats class) |
| `NoSecurity` | C M | `security: []` (public) |
| `PathParam(name, type='string', description='', example=null, deprecated=false)` | C M R | path parameter (must exist in the template) |
| `QueryParam(name, type='string', description='', required=false, example=null, enum=null, deprecated=false)` | C M R | query parameter |
| `HeaderParam(name, type='string', description='', required=false, example=null, deprecated=false)` | C M R | header parameter |
| `CookieParam(...)` same as HeaderParam | C M R | cookie parameter |
| `BodyParam(name, type='string', description='', required=false, example=null, enum=null, format='', nullable=false)` | C M R | one JSON body property (all merged into one object) |
| `RequestBody(class, description='', required=true, contentType='application/json')` | M | body schema from a DTO  beats BodyParam and FormRequest |
| `ApiResponse(status=200, description='', resource=null, collection=false, schema=null, headers=[], examples=[])` | M R | response; `resource` → `{data: $ref}` (collection adds `meta`/`links`) |
| `Example(name, value, summary='', for='response')` | M R | named example on the success response or the request body (`for: 'request'`) |
| `ResponseHeader(name, type='string', description='', example=null, required=false)` | C M R | header on the success response |
| `Produces(contentType, description='', schema=[])` | M R | replaces success media type(s) |
| `Schema(title='', description='', example=null, deprecated=false, externalDocs=[])` | C | metadata on a DTO component |
| `Webhook(name, summary='', description='', schema=null, tags=[])` | C R | `webhooks.{name}.post` (needs `webhooks.scan_paths`) |

Full semantics in `references/attributes.md`.

## Debugging checklist

| Symptom | Check |
|---|---|
| Endpoint missing | `list_routes` → `reason`: prefix (`api_path_prefix`), `exclude_paths`, `spec_group`/`#[ApiGroup]`, `filterRoutes`, `#[Hidden]`, or only HEAD/OPTIONS/PROPFIND verbs |
| No request body | Method not POST/PUT/PATCH; FormRequest not type-hinted; `rules()` throws / has required params / returns `[]`; use `#[RequestBody]`/`#[BodyParam]` |
| Response is bare `{type: object}` | Return type untyped or `JsonResponse`; add `@return Dto` or `#[ApiResponse(resource:)]`. For a resource: no own `toArray()` (the inherited one is in `/vendor/`), or its body is unreadable — annotate it `@return array{…}` |
| List documented as `items: {}` | `@return array` without element type — write `@return Dto[]` |
| Property missing from schema | Not public; or `max_depth` reached (nested object collapses to `{type: object}`) |
| Resource key present but untyped (`{}`) | Nothing in the expression said anything — add `@mixin`/`@property` to the model, a cast, or `@return array{…}` |
| Resource key wrongly `required` | It is unconditional in the literal; wrap it in `when…()`, or mark it optional in `@return array{key?: T}` |
| Property wrongly required | No default + non-nullable → required; readonly nullable → required; add a default or make mutable |
| Security wrong / 401 missing | `security.auto_detect`; middleware contains `auth`/`sanctum`/`passport`/`jwt`? override with `#[Security]`/`#[NoSecurity]` |
| `Security requirement 'x' has no matching securityScheme` | scheme not in `security.schemes` and not auto-detected (package not installed) |
| Duplicate operationId | Unnamed routes with punctuation-only differences or same route name for several verbs  name routes distinctly |
| Path template `{x}` has no parameter | `#[PathParam]` name differs from the template; template uses characters the router didn't map |
| Docs 404 in production | `environments` excludes it (by design); add env + auth middleware |
| Spec stale | Cache on (`cache.enabled`)  `SpecCache::forget()` / `APEXDOCS_CACHE_ENABLED=false` locally |
| `InvalidConfigException … not a class that exists` | Transformer class name typo in `document_transformers`/`operation_transformers` |
| Symfony boot fails on `ui.default` | Removed key  use `ui.show_toolbar`/`ui.theme` (see UPGRADING) |
