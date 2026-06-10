# Schemas & types  how PHP becomes JSON Schema

`SchemaBuilder` (with a `ComponentRegistry`) turns classes and type strings into OpenAPI 3.1 /
JSON Schema 2020-12. `maxDepth` = `responses.max_depth` (default 6, min 1).

## Type strings (`SchemaBuilder::fromTypeString`)

| PHP / PHPDoc | Schema |
|---|---|
| `void`, `never`, `mixed`, `''` | `{}` (no constraint)  for a return type this means **no inferred 200 content** |
| `null` | `{type: null}` |
| `bool`, `true`, `false` | `{type: boolean}` |
| `int` | `{type: integer}` |
| `float`, `double`, `number` | `{type: number, format: float}` |
| `string`, `class-string`, `non-empty-string` | `{type: string}` |
| `array`, `iterable`, `list` | `{type: array, items: {}}` |
| `object`, `stdClass` | `{type: object}` |
| `A&B` | `allOf` of members |
| `A\|B` | `oneOf` of members; `T\|null` → `type: [T, "null"]` (nullable handling) |
| `T[]` | `{type: array, items: <T>}` |
| `T{}` (internal marker from `array<string, T>`) | `{type: object, additionalProperties: <T>}` |
| class / interface / enum | component `$ref` |
| anything else | `{type: string}` |

Generics are normalised first (`TypeInferrer::normalise`):
`Collection<int, T>`, `Collection<T>`, `list<T>`, `array<int, T>`, `LengthAwarePaginator<T>`,
`CursorPaginator<T>`, `Paginator<T>`, `ResourceCollection<T>`, `AnonymousResourceCollection<T>`
→ `T[]`; `array<string, T>` → `T{}`; nested `array<int, array<int, T>>` → `T[][]`. An **unknown
generic wrapper** (`Foo<T>`) documents `Foo` itself. Leading `\` stripped; `(...)` trimmed.

Return type source order: `@return` PHPDoc → reflection return type. So `@return UserDto[]` on a
method declared `: array` produces an array of `$ref`. A return type that is not a class in your
app (`JsonResponse`, `Response`, `Illuminate\Http\Resources\Json\JsonResource`) is reflected as
a class with no useful public properties → `{type: object}`.

## Classes (`SchemaBuilder::fromClass`)

- **Enums**: `enum` of backing values (`type` integer if int-backed, else string); pure enums →
  case names.
- **Objects**: every **public** property declared on the class itself (parents handled below):
  - Type: reflection named type; if it is `array|iterable|mixed|object` **and** there is a `@var`
    (or `@param` on the promoted ctor param) annotation, the annotation wins (`@var Item[]`).
    Union/intersection/untyped properties use the annotation or `{}`.
  - Nullable (`?T`) → `type: [T, "null"]` (never `nullable: true`).
  - **Required** when: no default value (property or promoted ctor param) **and**
    (non-nullable, **or** nullable + `readonly`). Nullable mutable without default → optional.
  - No public properties → `{type: object}` with no `properties` (e.g. Eloquent models, Resources).
- **Inheritance**: if the parent class has public properties, is not PHP built-in and is not
  under `/vendor/`, the schema is `allOf: [$ref Parent, {own}]`.
- **`#[Schema]`** on the class merges `title`, `description`, `example`, `deprecated`, `externalDocs`.
- **Recursion**: a class already being built returns its `$ref`; depth ≥ `maxDepth` returns
  `{type: object}`; every class is registered once in `components/schemas` under its short name
  (`App\Dto\User` → `User`); two classes with the same short name get distinct names
  (`ComponentRegistry::reserve`). A user DTO named `ValidationError`, `PaginationMeta`,
  `PaginationLinks` or `UnauthorizedError` keeps its name and the standard one is not emitted.

## Standard components (emitted only when the config flag allows)

| Name | Kind | Flag |
|---|---|---|
| `schemas.ValidationError`, `responses.ValidationError` (422) | `{message, errors: {field: [string]}}` | `responses.include_validation_errors` |
| `schemas.UnauthorizedError`, `responses.Unauthorized` (401) | `{message}` | `responses.infer_error_responses` |
| `schemas.PaginationMeta`, `schemas.PaginationLinks` | Laravel paginator shape | `responses.include_pagination_meta` |
| `responses.TooManyRequests` (429, `Retry-After`, `X-RateLimit-*`) | | `rate_limits.enabled` |

## Laravel FormRequest → body schema (`RuleParser`)

Dotted keys nest (`address.city` → object property), `field.*` → `items`. Type inference from
rules: `integer|int|digits|digits_between` → integer; `numeric|decimal` → number;
`boolean|bool|accepted|declined` → boolean; `array|list` → array; else string.
`nullable` → `type: [T, "null"]`; `required` (and `required_*`? no  only plain `required`,
`present`, `filled`? see laravel.md) → `required[]`; `in:a,b` (and `Rule::in`, `Rule::enum`) → `enum`
cast to the type; `file|image|mimes|mimetypes` → `{type: string, format: binary}` and a second
`multipart/form-data` media type.

String constraints: `min/max/size` → `minLength/maxLength`; `regex:` → `pattern` (delimiters
stripped); `starts_with`/`ends_with` → pattern; `email`, `url|active_url` → `uri`, `uuid`,
`ulid` (pattern), `date` → `date-time`, `date_format:Y-m-d` → `date`, `ip|ipv4`, `ipv6`,
`mac_address`/`hex_color` (pattern), `json`, `password|current_password` → `format`.
Numeric: `min|gte` → minimum, `max|lte` → maximum, `gt`/`lt` → exclusive*, `multiple_of`,
`between:a,b`, `digits:n` → range. Rule objects are stringified only when `Stringable`; closures
and `ValidationRule` objects without `__toString` are ignored.
