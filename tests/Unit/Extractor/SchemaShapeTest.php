<?php

declare(strict_types=1);

use ApexDocs\Extractor\ComponentRegistry;
use ApexDocs\Extractor\SchemaBuilder;

/**
 * JSON Schema shape rules that PHP's array encoding makes easy to break: an
 * empty PHP array serialises to `[]`, which is not a valid value for
 * `properties`, `items`, or `additionalProperties`.
 */
class NoPublicProps
{
    protected int $hidden = 1;

    private string $secret = 'x';
}

class SelfReferencing
{
    public string $name = '';

    public ?SelfReferencing $parent = null;

    /** @var SelfReferencing[] */
    public array $children = [];
}

enum EmptyBacking: string
{
    case Only = 'only';
}

it('omits properties instead of encoding an empty array', function () {
    $registry = new ComponentRegistry;
    (new SchemaBuilder(6, $registry))->fromClass(NoPublicProps::class);

    $schema = $registry->all()['NoPublicProps'];

    expect($schema)->toBe(['type' => 'object'])
        ->and(json_encode($schema))->toBe('{"type":"object"}');
});

it('encodes an untyped array as items: {} not items: []', function () {
    $json = json_encode((new SchemaBuilder)->fromTypeString('array'));

    expect($json)->toBe('{"type":"array","items":{}}');
});

it('terminates on a self-referencing DTO and points the cycle at the component', function () {
    $registry = new ComponentRegistry;
    $ref = (new SchemaBuilder(6, $registry))->fromClass(SelfReferencing::class);

    expect($ref)->toBe(['$ref' => '#/components/schemas/SelfReferencing']);

    $schema = $registry->all()['SelfReferencing'];

    expect($schema['properties']['parent']['oneOf'][0]['$ref'])->toBe('#/components/schemas/SelfReferencing')
        ->and($schema['properties']['parent']['oneOf'][1])->toBe(['type' => 'null'])
        // The element type comes from `@var SelfReferencing[]` — a bare `array`
        // declaration alone would only give `items: {}`.
        ->and($schema['properties']['children']['items']['$ref'])->toBe('#/components/schemas/SelfReferencing');
});

it('reads element types from a promoted constructor parameter annotation', function () {
    $registry = new ComponentRegistry;
    (new SchemaBuilder(6, $registry))->fromClass(AnnotatedDto::class);

    $schema = $registry->all()['AnnotatedDto'];

    expect($schema['properties']['lines']['items']['$ref'])->toBe('#/components/schemas/SelfReferencing')
        ->and($schema['properties']['meta'])->toBe([
            'type' => 'object',
            'additionalProperties' => ['type' => 'string'],
        ])
        ->and($schema['properties']['untyped'])->toBeInstanceOf(stdClass::class);
});

class AnnotatedDto
{
    public $untyped;

    /**
     * @param  SelfReferencing[]  $lines
     * @param  array<string, string>  $meta
     */
    public function __construct(
        public readonly array $lines = [],
        public readonly array $meta = [],
    ) {}
}

it('uses the 3.1 type array for a nullable property, never the nullable keyword', function () {
    $registry = new ComponentRegistry;
    (new SchemaBuilder(6, $registry))->fromClass(SelfReferencing::class);

    expect(json_encode($registry->all()))->not->toContain('nullable');
});

it('reads a backed enum as a typed enum schema', function () {
    $registry = new ComponentRegistry;
    (new SchemaBuilder(6, $registry))->fromClass(EmptyBacking::class);

    expect($registry->all()['EmptyBacking'])->toBe(['type' => 'string', 'enum' => ['only']]);
});

it('stops at maxSchemaDepth without recursing forever', function () {
    $schema = (new SchemaBuilder(1))->fromClass(SelfReferencing::class);

    expect($schema['type'])->toBe('object');
});
