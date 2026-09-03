<?php

declare(strict_types=1);

use ApexDocs\ApexDocs;
use ApexDocs\Attribute\ExternalDocs;
use ApexDocs\Attribute\PathParam;
use ApexDocs\Attribute\Produces;
use ApexDocs\Attribute\QueryParam;
use ApexDocs\Attribute\ResponseHeader;
use ApexDocs\Attribute\Schema;
use ApexDocs\Attribute\Tag;
use ApexDocs\Bridge\Laravel\SecurityDetector;
use ApexDocs\Config;
use ApexDocs\Route\ArrayRouteCollection;
use ApexDocs\Route\Route;

/**
 * Structural invariants an adversarial sweep found the generator breaking.
 * Every one of these produced either an uncaught throwable or a document a
 * validator rejects.
 */
class InvariantDto
{
    public string $a = '';
}

#[Schema(title: \NoSuchClassAtAll::MISSING_CONST)]
class BrokenSchemaDto
{
    public string $x = '';
}

#[Tag('2024')]
class InvariantController
{
    public function numericTag(): InvariantDto
    {
        throw new RuntimeException;
    }

    public function broken(): BrokenSchemaDto
    {
        throw new RuntimeException;
    }

    #[PathParam('ghost')]
    #[QueryParam('page', type: 'int')]
    public function strays(): InvariantDto
    {
        throw new RuntimeException;
    }

    #[Produces('')]
    #[ResponseHeader('123')]
    #[ResponseHeader('X-Fine')]
    #[ExternalDocs(url: '', description: 'nowhere')]
    public function emptyish(): InvariantDto
    {
        throw new RuntimeException;
    }

    public function plain(): InvariantDto
    {
        throw new RuntimeException;
    }
}

class MixedPropDto
{
    public mixed $payload = null;
}

class MixedController
{
    public function m(): MixedPropDto
    {
        throw new RuntimeException;
    }

    /** @return int|mixed */
    public function unionWithMixed(): mixed
    {
        return 1;
    }
}

function invariantSpec(array $routes, ?Config $config = null): array
{
    return ApexDocs::make($config ?? new Config(title: 'I', version: '1'))
        ->routes(new ArrayRouteCollection($routes))
        ->generate()
        ->toArray();
}

it('accepts a numeric tag name without a TypeError', function () {
    // Array keys coerce "2024" to int 2024, which Document::addTag(string) rejects.
    $spec = invariantSpec([new Route(['GET'], '/api/a', [InvariantController::class, 'numericTag'])]);

    expect($spec['paths']['/api/a']['get']['tags'])->toBe(['2024'])
        ->and($spec['tags'])->toBe([['name' => '2024']]);
});

it('accepts a numeric webhook name without a TypeError', function () {
    $doc = ApexDocs::make(new Config(title: 'I', version: '1'))
        ->routes(new ArrayRouteCollection)
        ->webhook('2024', ['post' => ['responses' => ['200' => ['description' => 'ok']]]])
        ->generate();

    // PHP stores "2024" as an int key; what matters is that the document builds
    // and serialises the webhook as a named object.
    expect(json_encode($doc->toArray()['webhooks']))->toStartWith('{"2024":');
});

it('survives a #[Schema] whose arguments no longer resolve', function () {
    $spec = invariantSpec([new Route(['GET'], '/api/b', [InvariantController::class, 'broken'])]);

    expect($spec['components']['schemas'])->toHaveKey('BrokenSchemaDto');
});

it('roots an unrooted path and keeps the paths map a JSON object', function () {
    $spec = invariantSpec(
        [
            new Route(['GET'], 'api/users', [InvariantController::class, 'plain']),
            new Route(['GET'], '0', [InvariantController::class, 'plain']),
        ],
        new Config(title: 'I', version: '1', pathPrefixes: ['']),
    );

    // A path of "0" would become an int array key and turn the whole paths map
    // into a JSON array; rooting it keeps the key a string.
    expect(array_keys($spec['paths']))->toBe(['/api/users', '/0'])
        ->and(json_encode($spec['paths'], JSON_UNESCAPED_SLASHES))->toStartWith('{"/api/users"');
});

it('drops a path parameter that names no template variable', function () {
    $params = invariantSpec([new Route(['GET'], '/api/c', [InvariantController::class, 'strays'])])
        ['paths']['/api/c']['get']['parameters'];

    expect(array_column($params, 'name'))->toBe(['page']);
});

it('maps a PHP type name onto a JSON Schema type', function () {
    $params = invariantSpec([new Route(['GET'], '/api/c', [InvariantController::class, 'strays'])])
        ['paths']['/api/c']['get']['parameters'];

    expect($params[0]['schema']['type'])->toBe('integer');
});

