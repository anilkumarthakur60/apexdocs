# Attributes  complete reference (`ApexDocs\Attribute`)

All are `final` classes with `public readonly` constructor properties. Read with
`AttributeReader::all|first|has`. Precedence rule everywhere: **method-level beats class-level**;
for repeatable parameter attributes, method + class entries are merged and a method entry
overwrites the fields of a class entry with the same `in:name`.

## Metadata

### `#[Group(string $name, string $description = '')]`  class
Default tag for every method of the controller when no `#[Tag]` is present; `description` becomes
the document-level tag description. Without `Group`/`Tag`, the tag is the class short name minus
`Controller` (`UserController` → `Users`? no: → `User`), or `General` when empty. Closure routes are
tagged by the first non-version path segment (`/api/v1/users` → `Users`).

### `#[Tag(string $name, string $description = '')]`  class, method, repeatable
Operation `tags`. Method tags **replace** class tags (not merge). Unique, order preserved.

### `#[Endpoint(string $summary = '', string $description = '')]`  method
Overrides PHPDoc. Without it: summary = first non-tag PHPDoc line; description = lines between
summary and first `@tag` (markdown kept).

### `#[Hidden]`  class, method
Operation is not built at all (route stays in `list_routes` with reason `hidden`).

### `#[Deprecated(string $message = '', string $since = '')]`  class, method
`deprecated: true`; `x-deprecation-notice`, `x-deprecated-since` (extensions are prefixed `x-` by
`Operation::extend`).

### `#[SunsetDate(string $date, string $migrationGuide = '')]`  class, method
`x-sunset-date`, `x-migration-guide`, plus a `Sunset` header on the success response
(RFC 8594, `example: $date`). Does not set `deprecated` by itself  pair with `#[Deprecated]`.

### `#[ExternalDocs(string $url, string $description = '')]`  class, method
`externalDocs: {url, description}` (method beats class).

### `#[ApiGroup(string $name)]`  class, method, repeatable
Only consulted when `spec_group` config is non-empty: routes with no `ApiGroup` are always
included; routes with one or more are included only if any name equals `spec_group`. Lets one
codebase publish several specs (`APEXDOCS_SPEC_GROUP=partner`).

## Security

### `#[Security(string $scheme, array $scopes = [])]`  class, method, repeatable
`security: [{scheme: scopes}, …]`. Several attributes at the same level are **alternatives (OR)**.
Method-level list replaces class-level list. The scheme must exist in `components.securitySchemes`
(configured under `security.schemes` or auto-detected) or validation fails.

### `#[NoSecurity]`  class, method
`security: []`  overrides everything, including detected middleware.

Precedence: `NoSecurity` → `Security` (method, else class) → detector (`auth*` middleware / `#[IsGranted]`).

## Parameters

Shared behaviour: `type` is normalised by `JsonType::normalize` (`int`→`integer`, `bool`→`boolean`,
`float|double|decimal`→`number`, `text|str|date|datetime|uuid|binary|file`→`string`, `list`→`array`,
`map|dict`→`object`, `mixed`→`string`); `type: array` gets `items: {type: string}`; empty `name`
is dropped; `example`, `description`, `deprecated` emitted only when set.

### `#[PathParam(string $name, string $type = 'string', string $description = '', mixed $example = null, bool $deprecated = false)]`  class, method, repeatable
Always `required: true`. **Dropped if `{name}` is not in the path template** (so a class-level
PathParam only applies to routes that have it). Without the attribute, path params are still
documented from the template: type from the action's scalar type-hint (`int $id` → integer) →
router constraint (`\d+` → integer; `a|b` → enum; other → `pattern`) → name heuristic (`id`,
`*_id`, `*Id` → integer, else string); description from `@param $name …`; `{name?}` adds
"(optional segment …)" to the description.

### `#[QueryParam(string $name, string $type = 'string', string $description = '', bool $required = false, mixed $example = null, ?array $enum = null, bool $deprecated = false)]`  class, method, repeatable
`in: query`. `enum` → `schema.enum`. Nothing is inferred for query params  always declare them.

### `#[HeaderParam(...)]`, `#[CookieParam(...)]`  same signature minus `enum`
`in: header` / `in: cookie`.

## Request body

Sources, first match wins, evaluated per operation:
1. `#[RequestBody]` (any verb)
2. `#[BodyParam]` set (class + method merged, any verb)
3. framework validation extractor  **POST/PUT/PATCH only** (FormRequest / MapRequestPayload)

### `#[RequestBody(string $class, string $description = '', bool $required = true, string $contentType = 'application/json')]`  method
`requestBody.content.{contentType}.schema` = `SchemaBuilder::fromClass($class)` (a `$ref`).

### `#[BodyParam(string $name, string $type = 'string', string $description = '', bool $required = false, mixed $example = null, ?array $enum = null, string $format = '', bool $nullable = false)]`  class, method, repeatable
Each becomes one property of one `application/json` object schema; `nullable` → `type: [T, "null"]`;
`required` names collected into `schema.required`; `requestBody.required` = any required.

### `#[Example(string $name, array $value, string $summary = '', string $for = 'response')]`  method, repeatable
`for: 'request'` → `requestBody.content.<json or first media type>.examples.{name}`;
`for: 'response'` → success response `content.<media>.examples.{name}`. Values wrapped as
`{value, summary?}`. Creates a `{type: object}` content entry if the response had none.

## Responses

### `#[ApiResponse(int $status = 200, string $description = '', ?string $resource = null, bool $collection = false, ?array $schema = null, array $headers = [], array $examples = [])]`  method, repeatable
Key = status (100-599; anything else → `default`). `description` defaults to the reason phrase
(`OK`, `Created`, `Not Found`, …, else `Response`). Content precedence: `schema` (inline, as given)
→ `resource` (`{type: object, properties: {data: <schema of class>}}`; `collection: true` →
`data: {type: array, items: …}` plus `meta: $ref PaginationMeta`, `links: $ref PaginationLinks`
when `responses.include_pagination_meta`) → none. `headers`: `'X-Foo' => 'integer'` or a full
Header Object. `examples`: raw values auto-wrapped in `{value: …}`. Declaring any 2xx suppresses
the inferred 200.

### `#[ResponseHeader(string $name, string $type = 'string', string $description = '', mixed $example = null, bool $required = false)]`  class, method, repeatable
Header on the **first 2xx** response; existing headers from `ApiResponse(headers:)` win on conflict.

### `#[Produces(string $contentType, string $description = '', array $schema = [])]`  method, repeatable
Replaces the success response `content` with the listed media types. `schema` defaults to the
schema the JSON response already had, else `{type: string, format: binary}` (downloads).
`description` replaces the response description only when it is still the default `OK`.

## Schemas

### `#[Schema(string $title = '', string $description = '', mixed $example = null, bool $deprecated = false, array $externalDocs = [])]`  class
Merged onto the DTO's component schema. See `schemas-and-types.md`.

### `#[Webhook(string $name, string $summary = '', string $description = '', ?array $schema = null, array $tags = [])]`  class, repeatable
Found by scanning `webhooks.scan_paths` (directories; file → class by namespace + class regex).
Emits `webhooks.{name}: {post: {summary (default class short name), description?, tags?,
requestBody: {required: true, content: application/json: schema ?? {type: object}},
responses: {200: {description: 'Webhook received'}}}}`. Programmatic alternative:
`$apexDocs->webhook('name', ['post' => …])`.
