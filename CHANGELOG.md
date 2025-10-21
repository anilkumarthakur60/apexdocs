# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Native Apex UI: persistent auth token (per-spec localStorage), rich response
  viewer with Body/Headers/Raw tabs, request history with restore, Models /
  schema browser tab, response examples switcher, deprecation + sunset banner,
  expand-all/collapse-all on schema sections, property badges (required,
  nullable, readOnly, writeOnly, deprecated, format, default, ranges, regex),
  keyboard-shortcuts dialog (`?`), `j`/`k` next/prev navigation, mobile-friendly
  off-canvas sidebar, OAuth2 implicit-flow helper, and full markdown rendering
  for descriptions.
- Bulk-JSON edit toggle on the Try-it-out Path / Query / Headers groups —
  paste a full JSON object to populate all fields, copy out to clipboard, or
  refresh the JSON from current field values.
- Empty-state and error-state UIs when the spec fails to load or contains no
  endpoints, with a retry action.
- Exception hierarchy under `ApexDocs\Exception\*` (`ApexDocsException` marker
  interface plus typed runtime exceptions).
- `LICENSE`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, `UPGRADING.md`,
  `.editorconfig`, and a GitHub Actions workflow running Pest on PHP 8.2 / 8.3 /
  8.4 against Laravel 11 / 12.
- Symfony `ApexDocsBundle` with DI configuration — `composer require` is now
  enough to wire the Symfony bridge.
- `tests/Feature/` smoke test suite plus unit coverage for the host-header
  fix, OpenAPI 3.1 nullable encoding, and `SchemaBuilder::asNullable`.

### Changed

- **Security:** `SpecBuilder::setServers` no longer derives the default server
  URL from `$_SERVER['HTTP_HOST']` / `HTTPS`. This was a host header injection
  vector — the cached spec served to every consumer would inherit attacker
  controlled values. The builder is now a pure function of `Config`. The
  Laravel bridge feeds `config('app.url')` (a trusted source) when the user
  hasn't configured `servers` explicitly.
- **Security:** `apexdocs:mock` no longer embeds the OpenAPI spec inside a
  PHP heredoc. The router now lives in `resources/mock/server.php` and reads
  the spec from a temp file passed via the `APEXDOCS_MOCK_SPEC` env var,
  removing the code-injection attack surface where descriptions, route
  metadata, or example values containing `'` could break out of the PHP
  string literal.
- **Robustness:** `ValidationExtractor` (Laravel) now wraps the entire
  `rules()` invocation in `error_reporting(0)` + a `Throwable` catch, skips
  `rules()` methods that require parameters, and caches the resulting body
  schema per-class. A FormRequest that throws — including from missing
  request context like `$this->route('id')` — can no longer kill spec
  generation.
- **Correctness:** `SpecCache` now round-trips the full spec — info,
  servers, components, tags, webhooks, security, and `x-*` extensions all
  survive a cache hit. Backed by new `Document::fromArray()` and
  `Components::fromArray()` constructors. Added `SpecCache::getArray()` for
  HTTP fast paths that just need the serialised form.
- **Maintainability:** Spec-export response bodies + headers now flow through
  a single `ApexDocs\Http\SpecPayload` value object. The PSR-15 `Handler` and
  Laravel `DocsController` both delegate to it so they can no longer drift on
  content type, CORS, or download filenames.
- **Robustness:** `WebhookScanner` now uses `token_get_all()` instead of
  regex to find namespace + class declarations. Avoids false positives on
  `::class` constants, multi-line namespace blocks, and `class` appearing
  inside doc comments.
- **Correctness:** Nullable schemas now emit OpenAPI 3.1's `type: [..., 'null']`
  instead of the deprecated `nullable: true` keyword. Applied across
  `SchemaBuilder` (reflection + union), `RuleParser`, `PostmanExporter`, the
  mock-server example builder, and the Apex UI's schema renderer. A new
  `SchemaBuilder::asNullable()` helper handles `$ref` / `oneOf` / `anyOf` /
  `allOf` cases by appending a `null` branch.
- `composer.json`: removed the unused `nikic/php-parser` dependency, dropped
  the invalid `symfony/yaml: ^8.0` constraint, changed `minimum-stability` from
  `dev` to `stable`, and added a `support` block. Dev dependencies trimmed to
  Pest only.
- `ApexDocs::generate()` now throws `ApexDocs\Exception\MissingRouteCollectionException`
  (extends `\RuntimeException`, implements `ApexDocsException`) instead of
  `\LogicException`.

### Removed

- `nikic/php-parser` — was declared but never imported anywhere in `src/`.
- `phpstan/phpstan` and `laravel/pint` dev dependencies — Pest is the project's
  only quality tool per maintainer decision.

## [0.1.0]

Initial release.
