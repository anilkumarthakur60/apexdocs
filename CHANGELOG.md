# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Model-informed resource keys.** A key that reads a model attribute is typed
  from what the model *declares*: its `$casts` map, the Laravel 11 `casts()`
  method (both read statically — no model is constructed, no database touched),
  and `$keyType` for the primary key, which is how a UUID model says its `id` is
  a string where the naming convention would have guessed an integer. Casts map
  as Eloquent casts them, including `decimal:` → string and an enum-class cast →
  that enum's schema. Duck-typed on the declarations, so it costs nothing
  outside Laravel. Also typed now: `__()`/`trans()`, and the collection
  pipelines (`map`, `filter`, `pluck`, `flatten`, …).
- **A `$ref` is now a link.** Every reference in a schema — a property, an
  array's items, a response or request body, a `oneOf` branch — navigates to
  that component's own view, and an array of references names its item type
  (`CarrierResource[]`) where it used to say only `array`. Derived from the ref
  itself, so it holds for any schema; linked only when the component is really
  published, so a dangling reference cannot become a dead link. The reverse
  direction, a schema's "Used by" list, is now links too — it was a
  `<div onclick>`, so it was unreachable by keyboard.
- **A documentation UI that can be used with a keyboard.** Measured before:
  211 endpoint rows in the navigation and 34 group headers, none reachable by
  Tab; response accordions unreachable; no `:focus-visible` rule anywhere, so
  focus was invisible; no `h1`. The navigation is now real `<a href>` links
  routed by a `hashchange` listener — which also makes Back and Forward walk the
  endpoints visited, and lets an endpoint be opened in a new tab — every
  disclosure control is a `<button>` reporting `aria-expanded`/`aria-controls`,
  each view names itself with an `h1`, the active row carries
  `aria-current="page"`, and there is one focus ring, keyboard-only, drawn from
  the existing `--ring` token.
