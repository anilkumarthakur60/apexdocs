# Exports, HTTP payloads, UI, cache

## Exporters (`ApexDocs\Export`)

| Class | `toString(Document)` | `toFile(Document, path)` | Notes |
|---|---|---|---|
| `JsonExporter` | `toString($doc, bool $pretty = true)` | ✓ | |
| `YamlExporter` | ✓ | ✓ | `symfony/yaml` |
| `PostmanExporter` | `toArray` / `toString` | ✓ | Collection v2.1; folders per tag; example bodies from schemas |
| `InsomniaExporter` | `toArray` / `toString` | ✓ | Insomnia export v4 |
| `BrunoExporter` | `toArray` / `toString` | ✓ | Bruno collection JSON |
| `SchemaExample` | `build(array $schema, int $depth = 0)` |  | example value for any schema (used by mock server & exporters) |

`toFile` creates parent directories; failures throw `ExporterException::writeFailed`.

## `SpecPayload`
Framework-neutral response description: `body`, `contentType`, `headers`, `downloadName`.
Factories accept `ApexDocs|Document`: `json`, `yaml`, `postman`, `insomnia`, `bruno`, `html(string)`.
Laravel's `DocsController` and the PSR-15 `Handler` both use it, so content types and download
names never diverge.

## UI (`ApexDocs\Http\UiRenderer`, `Theme`)
Single native UI, zero outbound requests (CSP-safe): sidebar tree, command palette, schema
browser, try-it-out, code samples (`curl|js|python|php|go`). `UiRenderer::render(string $specUrl,
Config $config, string $theme)`; `UiRenderer::normalizeTheme(?string $query, string $default)`.
Keyboard: `t` theme, `?` shortcuts dialog. Config: `ui.*` (see config.md). `Theme::MODES`,
`Theme::palette($mode)`, `Theme::declarations($mode)`, `Theme::css()`.

## `SpecCache` (PSR-16)
`new SpecCache(CacheInterface $psr16, int $ttl = 3600, string $prefix = 'apexdocs.')`;
`get($key = 'default'): ?Document`, `getArray()`, `put($key, Document)`, `forget()`, `has()`.
Stores `toArray()`; restores via `Document::fromArray`. Laravel binds it to `cache.driver`.
