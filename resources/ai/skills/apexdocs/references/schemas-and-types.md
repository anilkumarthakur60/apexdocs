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
| `numeric-string`, `literal-string`, `class-string`, … | `{type: string}` |
| `positive-int`, `non-negative-int`, `int<a, b>`, … | `{type: integer}` |
| `array-key` | `{type: ["string","integer"]}` |
| anything else — a name that resolves to nothing, `callable`, `resource` | `{}` (no constraint), and **no inferred 200 content** |

Generics are normalised first (`TypeInferrer::normalise`):
`Collection<int, T>`, `Collection<T>`, `list<T>`, `array<int, T>`, `LengthAwarePaginator<T>`,
`CursorPaginator<T>`, `Paginator<T>`, `ResourceCollection<T>`, `AnonymousResourceCollection<T>`
→ `T[]`; `array<string, T>` → `T{}`; nested `array<int, array<int, T>>` → `T[][]`. An **unknown
generic wrapper** (`Foo<T>`) documents `Foo` itself. Leading `\` stripped; `(...)` trimmed.

Return type source order: `@return` PHPDoc → reflection return type. So `@return UserDto[]` on a
method declared `: array` produces an array of `$ref`. A return type that is not a class in your
app (`JsonResponse`, `Response`) is reflected as a class with no useful public properties and no
payload method → `{type: object}`.

Type strings from PHPDoc are resolved against the **file's own `use` statements** first
(`NameResolver`), so `@return UserResource` finds the imported class instead of falling through to
the last row. Reflection types are already qualified; `self`/`static`/`$this` resolve to the
declaring class.

Classes that never become a component:

| Class | Schema | Why |
|---|---|---|
| `DateTimeInterface` (Carbon included) | `{type: string, format: date-time}` | every serialiser emits ISO-8601 |
| a collection/paginator with no generic (`TypeInferrer::ITERABLE_GENERICS`, and it must really be `Traversable`) | `{type: array, items: {}}` | the same reading `Collection<T>` gets |
| a response wrapper — `Symfony\…\HttpFoundation\Response` and every subclass (`JsonResponse`, `RedirectResponse`, `StreamedResponse`), PSR-7 `ResponseInterface`, `Illuminate\Contracts\View\View` | `{}` → no content | it carries the payload, it is not the payload: reflected, `JsonResponse` published `{original, exception}` |
| the bare abstract bases, matched **exactly**: `JsonResource`, `ResourceCollection`, Eloquent `Model`, `Relation`, `Builder` | `{}` → no content | a subclass keeps its schema; only the base has nothing to say |

Static properties are never payload keys.

## Classes (`SchemaBuilder::fromClass`)

Source order: payload method (`jsonSerialize()` then `toArray()`) → own public properties →
`@property` annotations → `{type: object}`.

- **Enums**: `enum` of backing values (`type` integer if int-backed, else string); pure enums →
  case names.
- **Payload methods** (`ArrayShapeReader`): a `jsonSerialize()` or `toArray()` **declared outside
  `/vendor/`** wins over the property list — the keys of an API resource are in the method, and its
  public surface (`$collects`, `$resource`) is plumbing. `@return array{…}` on that method beats
  its body and is the escape hatch for an unreadable one. Details in the section below.
- **Objects**: every **public** property declared on the class itself (parents handled below):
  - Type: reflection named type; if it is `array|iterable|mixed|object` **and** there is a `@var`
    (or `@param` on the promoted ctor param) annotation, the annotation wins (`@var Item[]`).
    Union/intersection/untyped properties use the annotation or `{}`.
  - Nullable (`?T`) → `type: [T, "null"]` (never `nullable: true`).
  - **Required** when: no default value (property or promoted ctor param) **and**
    (non-nullable, **or** nullable + `readonly`). Nullable mutable without default → optional.
  - Nothing public, no payload method → `@property`/`@property-read` annotations if any (the
    Eloquent-model case), else `{type: object}` with no `properties`.
- **Inheritance**: if the parent class has public properties, is not PHP built-in and is not
  under `/vendor/`, the schema is `allOf: [$ref Parent, {own}]`.
- **`#[Schema]`** on the class merges `title`, `description`, `example`, `deprecated`, `externalDocs`.
- **Recursion**: a class already being built returns its `$ref`; depth ≥ `maxDepth` returns
  `{type: object}`; every class is registered once in `components/schemas` under its short name
  (`App\Dto\User` → `User`); two classes with the same short name get distinct names
  (`ComponentRegistry::reserve`). A user DTO named `ValidationError`, `PaginationMeta`,
  `PaginationLinks` or `UnauthorizedError` keeps its name and the standard one is not emitted.

