<?php

declare(strict_types=1);

use ApexDocs\ApexDocs;
use ApexDocs\Attribute\ApiResponse;
use ApexDocs\Attribute\Endpoint;
use ApexDocs\Attribute\QueryParam;
use ApexDocs\Attribute\Tag;
use ApexDocs\Config;
use ApexDocs\Route\ArrayRouteCollection;
use ApexDocs\Tests\Fixtures\Controllers\UserController;
use ApexDocs\Tests\Fixtures\Dtos\UserDto;

/**
 * Asserts that the generated spec satisfies the structural invariants of the
 * OpenAPI 3.1 specification.
 *
 * Why not run the full JSON-Schema meta-schema? It's a 2k-line schema that
 * imports JSON-Schema 2020-12 + the OpenAPI dialect; bringing that as a test
 * dependency would mean pulling in opis/json-schema or similar - a runtime-
 * sized dependency just for testing. Instead we encode the invariants that
 * MUST hold for any OpenAPI 3.1 document to be valid against the meta-schema,
 * plus the rules that distinguish 3.1 from 3.0. If we ship something that
 * passes these, downstream tooling (Spectral, Scalar 3.1, Redocly) will
 * accept it.
 */
function conformanceSpec(): array
{
    $routes = (new ArrayRouteCollection)
        ->add('GET', '/api/users', UserController::class.'@index')
        ->add('GET', '/api/users/{id}', UserController::class.'@show');

    return ApexDocs::make(new Config(
        title: 'Conformance API',
        version: '1.0.0',
        servers: [['url' => 'https://api.example.com']],
    ))
        ->routes($routes)
        ->generate()
        ->toArray();
}

it('declares openapi: 3.1.0 at the root', function () {
    $spec = conformanceSpec();
    expect($spec['openapi'])->toBe('3.1.0');
});

it('has the required info object with title and version', function () {
    $spec = conformanceSpec();
    expect($spec['info'])->toBeArray()
        ->toHaveKey('title')
        ->toHaveKey('version');
});

it('uses 3.1 type-array form for nullable, not deprecated `nullable: true`', function () {
    $spec = conformanceSpec();
    $userSchema = $spec['components']['schemas']['UserDto'] ?? [];
    $emailProp = $userSchema['properties']['email'] ?? [];

    expect($emailProp)->not->toHaveKey('nullable') // 3.0 syntax - banned in 3.1
        ->and($emailProp['type'])->toBeArray()
        ->and($emailProp['type'])->toContain('null');
});

it('servers entries each have a url (the only required field)', function () {
    $spec = conformanceSpec();
    foreach ($spec['servers'] ?? [] as $server) {
        expect($server)->toHaveKey('url')
            ->and($server['url'])->toBeString()->not->toBeEmpty();
    }
});

it('every operation has a responses object with at least one entry', function () {
    $spec = conformanceSpec();
    foreach ($spec['paths'] ?? [] as $path => $methods) {
        foreach ($methods as $method => $op) {
            if (! is_array($op)) {
                continue;
            }
            expect($op['responses'] ?? [])->toBeArray()->not->toBeEmpty(
                "operation {$method} {$path} must declare at least one response"
            );
        }
    }
});

it('path-template variables are reflected in operation parameters', function () {
    $spec = conformanceSpec();
    $op = $spec['paths']['/api/users/{id}']['get'] ?? null;

    expect($op)->not->toBeNull();
    $names = array_column($op['parameters'] ?? [], 'name');
    expect($names)->toContain('id');
});

it('every parameter has the required `in` and `name` fields', function () {
    $spec = conformanceSpec();
    foreach ($spec['paths'] ?? [] as $path => $methods) {
        foreach ($methods as $method => $op) {
            if (! is_array($op)) {
                continue;
            }
            foreach ($op['parameters'] ?? [] as $param) {
                expect($param)->toHaveKey('name')->toHaveKey('in');
                expect($param['in'])->toBeIn(['query', 'header', 'path', 'cookie']);
                if ($param['in'] === 'path') {
                    // Path params must be required per the spec.
                    expect($param['required'] ?? false)->toBeTrue();
                }
            }
        }
    }
});

it('every response key is either a status code or the literal "default"', function () {
    $spec = conformanceSpec();
    foreach ($spec['paths'] ?? [] as $methods) {
        foreach ($methods as $op) {
            if (! is_array($op)) {
                continue;
            }
            foreach ($op['responses'] ?? [] as $code => $_) {
                $code = (string) $code;
                $ok = $code === 'default' || preg_match('/^[1-5][0-9X]{2}$/', $code) === 1;
                expect($ok)->toBeTrue("response key '{$code}' is not a valid HTTP status or 'default'");
            }
        }
    }
});

it('component schemas use only valid JSON-Schema types', function () {
    $valid = ['string', 'number', 'integer', 'object', 'array', 'boolean', 'null'];
    $spec = conformanceSpec();

    foreach ($spec['components']['schemas'] ?? [] as $name => $schema) {
        $type = $schema['type'] ?? null;
        if ($type === null) {
            continue;
        }
        $types = is_array($type) ? $type : [$type];
        foreach ($types as $t) {
            expect(in_array($t, $valid, true))->toBeTrue("schema '{$name}' has invalid type '{$t}'");
        }
    }
});

it('round-trips through json_encode + json_decode (no resource leakage)', function () {
    $spec = conformanceSpec();
    $json = json_encode($spec, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $rebuilt = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    expect($rebuilt)->toBe($spec);
});

it('passes the spec to YAML and round-trips with the same shape', function () {
    $spec = conformanceSpec();
    $yaml = \Symfony\Component\Yaml\Yaml::dump($spec, inline: 10);
    $rebuilt = \Symfony\Component\Yaml\Yaml::parse($yaml);

    expect($rebuilt['openapi'])->toBe($spec['openapi'])
        ->and(array_keys($rebuilt['paths'] ?? []))->toBe(array_keys($spec['paths'] ?? []));
});