it('skips empty media types, keeps numeric header names, and omits a urlless externalDocs', function () {
    $op = invariantSpec([new Route(['GET'], '/api/d', [InvariantController::class, 'emptyish'])])
        ['paths']['/api/d']['get'];

    expect($op)->not->toHaveKey('externalDocs')
        ->and(array_keys($op['responses']['200']['content']))->toBe(['application/json'])
        ->and($op['responses']['200']['headers'])->toHaveKeys(['123', 'X-Fine'])
        ->and(json_encode($op['responses']['200']['headers']))->toStartWith('{');
});

it('keeps operationIds unique when punctuation collapses', function () {
    $spec = invariantSpec([
        new Route(['GET'], '/api/a-b', [InvariantController::class, 'plain']),
        new Route(['GET'], '/api/a.b', [InvariantController::class, 'plain']),
        new Route(['GET'], '/api/a_b', [InvariantController::class, 'plain']),
    ]);

    $ids = [];
    foreach ($spec['paths'] as $item) {
        $ids[] = $item['get']['operationId'];
    }

    expect($ids)->toHaveCount(3)
        ->and(array_unique($ids))->toHaveCount(3);
});

it('skips a route whose only verbs cannot appear in a path item', function () {
    $spec = invariantSpec([
        new Route(['PROPFIND'], '/api/webdav', [InvariantController::class, 'plain']),
        new Route(['GET', 'PURGE'], '/api/cache', [InvariantController::class, 'plain']),
    ]);

    expect($spec['paths'])->not->toHaveKey('/api/webdav')
        ->and(array_keys($spec['paths']['/api/cache']))->toBe(['get']);
});

it('describes a path variable whose name is not plain ASCII', function () {
    $op = invariantSpec([new Route(['GET'], '/api/{ключ}', [InvariantController::class, 'plain'])])
        ['paths']['/api/{ключ}']['get'];

    expect(array_column($op['parameters'], 'name'))->toBe(['ключ']);
});

it('never emits an empty array where a schema object belongs', function () {
    $spec = invariantSpec([
        new Route(['GET'], '/api/e', [MixedController::class, 'm']),
        new Route(['GET'], '/api/f', [MixedController::class, 'unionWithMixed']),
    ]);

    $json = json_encode($spec);

    expect($json)->not->toContain('[]')
        ->and($spec['components']['schemas']['MixedPropDto']['properties']['payload'])->toBeInstanceOf(stdClass::class);
});

it('does not reference a detected security scheme when auto-detection is off', function () {
    $spec = ApexDocs::make(new Config(title: 'I', version: '1', autoDetectSecurity: false))
        ->routes(new ArrayRouteCollection([
            new Route(['GET'], '/api/g', [InvariantController::class, 'plain'], ['auth:sanctum']),
        ]))
        ->security(new SecurityDetector)
        ->generate()
        ->toArray();

    expect($spec['paths']['/api/g']['get'])->not->toHaveKey('security')
        ->and($spec['components'])->not->toHaveKey('securitySchemes');
});

it('drops an unusable path prefix instead of documenting the whole app', function () {
    $routes = [
        new Route(['GET'], '/api/users', [InvariantController::class, 'plain']),
        new Route(['GET'], '/admin/secret', [InvariantController::class, 'plain']),
    ];

    // ['api', null] - an env-driven prefix that resolved to nothing.
    $spec = invariantSpec($routes, new Config(title: 'I', version: '1', pathPrefixes: ['api', null]));

    expect(array_keys($spec['paths']))->toBe(['/api/users']);
});

it('anchors exclude regexes so a short pattern cannot empty the spec', function () {
    $routes = [
        new Route(['GET'], '/api/users', [InvariantController::class, 'plain']),
        new Route(['GET'], '/api/internally', [InvariantController::class, 'plain']),
        new Route(['GET'], '/api/internal', [InvariantController::class, 'plain']),
    ];

    $spec = invariantSpec($routes, new Config(
        title: 'I',
        version: '1',
        excludePaths: ['api', 'api/internal'],
    ));

    expect(array_keys($spec['paths']))->toBe(['/api/users', '/api/internally']);
});

it('sanitises a configured security scheme name to a legal component key', function () {
    $spec = invariantSpec(
        [new Route(['GET'], '/api/users', [InvariantController::class, 'plain'])],
        new Config(title: 'I', version: '1', securitySchemes: ['my scheme!' => ['type' => 'http', 'scheme' => 'bearer']]),
    );

    expect(array_keys($spec['components']['securitySchemes']))->toBe(['myscheme']);
});