## Payload methods → object shape (`ArrayShapeReader`)

Read **statically** from the method's own lines (`TokenScanner` over `token_get_all`): the class is
never instantiated, the method never called, nothing is autoloaded but types. Methods declared in
`/vendor/`, abstract methods and internal classes are skipped, so every `JsonResource` subclass
that does *not* write its own `toArray()` stays `{type: object}`.

Per `return` statement, the array literal (also inside `array_merge()`, `array_replace()`,
`array_filter()`). Several `return`s merge; a key missing from one becomes optional. Keys must be
literal strings — `self::FIELD => …` is skipped; a numeric-keyed literal is a list, not an object.

| Value expression | Result |
|---|---|
| `(bool)`/`(int)`/`(float)`/`(string)`/`(array)`/`(object)` cast | that type |
| `'x'`, `12`, `1.5`, `true`, `-1`, `"…{$x}"`, heredoc, `$a.$b` | scalar type from the literal |
| `[...]` with string keys / with none | nested object / array of the first element |
| `new PostResource(…)` | `$ref` |
| `PostResource::collection(…)`, `::collect(…)` | array of `$ref` |
| `PostResource::make(…)`, `::create/from/fromModel(…)` | `$ref` |
| `self::CONST`, `Status::Active`, `Foo::class` | the constant's value type / enum `$ref` / string |
| `$a ?? $b`, `$c ? $a : $b`, `$a ?: $b` | the first branch that types; `null` branch → nullable |
| `count()`, `sprintf()`, `implode()`, `array_map()`, … | `ArrayShapeReader::FUNCTION_TYPES` |
| `…->toIso8601String()`, `->count()`, `->pluck()`, `->format()`, … | `ArrayShapeReader::METHOD_TYPES` (`date-time` where it applies) |
| `$this->attr`, `$this->rel->attr`, `$this->resource->attr` | reflection property type → `@var` → `@property`/`@property-read` (up the hierarchy) → the model's **`$casts`** / **`casts()`** / **`$keyType`** → `@mixin` target; `$this->resource` *is* the `@mixin` target |
| `$this->method()` | declared class return type, else `METHOD_TYPES` |
| `$this->collection` (a `ResourceCollection`) | array of `$collects`, else `FooCollection` → `Foo`/`FooResource` |
| anything else | key with **no type** — never a guessed one |

`optional` (i.e. left out of `required`): `when()`, `unless()`, `whenLoaded()`, `whenNull()`,
`whenNotNull()`, `whenAppended()`, `whenCounted()`, `whenAggregated()`, `whenExistsLoaded()`,
`whenHas()`, `whenPivotLoaded()`, `whenPivotLoadedAs()`, `mergeWhen()`, `mergeUnless()` —
including nested inside `Resource::collection(…)` — plus every key
of an `array_filter()` payload and any key missing from one `return` branch. The value argument of
a conditional still types the key.

Keys contributed without a `=>`: `...parent::toArray($request)`, `...$this->fields()` (recursed as
another payload method), `...[...]`, `$this->merge([...])`, `$this->mergeWhen($c, [...])`,
`$this->mergeUnless($c, [...])`.

Last-resort naming conventions, applied **only** when the expression yielded no type
(`ArrayShapeReader::fromKeyName`): `id`/`*_id` → integer; `*_at` → string `date-time`;
`count`/`*_count` → integer; `is_*`/`has_*`/`can_*` → boolean; `email` → string `email`;
`url`/`*_url` → string `uri`; `uuid` → string `uuid`.

Cast vocabulary (read from `$casts` or the `casts()` literal — no model is constructed):
`bool`/`boolean` → boolean; `int`/`integer`/`timestamp` → integer; `real`/`float`/`double` →
number; `decimal:n`/`string`/`hashed` → **string** (Eloquent formats a decimal and returns a
string); `array`/`json`/`collection` → array; `object` → object; `date` → `date`;
`datetime`/`immutable_datetime`/`custom_datetime` → `date-time`; `encrypted:<x>` → whatever `<x>`
is; an enum-class cast → that enum's schema; `AsCollection`/`AsArrayObject`/… → array;
`AsStringable` → string; a custom `CastsAttributes` class → no type. `$keyType` types the
`$primaryKey` attribute, which is how a UUID model overrides the `id` → integer convention.

Nesting obeys `responses.max_depth`; the reader itself stops at 5 levels of literal/chain.

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
