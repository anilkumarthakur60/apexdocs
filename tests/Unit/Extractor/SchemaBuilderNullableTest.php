<?php

declare(strict_types=1);

use ApexDocs\Extractor\ComponentRegistry;
use ApexDocs\Extractor\SchemaBuilder;

/*
 * C3 regression coverage: every nullable construct should emit OpenAPI 3.1
 * syntax, not the deprecated 'nullable: true' keyword.
 */

it('wraps a primitive type as type-array when nullable', function () {
    $result = SchemaBuilder::asNullable(['type' => 'string']);

    expect($result['type'])->toBe(['string', 'null'])
        ->and($result)->not->toHaveKey('nullable');
});

it('appends "null" to an existing type-array without duplicating', function () {
    $alreadyNullable = SchemaBuilder::asNullable(['type' => ['string', 'null']]);

    expect($alreadyNullable['type'])->toBe(['string', 'null']);
});

it('wraps a $ref with oneOf and null branch', function () {
    $result = SchemaBuilder::asNullable(['$ref' => '#/components/schemas/UserDto']);

    expect($result)->toHaveKey('oneOf')
        ->and($result['oneOf'])->toHaveCount(2)
        ->and($result['oneOf'][0]['$ref'])->toBe('#/components/schemas/UserDto')
        ->and($result['oneOf'][1]['type'])->toBe('null');
});

it('appends a null branch onto an existing oneOf', function () {
    $result = SchemaBuilder::asNullable([
        'oneOf' => [['type' => 'string'], ['type' => 'integer']],
    ]);

    expect($result['oneOf'])->toHaveCount(3)
        ->and(end($result['oneOf']))->toBe(['type' => 'null']);
});

it('appends a null branch onto an existing anyOf', function () {
    $result = SchemaBuilder::asNullable([
        'anyOf' => [['type' => 'string']],
    ]);

    expect($result['anyOf'])->toHaveCount(2)
        ->and($result['anyOf'][1])->toBe(['type' => 'null']);
});

it('falls back to oneOf wrapping for typeless schemas', function () {
    $result = SchemaBuilder::asNullable(['title' => 'orphan']);

    expect($result)->toHaveKey('oneOf')
        ->and($result['oneOf'][0])->toBe(['title' => 'orphan'])
        ->and($result['oneOf'][1])->toBe(['type' => 'null']);
});

it('encodes ?string properties in OpenAPI 3.1 syntax via fromTypeString unions', function () {
    $sb = new SchemaBuilder(maxDepth: 6, registry: new ComponentRegistry());

    $schema = $sb->fromTypeString('string|null');

    expect($schema['type'])->toBe(['string', 'null'])
        ->and($schema)->not->toHaveKey('nullable');
});
