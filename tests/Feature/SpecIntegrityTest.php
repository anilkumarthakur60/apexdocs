<?php

declare(strict_types=1);

use ApexDocs\ApexDocs;
use ApexDocs\Bridge\Laravel\SecurityDetector;
use ApexDocs\Config;
use ApexDocs\Exception\InvalidConfigException;
use ApexDocs\Route\ArrayRouteCollection;
use ApexDocs\Route\Route;
use ApexDocs\Spec\Document;
use ApexDocs\Tests\Fixtures\Controllers\UserController;

/**
 * Document-level invariants that downstream tooling depends on: no dangling
 * $refs, no security requirement without a matching scheme, no server without
 * a url.
 */
function collectRefs(mixed $node, array &$found = []): array
{
    if (! is_array($node)) {
        return $found;
    }
    foreach ($node as $key => $value) {
        if ($key === '$ref' && is_string($value)) {
            $found[$value] = true;

            continue;
        }
        collectRefs($value, $found);
    }

    return $found;
}

function resolvePointer(array $spec, string $ref): bool
{
    if (! str_starts_with($ref, '#/')) {
        return false;
    }
    $node = $spec;
    foreach (explode('/', substr($ref, 2)) as $segment) {
        if (! is_array($node) || ! array_key_exists($segment, $node)) {
            return false;
        }
        $node = $node[$segment];
    }

    return true;
}

function securedSpec(): array
{
    $routes = new ArrayRouteCollection([
        new Route(['GET'], '/api/users', UserController::class.'@index', ['auth:sanctum', 'throttle:60,1']),
        new Route(['POST'], '/api/users', UserController::class.'@store', ['auth', 'throttle:api']),
        new Route(['GET'], '/api/users/{id}', UserController::class.'@show'),
    ]);

    return ApexDocs::make(new Config(title: 'Secured', version: '1.0.0'))
        ->routes($routes)
        ->security(new SecurityDetector)
        ->generate()
        ->toArray();
}

it('resolves every $ref it emits', function () {
    $spec = securedSpec();

    foreach (array_keys(collectRefs($spec)) as $ref) {
        expect(resolvePointer($spec, $ref))->toBeTrue("dangling \$ref: {$ref}");
    }
});

it('defines every security scheme it requires', function () {
    $spec = securedSpec();
    $defined = array_keys($spec['components']['securitySchemes'] ?? []);

    expect($defined)->not->toBeEmpty();

    foreach ($spec['paths'] as $methods) {
        foreach ($methods as $op) {
            foreach ($op['security'] ?? [] as $requirement) {
                foreach (array_keys($requirement) as $name) {
                    expect($defined)->toContain($name);
                }
            }
        }
    }
});

it('adds 401 and 429 responses for protected, throttled routes', function () {
    $spec = securedSpec();

    expect($spec['paths']['/api/users']['get']['responses'])->toHaveKeys(['401', '429'])
        ->and($spec['paths']['/api/users/{id}']['get']['responses'])->not->toHaveKey('401');
});

it('drops a configured server that has no url', function () {
    $spec = ApexDocs::make(new Config(
        title: 'S',
        version: '1',
        servers: [['description' => 'no url here'], ['url' => 'https://api.example.com']],
    ))->routes(new ArrayRouteCollection)->generate()->toArray();

    expect($spec['servers'])->toBe([['url' => 'https://api.example.com']]);
});

it('documents everything when no path prefix is configured', function () {
    $routes = new ArrayRouteCollection([
        new Route(['GET'], '/health', UserController::class.'@index'),
        new Route(['GET'], '/api/users', UserController::class.'@index'),
    ]);

    $spec = ApexDocs::make(new Config(title: 'S', version: '1', pathPrefixes: []))
        ->routes($routes)->generate()->toArray();

    expect(array_keys($spec['paths']))->toContain('/health', '/api/users');
});

it('honours exclude patterns with and without a leading slash', function () {
    $routes = new ArrayRouteCollection([
        new Route(['GET'], '/api/internal/metrics', UserController::class.'@index'),
        new Route(['GET'], '/api/users', UserController::class.'@index'),
    ]);

    $spec = ApexDocs::make(new Config(title: 'S', version: '1', excludePaths: ['api/internal/*']))
        ->routes($routes)->generate()->toArray();

    expect(array_keys($spec['paths']))->toBe(['/api/users']);
});

it('rejects a transformer class that does not implement the contract', function () {
    expect(fn () => ApexDocs::make(new Config(title: 'S', version: '1', documentTransformers: [stdClass::class]))
        ->routes(new ArrayRouteCollection)
        ->generate())
        ->toThrow(InvalidConfigException::class);
});

it('applies transformers declared in config as well as fluent ones', function () {
    $spec = ApexDocs::make(new Config(
        title: 'S',
        version: '1',
        operationTransformers: [TagStampingTransformer::class],
    ))
        ->routes(new ArrayRouteCollection([new Route(['GET'], '/api/users', UserController::class.'@index')]))
        ->generate()
        ->toArray();

    expect($spec['paths']['/api/users']['get']['x-owner'])->toBe('platform');
});

it('passes the route to operation transformer closures', function () {
    $seen = null;

    ApexDocs::make(new Config(title: 'S', version: '1'))
        ->routes(new ArrayRouteCollection([new Route(['GET'], '/api/users', UserController::class.'@index')]))
        ->transformOperation(function ($op, $route) use (&$seen) {
            $seen = $route->path;
        })
        ->generate();

    expect($seen)->toBe('/api/users');
});

it('round-trips a full document through the cache serialisation', function () {
    $spec = securedSpec();

    expect(Document::fromArray($spec)->toArray())->toBe($spec);
});

class TagStampingTransformer implements \ApexDocs\Contract\OperationTransformerInterface
{
    public function transform(\ApexDocs\Spec\Operation $operation): void
    {
        $operation->extend('owner', 'platform');
    }
}
