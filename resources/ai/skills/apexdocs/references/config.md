# Configuration — every key

Three faces of the same object: Laravel `config/apexdocs.php` (snake_case nested array →
`Config::fromArray`), Symfony `apex_docs:` YAML tree (same shape), and `ApexDocs\Config`
(camelCase readonly props; `new Config(...)`, `Config::fromArray([...])`, `$config->with([...])`).
Invalid enum-like values (`theme`, `announcement_banner_type`, `default_language`) silently fall
back to the default.

| Laravel / Symfony key | `Config` property | Default | Effect |
|---|---|---|---|
| `info.title` | `title` | `APP_NAME.' API'` (Laravel) / `'API'` | `info.title` |
| `info.version` | `version` | `1.0.0` | `info.version` |
| `info.description` | `description` | `''` | |
| `info.contact` {name,email,url} | `contact` | `[]` | emitted when non-empty (falsy values filtered) |
| `info.license` {name,url} | `license` | `[]` | |
| `info.terms_of_service` | `termsOfService` | `''` | |
| `api_path_prefix` (string\|array) / `path_prefixes` | `pathPrefixes` | `['api']` | route must equal or start with `prefix/`; `[]` or an empty string = all routes; all-null list = nothing |
| `exclude_paths` | `excludePaths` | `[]` | glob (`fnmatch`) or **anchored** regex, tested with and without leading `/` |
| `spec_group` | `specGroup` | `''` | see `#[ApiGroup]` |
| `servers` [{url, description, variables}] | `servers` | `[]` | Laravel: `APP_URL` injected when empty |
| `ui.path` (Laravel only) | — | `documentation/api` | docs base path; the six docs URLs are auto-added to `exclude_paths` |
| `ui.show_toolbar` | `showToolbar` | `true` | header bar |
| `ui.theme` | `theme` | `dark` | `dark\|light\|auto`; `?theme=` overrides per request |
| `ui.custom_logo` | `customLogo` | `''` | URL |
| `ui.custom_css` | `customCss` | `''` | raw CSS |
| `ui.announcement_banner`, `ui.announcement_banner_type` | `announcementBanner`, `announcementBannerType` | `''`, `info` | `info\|warning\|error` |
| `ui.try_it_out` | `tryItOutEnabled` | `true` | |
| `ui.default_language` | `defaultLanguage` | `curl` | `curl\|js\|python\|php\|go` |
| `security.auto_detect` | `autoDetectSecurity` | `true` | detector on/off (schemes and per-route) |
| `security.schemes` {name: Security Scheme Object} | `securitySchemes` | `[]` | extra schemes |
| `responses.infer_error_responses` | `inferErrorResponses` | `true` | 401 + `Unauthorized` components |
| `responses.include_validation_errors` | `includeValidationErrors` | `true` | 422 + `ValidationError` |
| `responses.include_pagination_meta` | `includePaginationMeta` | `true` | `meta`/`links` on collections |
| `responses.max_depth` | `maxSchemaDepth` | `6` (min 1) | DTO nesting limit |
| `rate_limits.enabled` | `documentRateLimits` | `true` | 429 for `throttle`/`rate` middleware |
| `webhooks.scan_paths` | `webhookScanPaths` | `[]` | absolute directories |
| `cache.enabled` | `cacheEnabled` | Laravel: `APP_ENV !== 'local'`; core `false` | Laravel `DocsController` only (commands never cache) |
| `cache.driver` (Laravel) | — | `null` | store name from `config/cache.php` |
| `cache.ttl` | `cacheTtl` | `3600` | seconds |
| `middleware` (Laravel) | — | `['web']` | on the six docs routes |
| `environments` (Laravel) | — | `['local','staging']` | docs routes registered only here; `[]` = everywhere |
| `export.default_path` | `exportPath` | `storage_path('apexdocs')` / `sys_get_temp_dir().'/apexdocs'` | `apexdocs:export` without `--output` |
| `document_transformers`, `operation_transformers` | `documentTransformers`, `operationTransformers` | `[]` | class names (or objects) implementing the interfaces; unknown class → `InvalidConfigException` |

Env vars read by the Laravel config: `APEXDOCS_TITLE`, `APEXDOCS_VERSION`, `APEXDOCS_DESCRIPTION`,
`APEXDOCS_PATH_PREFIX`, `APEXDOCS_SPEC_GROUP`, `APEXDOCS_PATH`, `APEXDOCS_THEME`,
`APEXDOCS_CACHE_ENABLED`, `APEXDOCS_CACHE_DRIVER`, `APEXDOCS_CACHE_TTL`.

Removed (pre-1.0): `ui.default`, `APEXDOCS_UI`, `ui.show_ui_switcher` → `ui.show_toolbar`.
Symfony rejects the removed keys at boot.

`Config::with([...])` takes **camelCase** property names; `Config::fromArray` takes the nested
snake_case shape (and also accepts flat `title`/`version`/`description`/`path_prefixes`).
