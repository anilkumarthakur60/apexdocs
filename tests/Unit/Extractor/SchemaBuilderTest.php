<?php

declare(strict_types=1);

use ApexDocs\Extractor\SchemaBuilder;

test('scalar types', function () {
    $b = new SchemaBuilder;
    expect($b->fromTypeString('string'))->toBe(['type' => 'string']);
    expect($b->fromTypeString('int'))->toBe(['type' => 'integer']);
    expect($b->fromTypeString('bool'))->toBe(['type' => 'boolean']);
    expect($b->fromTypeString('float'))->toBe(['type' => 'number', 'format' => 'float']);
});

test('array shorthand', function () {
    $b = new SchemaBuilder;
    $schema = $b->fromTypeString('string[]');
    expect($schema['type'])->toBe('array');
    expect($schema['items']['type'])->toBe('string');
});

test('union type becomes oneOf', function () {
    $b = new SchemaBuilder;
    $schema = $b->fromTypeString('string|int');
    expect($schema)->toHaveKey('oneOf');
    expect(count($schema['oneOf']))->toBe(2);
});

test('nullable union collapses to single type', function () {
    $b = new SchemaBuilder;
    $schema = $b->fromTypeString('string|null');
    expect($schema['type'])->toBe('string');
    expect($schema['nullable'])->toBeTrue();
});

test('enum class', function () {
    $b = new SchemaBuilder;

    // Create an anonymous enum for testing
    eval('enum TestStatus: string { case Active = "active"; case Inactive = "inactive"; }');

    $schema = $b->fromTypeString('TestStatus');
    expect($schema['type'])->toBe('string');
    expect($schema['enum'])->toContain('active');
})->skip(PHP_VERSION_ID < 80100, 'Requires PHP 8.1+');

test('void returns empty array', function () {
    $b = new SchemaBuilder;
    expect($b->fromTypeString('void'))->toBe([]);
});
