# Customisation

## Transformers

Class-based (config `document_transformers` / `operation_transformers`, or fluent) implement the
contract and receive only the spec object; closures may also receive the `Route`:

```php
final class AddOwner implements OperationTransformerInterface {
    public function transform(Operation $operation): void { $operation->extend('x-owner', 'backend'); }
}
$apex->transformOperation(fn (Operation $op, Route $route) => $op->extend('x-source', $route->handler));
$apex->transformDocument(fn (Document $doc) => $doc->addServer('https://staging.example.com', 'Staging'));
```
Order: fluent transformers first, then config ones. Operation transformers run per operation
(after security), document transformers after webhooks, schemas and standard components.

## `Operation` API (`ApexDocs\Spec\Operation`)
`id()`, `summary()`, `description()`, `tags(array)`, `deprecated(bool = true)`, `addParameter(array)`,
`requestBody(array)`, `addResponse(string $status, array)`, `security(?array)` (`[]` = public,
`null` = unset), `externalDocs(url, description)`, `extend(key, value)` (`x-` prefixed
automatically), `getTags()`, `get(key, default)`, `toArray()`.

## `Document` API (`ApexDocs\Spec\Document`)
`info(title, version, description)`, `addInfoField(key, value)`, `addServer(url, description, variables)`,
`addOperation(path, method, Operation)`, `hasOperation(path, method)`, `getPaths()`, `addWebhook(name, spec)`,
`components(): Components`, `addGlobalSecurity(scheme, scopes)`, `addTag(name, description)`,
`extend(key, value)`, `toArray()`, `toJson(pretty)`, `jsonSerialize()`, `Document::fromArray(array)`
(round-trips everything  used by the cache).

## `Components` API
`addSchema`, `hasSchema`, `addResponse`, `addParameter`, `addExample`, `addRequestBody`,
`addHeader`, `addSecurityScheme`, `fromArray`, `toArray`. Component keys must match
`^[a-zA-Z0-9._-]+$`.

## Route filtering
`filterRoutes(Closure(Route): bool)`  runs after prefix/exclude/group filtering; return `false`
to drop. Only one filter is kept (a later call replaces it).

## Webhooks
`#[Webhook]` classes under `webhooks.scan_paths`, or `$apex->webhook('name', ['post' => [...]])`
with a full Path Item Object. Both land in the top-level `webhooks` map.

## Multiple specs from one app
`spec_group` + `#[ApiGroup('partner')]` on the partner controllers; run
`APEXDOCS_SPEC_GROUP=partner php artisan apexdocs:generate --output=partner.json`. Routes without
`#[ApiGroup]` appear in every group.