- **API resource schemas.** A class whose payload is assembled by a method —
  every Laravel API resource, every `JsonSerializable` value object — now
  publishes its keys instead of an empty `{type: object}`. `toArray()` /
  `jsonSerialize()` is read *statically* (`ApexDocs\Extractor\ArrayShapeReader`
  over `ApexDocs\Extractor\TokenScanner`): the class is never instantiated and
  the method never called, so no model, request, container or database is
  involved. What is read from a key's expression:
  - casts, scalar literals, string concatenation and interpolation, `??`, `?:`
    and ternaries, class constants and enum cases;
  - `new PostResource(…)` and `PostResource::collection(…)` → `$ref` and array
    of `$ref`, recursion included;
  - nested array literals → nested objects; `$this->collection` in a
    `ResourceCollection` → an array of whatever it collects (`$collects`, else
    Laravel's own naming convention);
  - `$this->attribute` typed through reflection, `@var`, `@property` /
    `@property-read` and `@mixin` — so a resource over an annotated model gets
    real types;
  - `array_merge()` / `array_replace()` / `array_filter()` around the returned
    literal, `...parent::toArray($request)` and `...$this->fields()` spreads,
    and `$this->merge…()`.

  A key is `required` unless it is conditional (`when…()`, `mergeWhen()`,
  `array_filter()`, or missing from one of several `return` statements). A key
  whose expression yields no evidence is published *without* a type rather than
  with a guessed one, except for the naming conventions listed in the README.
  `@return array{…}` on the payload method overrides everything and is the
  escape hatch for a body too dynamic to read.
- **`@property` / `@property-read` annotations** are read for any class with no
  public property and no payload method — how an Eloquent model documents its
  columns. New: `DocBlockReader::propertyTypes()`, `DocBlockReader::mixins()`,
  and `ApexDocs\Extractor\NameResolver`, which resolves the short class names in
  a file against its `use` statements.

- **AI assistant integration.** `php artisan apexdocs:install-ai` installs an
  `apexdocs` skill (a `SKILL.md` plus a source-derived reference set covering
  every attribute, the schema/type rules, everything the generator infers,
  every config key, all commands, the Laravel and Symfony bridges, standalone
  use, customisation, exports, and the exact validation/diff rules), a Claude
  Code subagent, a managed instructions block (`CLAUDE.md`, `AGENTS.md`,
  `.cursor/rules`, `.github/copilot-instructions.md`) and MCP registration
  (`.mcp.json`, `.cursor/mcp.json`, `.vscode/mcp.json`). Targets `claude`,
  `agents` (default both), `cursor`, `copilot`, `all`; idempotent. Also
  `vendor:publish --tag=apexdocs-ai`.
- **MCP server.** `php artisan apexdocs:mcp` (Laravel) and
  `vendor/bin/apexdocs-mcp --bootstrap=file.php` (any framework) serve a
  dependency-free Model Context Protocol server over stdio. Snapshots are
  built in a fresh PHP process per call (`apexdocs:snapshot`, hidden) so the
  agent always sees the code on disk; `--in-process` trades that for speed.
  Tools: `spec_summary`, `list_operations`, `describe_operation`,
  `list_routes` (with the reason a route is excluded), `list_schemas`,
  `get_schema`, `validate_spec`, `diff_spec`, `export_spec`, `get_config`,
  `attribute_reference`, `read_reference`, `search_reference`; resources
  `apexdocs://spec.json`, `apexdocs://config`, one per reference topic;
  prompts `document-endpoint`, `fix-validation`, `missing-endpoint`.
- `ApexDocs\Validation\SpecValidator`, `ApexDocs\Diff\SpecDiff` and
  `ApexDocs\Generator\RouteSelector`  the logic behind `apexdocs:validate`,
  `apexdocs:diff` and route selection, now public framework-agnostic classes
  (the commands delegate to them). `RouteSelector::exclusionReason()` explains
  why a route is left out.
- `ApexDocs::getRouteCollection()` and `ApexDocs::getRouteFilter()`.

### Removed

- **The five CDN-backed documentation UIs** (Scalar, Swagger UI, ReDoc,
  Stoplight Elements, RapiDoc) and the whole multi-UI system with them. The
  native UI is now the only UI. Each backend pulled a bundle from a third-party
  CDN, forced `script-src`/`style-src` to name that host, and brought its own UX
  conventions  five ways to expand a schema, five keyboard maps, five ideas of
  what "try it out" means. The page now issues **zero outbound requests** in
  every state, which is the property the rest of the UI work depends on.
- `?ui=` is no longer read anywhere. `?theme=dark|light|auto` stays  it is a
  supported deep-link override and is covered by tests.
- Removed public API: `UiRenderer::UIS`, `UiRenderer::normalizeUi()`,
  `Theme::isDark()`, the `$ui` first argument of `UiRenderer::render()`, and the
  `Config::$defaultUi` property.
- Removed configuration: `ui.default`, the `APEXDOCS_UI` environment variable,
  and `ui.show_ui_switcher` (renamed  see below).
- Removed the `1`…`6` "switch UI backend" keyboard shortcut and its row in the
  shortcuts dialog.

### Changed

- `ui.show_ui_switcher` → `ui.show_toolbar` (`Config::$showUiSwitcher` →
  `Config::$showToolbar`). The flag hides the entire header bar, not a tab
  strip, so the old name described something that no longer existed.

### Fixed

- **Nearly half of a real spec's response bodies were wrong.** Measured on a
  205-operation Laravel app: 95 operations documented their 200 as
  `$ref: JsonResponse` — that is, `{original, exception}`, the internals of
  Illuminate's response class — and 45 more documented a JSON object as
  `type: string`. Three causes, all fixed:
  - A response *wrapper* is no longer treated as a payload (matched by
    inheritance, so one rule covers `JsonResponse`, `RedirectResponse`,
    `StreamedResponse`, PSR-7 responses and views). Nor is the bare abstract
    base a payload class extends — `JsonResource`, `ResourceCollection`,
    Eloquent's `Model` — matched exactly, so subclasses keep their schemas.
  - **PHPDoc types are resolved against the file's own `use` statements.**
    `@return Response` and `@return UserResource` matched no class before, so
    every unqualified annotation in a namespaced app fell through to the
    fallback type. This is also what now links 9 endpoints in that app straight
    to their resource schemas.
  - The fallback for a name that resolves to nothing is `{}` — no constraint —
    instead of `{type: string}`, and for a return type that means no invented
    200 content. PHPStan's scalar refinements (`numeric-string`,
    `positive-int`, …) are recognised explicitly rather than caught by it.
- A **static property** was published as a payload key (`JsonResource::$wrap`
  reached response schemas this way). Class state cannot appear in an instance's
  JSON.
- **A 401, 422 or 429 row in the UI expanded onto nothing.** Those three are
  emitted as Response Object `$ref`s (`#/components/responses/ValidationError`),
  and the operation renderer read the response object raw: no `description`, no
  `content`, so the accordion body came out empty and
  `.ax-resp-body:empty{display:none}` hid it — a control that visibly did
  nothing when clicked. Responses, parameters and the request body of an
  operation are now resolved through `components` before rendering (a shallow
  copy — the cached spec the JSON view shows is left alone), so a `$ref`-ed
  request body reaches the code samples and the try-it form too.
