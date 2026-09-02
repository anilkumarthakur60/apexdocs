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
`json`/`yaml` send `Access-Control-Allow-Origin: *` and `Cache-Control: no-store`; `html` sends
`no-store` too — the page inlines the whole UI, so a cached copy pins the reader to an old one.
Laravel's `DocsController` and the PSR-15 `Handler` both use it, so content types and download
names never diverge.

## UI (`ApexDocs\Http\UiRenderer`, `Theme`)
Single native UI, zero outbound requests (CSP-safe): sidebar tree, command palette, schema
browser, try-it-out, code samples (`curl|js|python|php|go`). `UiRenderer::render(string $specUrl,
Config $config, string $theme)`; `UiRenderer::normalizeTheme(?string $query, string $default)`.
Keyboard: `t` theme, `?` shortcuts dialog. Config: `ui.*` (see config.md). `Theme::MODES`,
`Theme::palette($mode)`, `Theme::declarations($mode)`, `Theme::css()`.

Navigation is by hash, not by click handler: the sidebar, every `$ref` badge and each "Used by"
row is a real `<a href>`, so Tab reaches them, Enter activates them, cmd-click opens a new tab and
Back/Forward walk the views visited. Deep links: `#op_<operationId>`, `#wh_<name>_<method>`,
`#schema_<ComponentName>`, plus `?theme=dark|light|auto`. Every disclosure control is a `<button>`
carrying `aria-expanded`/`aria-controls`; each view emits one `h1`; the active row carries
`aria-current="page"`; one keyboard-only focus ring, from `--ring`.

Above 1200px the article is a grid item that fills its cell (`--doc-max` applies only below that,
where the viewport is the only bound). Descriptions are markdown with **soft** line breaks: a
single newline is a space, so a PHPDoc hard-wrapped at 80 columns reflows; two trailing spaces or
a trailing backslash still force a `<br>`. An operation's `$ref`-ed responses, parameters and
request body are resolved through `components` before rendering.

## `SpecCache` (PSR-16)
`new SpecCache(CacheInterface $psr16, int $ttl = 3600, string $prefix = 'apexdocs.')`;
`get($key = 'default'): ?Document`, `getArray()`, `put($key, Document)`, `forget()`, `has()`.
Stores `toArray()`; restores via `Document::fromArray`. Laravel binds it to `cache.driver`.
