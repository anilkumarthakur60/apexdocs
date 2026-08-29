# Standalone, PSR-15, and custom bridges

## Core API

```php
use ApexDocs\{ApexDocs, Config};
use ApexDocs\Route\{ArrayRouteCollection, Route};

$routes = (new ArrayRouteCollection)                       // also: new ArrayRouteCollection(iterable $routes)
    ->add('GET', '/api/users', UserController::class.'@index', middleware: ['auth'], metadata: ['name' => 'users.index'])
    ->add(['PUT', 'PATCH'], '/api/users/{id}', [UserController::class, 'update'], metadata: ['wheres' => ['id' => '\d+']])
    ->push(new Route(['DELETE'], '/api/users/{id}', UserController::class.'@destroy'));

$apex = ApexDocs::make(['title' => 'My API', 'version' => '2.0.0'])   // Config|array; array → Config::fromArray
    ->routes($routes)                                                  // required  generate() throws MissingRouteCollectionException otherwise
    ->validation($extractor)          // ?ValidationExtractorInterface
    ->security($detector)             // ?SecurityDetectorInterface
    ->filterRoutes(fn (Route $r) => true)
    ->transformDocument(fn ($doc) => …)
    ->transformOperation(fn ($op, Route $route) => …)
    ->webhook('order.paid', ['post' => [...]])
    ->withConfig(['maxSchemaDepth' => 4]);           // Config|array; array uses camelCase Config::with()

$doc = $apex->generate();            // ApexDocs\Spec\Document
$doc->toArray(); $doc->toJson(pretty: true); json_encode($doc);
ApexDocs::build($config, $routes, $validation, $security);   // one-shot → Document
$apex->getConfig(); $apex->getRouteCollection(); $apex->getRouteFilter();
```

`Route`: `methods` (uppercased list), `path` (always rooted), `handler` (`Class@method`; array →
joined; bare class → `__invoke`), `middleware`, `metadata` (`name`, `wheres`, …). Helpers:
`method()`, `documentedMethods()`, `normalizedPath()` (`{x?}` → `{x}`), `pathParamNames()`,
`hasMiddleware('auth')` (exact or `auth:*`), `resolveHandler()`, `name()`, `paramConstraints()`.

## Contracts (`ApexDocs\Contract`)

| Interface | Method | Return |
|---|---|---|
| `RouteCollectionInterface` | `all()` | `list<Route>` |
| `ValidationExtractorInterface` | `extract(ReflectionMethod $handler, Route $route)` | Request Body Object array or null |
| `SecurityDetectorInterface` | `schemes()` / `forRoute(Route, ReflectionMethod)` | `{name: Security Scheme}` / `list<{name: scopes}>` or null  every name returned by `forRoute` must exist in `schemes()` |
| `DocumentTransformerInterface` | `transform(Document $document): void` | mutate in place |
| `OperationTransformerInterface` | `transform(Operation $operation): void` | mutate in place |

## PSR-15 handler

```php
$handler = new ApexDocs\Http\Handler($apexDocs, $responseFactory, $streamFactory, new ApexDocs\Http\UiRenderer);
$app->get('/docs/{path:.*}', $handler);   // Slim example
```
Dispatch by path suffix: `/spec.json`, `/spec.yaml`, `/postman`, `/insomnia`, `/bruno`
(downloads), anything else → the HTML UI pointing at `<path>/spec.json`; `?theme=` honoured.
The spec is generated per request  wrap with `SpecCache` yourself if needed.

`SpecPayload::json|yaml|postman|insomnia|bruno(ApexDocs|Document)` and `::html(string)` give
`body`, `contentType`, `headers`, `downloadName` for any framework's response object.

## Exceptions (`ApexDocs\Exception`)

`ApexDocsException` (base), `ApexDocsRuntimeException`, `MissingRouteCollectionException::create()`,
`InvalidConfigException::forField($field, $reason)`, `SchemaBuildException::forClass($class)`,
`ExporterException::writeFailed($path)`.
