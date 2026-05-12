<?php

declare(strict_types=1);

use ApexDocs\ApexDocs;
use ApexDocs\Config;
use ApexDocs\Route\ArrayRouteCollection;
use ApexDocs\Spec\Document;
use ApexDocs\Tests\Fixtures\Controllers\UserController;

/*
 * End-to-end smoke test — builds a full Document from a hand-built route
 * collection and asserts the spec contains the expected paths, tags, schemas,
 * and operation metadata. Catches regressions across the whole pipeline.
 */

/**
 * Build the fixture document once per test (Pest disables shared state by
 * default, so callers stay isolated). Cheap enough to rerun.
 *
 * @return array<string, mixed>
 */
function fixtureSpec(): array
{
    $routes = (new ArrayRouteCollection())
        ->add('GET', '/api/users', UserController::class.'@index', metadata: ['name' => 'users.index'])
        ->add('GET', '/api/users/{id}', UserController::class.'@show', metadata: ['name' => 'users.show']);

    $doc = ApexDocs::make(new Config(title: 'Fixture API', version: '0.1.0', description: 'Test API'))
        ->routes($routes)
        ->generate();

    expect($doc)->toBeInstanceOf(Document::class);

    return $doc->toArray();
}

/**
 * Tiny lookup helper.
 *
 * @param  array<int, array<string, mixed>>  $items
 */
function findByField(array $items, string $field, mixed $value): ?array
{
    foreach ($items as $item) {
        if (($item[$field] ?? null) === $value) {
            return $item;
        }
    }

    return null;
}

it('produces an OpenAPI 3.1 document', function () {
    $array = fixtureSpec();

    expect($array['openapi'])->toBe('3.1.0')
        ->and($array['info']['title'])->toBe('Fixture API')
        ->and($array['info']['version'])->toBe('0.1.0');
});

it('includes both configured routes as operations', function () {
    $paths = fixtureSpec()['paths'] ?? [];

    expect(array_keys($paths))->toContain('/api/users', '/api/users/{id}')
        ->and($paths['/api/users']['get']['operationId'])->toBe('users_index')
        ->and($paths['/api/users/{id}']['get']['operationId'])->toBe('users_show');
});

it('extracts the Users tag from the class attribute', function () {
    $tags = fixtureSpec()['tags'] ?? [];

    expect($tags)->toHaveCount(1)
        ->and($tags[0]['name'])->toBe('Users');
});

it('registers UserDto as a reusable schema once', function () {
    $schemas = fixtureSpec()['components']['schemas'] ?? [];

    expect($schemas)->toHaveKey('UserDto')
        ->and($schemas['UserDto']['title'])->toBe('User')
        ->and($schemas['UserDto']['description'])->toBe('A registered user')
        ->and($schemas['UserDto']['properties'])->toHaveKeys(['id', 'name', 'email', 'isAdmin']);
});

it('encodes nullable properties in OpenAPI 3.1 form', function () {
    $emailSchema = fixtureSpec()['components']['schemas']['UserDto']['properties']['email'];

    expect($emailSchema['type'])->toBe(['string', 'null'])
        ->and($emailSchema)->not->toHaveKey('nullable');
});

it('marks readonly non-nullable properties as required', function () {
    $userSchema = fixtureSpec()['components']['schemas']['UserDto'];

    expect($userSchema['required'] ?? [])->toContain('id', 'name')
        ->and($userSchema['required'] ?? [])->not->toContain('email');
});

it('serializes #[QueryParam] attributes into operation parameters', function () {
    $params = fixtureSpec()['paths']['/api/users']['get']['parameters'] ?? [];

    $page = findByField($params, 'name', 'page');
    expect($page)->not->toBeNull()
        ->and($page['in'])->toBe('query')
        ->and($page['schema']['type'])->toBe('integer')
        ->and($page['example'])->toBe(1);
});

it('emits a path parameter for {id} with an inferred type', function () {
    $params = fixtureSpec()['paths']['/api/users/{id}']['get']['parameters'] ?? [];

    $id = findByField($params, 'name', 'id');
    expect($id)->not->toBeNull()
        ->and($id['in'])->toBe('path')
        ->and($id['required'])->toBeTrue()
        ->and($id['schema']['type'])->toBe('integer');
});

it('attaches both 200 (resource) and 404 responses on #[ApiResponse]', function () {
    $responses = fixtureSpec()['paths']['/api/users/{id}']['get']['responses'];

    expect($responses)->toHaveKeys(['200', '404'])
        ->and($responses['200']['content']['application/json']['schema']['properties']['data']['$ref'])
            ->toBe('#/components/schemas/UserDto')
        ->and($responses['404']['description'])->toBe('User not found');
});

it('exposes the configured server (no $_SERVER fallback)', function () {
    // C1 regression guard: without configured servers and without a
    // ServiceProvider override, the default is the constant http://localhost.
    $servers = fixtureSpec()['servers'] ?? [];

    expect($servers)->toHaveCount(1)
        ->and($servers[0]['url'])->toBe('http://localhost');
});