- **The documentation page was browser-cacheable.** It inlines the whole UI —
  stylesheet and script — and is rebuilt from source per request, so a cached
  copy pinned the reader to whichever UI they first loaded, with no symptom and
  nothing to invalidate. It now sends `Cache-Control: no-store`, like the spec
  endpoints it fetches from.
- **The documentation column used a third of the space it had.** Above 1200px
  the article is a grid item, and the `margin-inline:auto` that centres it in
  flow cancels a grid item's stretch and sizes the box to fit-content: it
  rendered 592px wide inside a 1216px cell — narrower than even its own 900px
  measure — and all the slack showed as dead space between the documentation and
  the request console (312px of it at 1920px, 632px at 2560px). In rail mode the
  article now fills its cell, which the console already bounds:
  `#axui-doc{grid-area:1/1;max-width:none;margin-inline:0}`. `--doc-max` still
  applies below the threshold, where the viewport is the only bound.
- **A description never reflowed.** `md()` turned every newline into `<br>`, so
  a PHPDoc hard-wrapped at 80 columns kept the source's line ends at every
  viewport — short ragged lines in a wide column, and double-wrapped ones on a
  phone. A single newline is now a soft break, as in every markdown renderer;
  markdown's own hard break (two trailing spaces, or a trailing backslash) still
  emits `<br>`.
- Clicking a resource under **Schemas** in the documentation UI showed
  `object {}` with no keys. The schema behind it was `{type: object}`: an API
  resource has no public properties, and nothing read the `toArray()` that
  builds the payload. See *API resource schemas* above.
- A `DateTimeInterface` property (`Carbon`, `DateTimeImmutable`, …) was
  reflected as a class, producing an empty `Carbon` component schema and a
  `$ref` to it. It is now `{type: string, format: date-time}`, which is what
  every serialiser actually emits, and no component is published for it.
- A collection or paginator written without its generic (`Collection`, not
  `Collection<Post>`) became an empty object component. It is now
  `{type: array, items: {}}` — the same reading `Collection<T>` already got.
- The command palette navigated to `?ui=apex#op_…`, so picking a result reloaded
  the page, wrote a dead parameter into the URL bar and history, and dropped any
  `?theme=` override on the way. It now resolves the hash in place, exactly like
  a sidebar click, and the deep-link theme survives.
- The `t` shortcut toggled the theme by clicking the toolbar button, so it did
  nothing when `ui.show_toolbar` was `false`  the one configuration where it is
  the only way to switch. It now calls the theme cycle directly.

### Internal

- `UiRenderer::css()` and `::js()` were each a single ~520 / ~1160-line heredoc.
  They are now assembled from `cssShell/cssNav/cssDoc/cssSchema/cssPanel/`
  `cssResponsive` and `jsCore/jsChrome/jsNav/jsIndex/jsDoc/jsSchema/jsPanel/`
  `jsInit`. Order is load-bearing in both cases. The split itself changed no
  emitted byte beyond the deletions above and one replacement:
  `.apex-right{margin-left:auto}` took over the spacing the deleted
  `.apex-tabs-wrap{flex:1}` used to provide.

See [UPGRADING.md](UPGRADING.md) for the migration steps.

## [0.1.0] - 2026-07-26

Initial public release.

ApexDocs generates OpenAPI 3.1 documents from PHP source: routes, controller
signatures, PHP 8 attributes, PHPDoc annotations, and framework validation
rules. The core has no framework dependency  Laravel and Symfony are bridges
over five small interfaces.

### Generating

- **OpenAPI 3.1 output**, JSON or YAML. Nullability uses JSON Schema type
  arrays (`["string","null"]`), not the 3.0 `nullable` keyword.
- **DTO reflection** into `components/schemas`, referenced by `$ref` everywhere
  it appears. Handles public properties, promoted constructor parameters,
  readonly and defaulted properties, backed and pure enums, inheritance via
  `allOf`, and recursive or mutually recursive types. Depth-bounded by
  `responses.max_depth`.
- **PHPDoc types**, with generics unwrapped: `Collection<int, User>`,
  `LengthAwarePaginator<User>`, and `User[]` all become an array of `$ref`;
  `array<string, User>` becomes an object with `additionalProperties`. `@var`
  and `@param` supply element types where the PHP type cannot.
- **Path parameters** typed from the handler signature, then from router
  constraints, then from the parameter name; described from `@param` tags.
- **Response inference** from return types, with 401 / 422 / 429 added from
  route middleware, each configurable.

### Attributes

