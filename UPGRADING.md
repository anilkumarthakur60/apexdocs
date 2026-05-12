# Upgrading

Notes for upgrading between minor versions before 1.0.0. After 1.0.0 we will
follow strict SemVer with no breaking changes in minor releases.

## Unreleased → next

### Behaviour changes you may see

1. **`servers` defaults are stricter.** `SpecBuilder` no longer reads
   `$_SERVER['HTTP_HOST']` to fabricate a server entry. Without configuration
   the spec falls back to `http://localhost`. Laravel users inherit
   `config('app.url')` automatically — set `APP_URL` correctly. Symfony users
   should populate `apexdocs.servers` in `config/packages/apex_docs.yaml`.

2. **Nullable schemas changed shape.** OpenAPI 3.1 syntax is now used:
   `"type": ["string", "null"]` instead of `"nullable": true`. Tooling that
   parses generated specs and special-cases `nullable` should be updated.
   Tools that are 3.1-aware (Spectral, Scalar 3.1, Redocly) will accept the
   new form and previously rejected the old one.

3. **`ApexDocs::generate()` exception type changed.** Throws
   `ApexDocs\Exception\MissingRouteCollectionException` instead of
   `\LogicException`. The new exception extends `\RuntimeException` and
   implements `ApexDocs\Exception\ApexDocsException`. Code that catches
   `\LogicException` here will need updating; code that catches `\Throwable`
   keeps working.

### `composer.json` changes

- `nikic/php-parser` was never used internally and has been removed. If your
  application depended on it transitively through ApexDocs, declare it
  directly.
- `symfony/yaml` constraint corrected to `^6.4 || ^7.0`. Symfony 8 does not
  exist yet — applications that pinned to a phantom `^8.0` should drop the
  constraint.
- `minimum-stability` changed from `dev` to `stable`. If your application
  resolution relied on ApexDocs pulling dev versions of transitive deps, set
  `minimum-stability` in your own root `composer.json` instead.

## Earlier releases

No upgrade notes — `0.1.0` was the initial release.
