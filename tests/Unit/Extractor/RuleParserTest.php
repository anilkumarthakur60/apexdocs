<?php

declare(strict_types=1);

use ApexDocs\Bridge\Laravel\RuleParser;

test('string with min/max', function () {
    $p = new RuleParser;
    $schema = $p->toSchema(['name' => 'required|string|min:2|max:100']);
    expect($schema['properties']['name'])->toMatchArray(['type' => 'string', 'minLength' => 2, 'maxLength' => 100]);
});

test('integer with between', function () {
    $p = new RuleParser;
    $schema = $p->toSchema(['age' => 'required|integer|between:18,65']);
    expect($schema['properties']['age'])->toMatchArray(['type' => 'integer', 'minimum' => 18, 'maximum' => 65]);
});

test('email format', function () {
    $p = new RuleParser;
    $schema = $p->toSchema(['email' => 'email']);
    expect($schema['properties']['email']['format'])->toBe('email');
});

test('boolean type', function () {
    $p = new RuleParser;
    $schema = $p->toSchema(['active' => 'boolean']);
    expect($schema['properties']['active']['type'])->toBe('boolean');
});

test('array with typed items', function () {
    $p = new RuleParser;
    $schema = $p->toSchema(['tags' => 'array', 'tags.*' => 'string']);
    expect($schema['properties']['tags']['type'])->toBe('array');
    expect($schema['properties']['tags']['items']['type'])->toBe('string');
});

test('nullable is marked on schema', function () {
    $p = new RuleParser;
    $schema = $p->toSchema(['bio' => 'nullable|string']);
    expect($schema['properties']['bio']['nullable'])->toBeTrue();
});

test('nullable fields not in required list', function () {
    $p = new RuleParser;
    $required = $p->required(['field' => 'nullable|string']);
    expect($required)->not->toContain('field');
});

test('in: rule becomes enum', function () {
    $p = new RuleParser;
    $schema = $p->toSchema(['status' => 'in:active,inactive,pending']);
    expect($schema['properties']['status']['enum'])->toBe(['active', 'inactive', 'pending']);
});

test('uuid format', function () {
    $p = new RuleParser;
    $schema = $p->toSchema(['token' => 'uuid']);
    expect($schema['properties']['token']['format'])->toBe('uuid');
});

test('file rule becomes binary', function () {
    $p = new RuleParser;
    $schema = $p->toSchema(['avatar' => 'file']);
    expect($schema['properties']['avatar'])->toMatchArray(['type' => 'string', 'format' => 'binary']);
});
