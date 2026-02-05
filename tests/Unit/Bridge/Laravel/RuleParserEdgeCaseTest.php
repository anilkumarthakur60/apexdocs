<?php

declare(strict_types=1);

use ApexDocs\Bridge\Laravel\RuleParser;

/**
 * Rule shapes that used to produce wrong schemas — or, in the closure case,
 * kill the whole build with "Object of class Closure could not be converted to
 * string".
 */
it('ignores a closure rule instead of throwing', function () {
    $schema = (new RuleParser)->toSchema([
        'name' => ['required', 'string', 'max:8', fn ($attribute, $value, $fail) => null],
    ]);

    expect($schema['properties']['name'])->toBe(['type' => 'string', 'maxLength' => 8]);
});

it('ignores a rule object with no string form', function () {
    $rule = new class
    {
        public string $whatever = 'x';
    };

    $schema = (new RuleParser)->toSchema(['field' => ['required', $rule]]);

    expect($schema['properties']['field']['type'])->toBe('string');
});

it('keeps fractional bounds on numeric fields', function () {
    $schema = (new RuleParser)->toSchema(['price' => 'required|numeric|min:0.5|max:99.99']);

    expect($schema['properties']['price']['minimum'])->toBe(0.5)
        ->and($schema['properties']['price']['maximum'])->toBe(99.99);
});

it('casts enum values to the declared field type', function () {
    $schema = (new RuleParser)->toSchema(['level' => 'required|integer|in:1,2,3']);

    expect($schema['properties']['level']['enum'])->toBe([1, 2, 3]);
});

it('treats a sometimes|required field as optional', function () {
    $parser = new RuleParser;
    $rules = ['nickname' => 'sometimes|required|string'];

    expect($parser->required($rules))->toBe([])
        ->and($parser->toSchema($rules))->not->toHaveKey('required');
});

it('nests dotted rule keys into object properties', function () {
    $schema = (new RuleParser)->toSchema([
        'author.name' => 'required|string',
        'author.email' => 'nullable|email',
    ]);

    expect($schema['properties'])->toHaveKey('author')
        ->and($schema['properties'])->not->toHaveKey('author.name')
        ->and($schema['properties']['author']['type'])->toBe('object')
        ->and(array_keys($schema['properties']['author']['properties']))->toBe(['name', 'email'])
        ->and($schema['properties']['author']['required'])->toBe(['name']);
});

it('nests wildcard rule keys into array items', function () {
    $schema = (new RuleParser)->toSchema([
        'items' => 'required|array|min:1',
        'items.*.sku' => 'required|string',
        'items.*.qty' => 'required|integer|gt:0',
    ]);

    $items = $schema['properties']['items'];

    expect($items['type'])->toBe('array')
        ->and($items['minItems'])->toBe(1)
        ->and($items['items']['type'])->toBe('object')
        ->and(array_keys($items['items']['properties']))->toBe(['sku', 'qty'])
        ->and($items['items']['properties']['qty']['exclusiveMinimum'])->toBe(0)
        ->and($items['items']['required'])->toBe(['sku', 'qty']);
});

it('strips regex delimiters from a pattern', function () {
    $schema = (new RuleParser)->toSchema(['code' => 'required|string|regex:/^[A-Z]{3}$/']);

    expect($schema['properties']['code']['pattern'])->toBe('^[A-Z]{3}$');
});

it('keeps a nullable file upload nullable', function () {
    $schema = (new RuleParser)->toSchema(['avatar' => 'nullable|image|max:2048']);

    expect($schema['properties']['avatar'])->toBe(['type' => ['string', 'null'], 'format' => 'binary']);
});

it('maps comparison rules onto JSON Schema bounds', function () {
    $schema = (new RuleParser)->toSchema(['n' => 'required|integer|gte:5|lt:10|multiple_of:5']);

    expect($schema['properties']['n']['minimum'])->toBe(5)
        ->and($schema['properties']['n']['exclusiveMaximum'])->toBe(10)
        ->and($schema['properties']['n']['multipleOf'])->toBe(5);
});