`#[Group]`, `#[Tag]`, `#[Endpoint]`, `#[Hidden]`, `#[Deprecated]`,
`#[SunsetDate]`, `#[ExternalDocs]`, `#[ApiGroup]`, `#[Schema]`, `#[Security]`,
`#[NoSecurity]`, `#[PathParam]`, `#[QueryParam]`, `#[HeaderParam]`,
`#[CookieParam]`, `#[BodyParam]`, `#[RequestBody]`, `#[ApiResponse]`,
`#[ResponseHeader]`, `#[Produces]`, `#[Example]`, `#[Webhook]`. Parameter
attributes work at class or method level, with method-level winning.

### Serving

- **Six documentation UIs**: a native renderer with a sidebar, command palette,
  try-it-out panel, and code samples that needs no CDN, plus Scalar, Swagger UI,
  ReDoc, Stoplight Elements, and RapiDoc. Switchable live from the toolbar.
- **A real light theme.** Both modes define the same ~75 colour tokens from one
  source (`ApexDocs\Http\Theme`), so the explicit choice and the
  `prefers-color-scheme` fallback cannot drift. Every token that carries text is
  contrast-checked against the surface it sits on, and the suite fails if a pair
  drops below the WCAG AA ratio of 4.5:1  method badges, JSON syntax
  highlighting, status pills, and property badges all pass in both themes.
  Raised surfaces (cards) and recessed ones (code blocks, inputs) are separate
  tokens, so light mode reads as layered rather than flat.
- **The third-party UIs follow the theme too**: Scalar, ReDoc, and RapiDoc are
  configured for the active palette, and the Swagger overrides are scoped to the
  dark selector so light mode keeps Swagger's own theme. Switching theme while a
  CDN-backed UI is on screen reloads it for the new palette, since those bundles
  cannot re-theme after init.
- **Logo, custom CSS, and announcement banner** are configurable.
- **PSR-15 handler** for Slim, Mezzio, or any PSR-7 stack, serving the UI, the
  spec, and every export from one mount point.
- **Exports**: OpenAPI JSON/YAML, Postman Collection v2.1, Insomnia v4, and
  Bruno  with request bodies generated from the referenced schemas and auth
  mapped from the declared security scheme type.

### Laravel bridge

- Auto-discovered service provider, facade, docs routes, and a publishable
  config file.
- **FormRequest rules → request-body schema.** Dotted and wildcard keys
  (`author.name`, `items.*.sku`) build nested structures; ~40 rules map onto
  JSON Schema keywords. Rules that carry no documentable metadata  closures,
  `ValidationRule` objects  are skipped rather than crashing the build.
- **Sanctum / Passport / JWT detection** from route middleware. Every scheme an
  operation requires is guaranteed to be declared in the document.
- **Spec caching** through any PSR-16 store, shared across the spec routes and
  every export.
- **Environment gating**: the docs routes are registered only in
  `apexdocs.environments` (default `local` and `staging`), so a public API spec
  stays off production unless you opt in.
- **Artisan commands**: `apexdocs:generate` (clean stdout, so redirection
  works), `apexdocs:validate` (`--strict` for CI), `apexdocs:export`,
  `apexdocs:diff` for breaking-change detection, `apexdocs:watch`, and
  `apexdocs:mock`  a mock server answering with examples from the spec.

### Symfony bridge

- Bundle with a full config tree, wiring the router, `#[MapRequestPayload]`
  bodies, and `#[IsGranted]` security.
- Inline route requirements (`/users/{id<\d+>}`) are normalised to valid path
  templates and used to type the parameter.

### Extending

- `RouteCollectionInterface`, `ValidationExtractorInterface`, and
  `SecurityDetectorInterface` to support any router or validation layer.
- `DocumentTransformerInterface` / `OperationTransformerInterface`, or plain
  closures, to post-process the document. Registerable fluently or via config.
- `ApexDocs\Exception\ApexDocsException` as a marker for every error the
  package throws.

### Requirements

PHP 8.2+. Laravel 11 or 12 for the Laravel bridge; Symfony 6.4, 7, or 8 for the
Symfony bridge. Runtime dependencies are `phpstan/phpdoc-parser`,
`symfony/yaml` (6.4 through 8), and the PSR cache / HTTP-message /
HTTP-server-handler interfaces.

### Tested

265 Pest tests, 753 assertions, across PHP 8.2–8.4 and Laravel 11–12,
including an OpenAPI 3.1 structural conformance suite, a WCAG contrast check over
both themes, and a hostile-input sweep asserting the generator never emits an
invalid document or throws into the host application.

[Unreleased]: https://github.com/anilkumarthakur60/apexdocs/compare/0.1.0...HEAD
[0.1.0]:      https://github.com/anilkumarthakur60/apexdocs/releases/tag/0.1.0
