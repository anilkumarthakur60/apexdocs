# Symfony bridge (`ApexDocs\Bridge\Symfony`)

```php
// config/bundles.php
ApexDocs\Bridge\Symfony\ApexDocsBundle::class => ['all' => true],
```

```yaml
# config/packages/apex_docs.yaml
apex_docs:
  info: { title: My API, version: '2.0.0', description: '', terms_of_service: '', contact: {name,email,url}, license: {name,url} }
  api_path_prefix: api
  exclude_paths: []
  spec_group: ''
  servers: [{ url: https://api.example.com, description: Production }]   # url required
  ui: { path: documentation/api, show_toolbar: true, theme: dark, custom_logo: '', custom_css: '', announcement_banner: '', announcement_banner_type: info, try_it_out: true, default_language: curl }
  security: { auto_detect: true, schemes: { apiKey: { type: apiKey, in: header, name: X-API-Key } } }
  responses: { infer_error_responses: true, include_validation_errors: true, include_pagination_meta: true, max_depth: 6 }
  cache: { enabled: false, ttl: 3600 }
  webhooks: { scan_paths: ['%kernel.project_dir%/src/Webhook'] }
  export: { default_path: '' }
  rate_limits: { enabled: true }
  document_transformers: []
  operation_transformers: []
```
Unknown keys under `ui` (e.g. the removed `default`) fail container compilation.

## Services registered by `ApexDocsExtension` (alias `apex_docs`)

Public: `ApexDocs\Config` (factory `fromArray`), `RouteCollectionInterface` → `RouteCollection(RouterInterface)`,
`ValidationExtractorInterface` → `ValidationExtractor(SchemaBuilder)`, `SecurityDetectorInterface`
→ `SecurityDetector`, `UiRenderer`, the five exporters, and `ApexDocs\ApexDocs` (built by
`apex_docs.factory` so the immutable chain is captured). Private: `ComponentRegistry`, `SchemaBuilder`.

No docs route is registered  mount `ApexDocs\Http\Handler` (PSR-15) or write a controller that
returns `SpecPayload::json($apexDocs)->body` with its `contentType` (see standalone.md).

## Route conversion

Symfony route → `Route`: methods (none → `GET`), `_controller` `Class::method` → `Class@method`
(string controllers only; service ids / invokables without `::` become `Class@__invoke` via
`resolveHandler`), path normalised: `{id<\d+>}` → `{id}` + requirement, `{page?1}` → `{page}`,
`{!slug}` → `{slug}`, `{._format}` removed; metadata `name`, `wheres` (requirements, inline win),
`host`. No middleware → 401/429 inference never fires; security comes from `#[IsGranted]`
(`Symfony\Component\Security\Http\Attribute\IsGranted` or the legacy Sensio one) on method or class
→ `bearerAuth` (http bearer).

## Request bodies

First parameter carrying `#[MapRequestPayload]`: schema from its class; media types
`application/json` + `application/x-www-form-urlencoded` unless `acceptFormat:` pins one
(`json`, `form`, `xml`); `required` = non-nullable without default.
