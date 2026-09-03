# Upgrading

Breaking changes and the migration steps for them are recorded here, newest
first. Before 1.0.0, a minor release may contain breaking changes; from 1.0.0
onward the project follows strict SemVer and minor releases will not.

For the full list of changes in any release, see [CHANGELOG.md](CHANGELOG.md).

## Unreleased - the CDN UIs are gone

Scalar, Swagger UI, ReDoc, Stoplight Elements and RapiDoc have been removed, and
with them the multi-UI system. The native UI is the only UI.

**Scope of the break.** The package has never been published to Packagist and
carries no git tags, so nothing below is a migration burden for a released
version - these are pre-release removals. They are listed because the names were
public and someone tracking `dev-main` may be using them.

### Configuration

| Before | After |
|--------|-------|
| `ui.default` / `APEXDOCS_UI` | *(delete it)* |
| `ui.show_ui_switcher` | `ui.show_toolbar` |

`ui.show_toolbar` behaves exactly as `show_ui_switcher` did: `false` hides the
whole header bar. It was renamed because it never hid only the switcher.

**Symfony fails loudly here.** The `apex_docs.ui` node is not
`ignoreExtraKeys`, so a leftover `ui.default` or `ui.show_ui_switcher` in
`config/packages/apex_docs.yaml` aborts container compilation with
`Unrecognized option`. That is the intended behaviour: the alternative is a
silently ignored setting. Delete the key, or rename it.

Laravel and the standalone `Config::fromArray()` ignore unknown keys, so a stale
`ui.default` there is inert - but `ui.show_ui_switcher` will stop taking effect,
and the toolbar will come back.

### PHP API

| Removed | Replacement |
|---------|-------------|
| `UiRenderer::UIS` | *(none - there is one UI)* |
| `UiRenderer::normalizeUi()` | *(none)* |
| `Theme::isDark(string $mode)` | `$mode !== 'light'`, if you still need it |
| `UiRenderer::render($ui, $specUrl, $config, $theme)` | `render($specUrl, $config, $theme)` |
| `Config::$defaultUi` | *(none)* |
| `Config::$showUiSwitcher` | `Config::$showToolbar` |

`UiRenderer::normalizeTheme()` is unchanged, and `?theme=dark|light|auto` still
overrides `ui.theme` for a page load.

**Positional `Config` constructor arguments have shifted.** Dropping the
promoted `$defaultUi` means the 13th argument is now the `bool $showToolbar`,
and everything after it moves up one position. Positional construction fails at
runtime with a wrong-typed value rather than at compile time, so it is worth
grepping for:

```php
// Before - 13th and 14th arguments were $defaultUi and $showUiSwitcher
new Config('API', '1.0.0', '', [], ['api'], [], true, true, true, 6, false, 3600, 'apex', true, ...);

// After - use named arguments, which are stable across releases
new Config(title: 'API', version: '1.0.0', showToolbar: true);
```

`Config::with()` takes camelCase keys, so `with(['showUiSwitcher' => false])`
becomes `with(['showToolbar' => false])`. An unknown key there is silently
ignored, so this one is worth grepping for as well.

### URLs

`?ui=` is no longer read. A bookmarked `/docs?ui=redoc` still serves the docs -
the parameter is simply ignored. Fragments (`#op_…`, `#schema_…`) are unaffected.

## 0.1.0

Initial release - nothing to upgrade from.

Two defaults are worth knowing on a first install, because both are deliberate
and neither is what every other documentation generator does:

1. **The docs routes exist only in the environments you list.**
   `apexdocs.environments` defaults to `['local', 'staging']`, so nothing is
   served in production until you say so - and when you do, put real
   authentication in front of it:

   ```php
   // config/apexdocs.php
   'environments' => ['local', 'staging', 'production'],
   'middleware'   => ['web', 'auth'],
   ```

   Use `'environments' => []` to allow every environment. The Artisan commands
   ignore this setting, so CI can always generate the spec.

2. **The spec is cached everywhere except `local`.** Building it reflects every
   controller, DTO, and FormRequest in the application, so the result is stored
   for `apexdocs.cache.ttl` seconds. A deploy that changes routes needs the
   cache cleared:

   ```php
   app(\ApexDocs\Cache\SpecCache::class)->forget();
   ```

   Set `APEXDOCS_CACHE_ENABLED=false` to rebuild on every request instead.
