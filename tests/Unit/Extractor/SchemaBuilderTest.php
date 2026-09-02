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

test('nullable union encodes as OpenAPI 3.1 type-array', function () {
    $b = new SchemaBuilder;
    $schema = $b->fromTypeString('string|null');
    expect($schema['type'])->toBe(['string', 'null'])
        ->and($schema)->not->toHaveKey('nullable');
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

/**
 * Three defects that shipped, all of them the same shape: a confident wrong
 * answer where "unknown" was the truth. Measured against a real 205-operation
 * Laravel spec, they accounted for 140 misleading response bodies.
 */
class WrapperResource extends Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray($request): array
    {
        return ['id' => 1];
    }
}

class StaticsFixture
{
    public static string $shared = 'not a payload key';

    public int $id = 0;
}

class WrapperFixture
{
    /** @return \Illuminate\Http\JsonResponse */
    public function wrapped() {}

    /** @return \Illuminate\Http\Resources\Json\JsonResource */
    public function resource() {}
}

test('a response wrapper is not a payload', function () {
    $b = new SchemaBuilder(6, new ApexDocs\Extractor\ComponentRegistry);

    // Reflected, JsonResponse published its own `original` and `exception`.
    expect($b->fromTypeString(Illuminate\Http\JsonResponse::class))->toBe([])
        ->and($b->fromTypeString(Illuminate\Http\RedirectResponse::class))->toBe([])
        ->and($b->fromTypeString(Symfony\Component\HttpFoundation\Response::class))->toBe([]);
});

test('the bare resource base is not a payload, but a subclass is', function () {
    $b = new SchemaBuilder(6, new ApexDocs\Extractor\ComponentRegistry);

    // Reflected, the base published `{resource, with, additional, wrap}` — its
    // own plumbing, and `$wrap` is not even an instance property.
    expect($b->fromTypeString(Illuminate\Http\Resources\Json\JsonResource::class))->toBe([])
        ->and($b->fromTypeString(Illuminate\Database\Eloquent\Model::class))->toBe([])
        // The exclusion is by exact name: a real resource keeps its schema.
        ->and($b->fromTypeString(WrapperResource::class))->toBe(['$ref' => '#/components/schemas/WrapperResource']);
});

test('a static property is never a payload key', function () {
    $registry = new ApexDocs\Extractor\ComponentRegistry;
    (new SchemaBuilder(6, $registry))->fromClass(StaticsFixture::class);

    expect(array_keys($registry->all()['StaticsFixture']['properties']))->toBe(['id']);
});

test('a name that resolves to nothing constrains nothing', function () {
    $b = new SchemaBuilder;

    // Previously `{type: string}` — which is how an unresolved class name came
    // to document a JSON object as a string.
    expect($b->fromTypeString('NoSuchClass'))->toBe([])
        ->and($b->fromTypeString('callable'))->toBe([])
        ->and($b->fromTypeString('resource'))->toBe([]);
});

test('PHPStan scalar refinements keep their JSON type', function () {
    $b = new SchemaBuilder;

    expect($b->fromTypeString('numeric-string'))->toBe(['type' => 'string'])
        ->and($b->fromTypeString('literal-string'))->toBe(['type' => 'string'])
        ->and($b->fromTypeString('positive-int'))->toBe(['type' => 'integer'])
        ->and($b->fromTypeString('non-negative-int'))->toBe(['type' => 'integer'])
        // A ranged int reaches the builder already normalised to `int`.
        ->and($b->fromTypeString(ApexDocs\Extractor\TypeInferrer::normalise('int<0, 100>')))->toBe(['type' => 'integer']);
});

test('an unresolvable return type infers no response body', function () {
    $extractor = new ApexDocs\Extractor\ResponseExtractor(new SchemaBuilder);
    $responses = $extractor->extract(
        new ApexDocs\Route\Route(['GET'], 'api/x', WrapperFixture::class.'@wrapped'),
        new ReflectionClass(WrapperFixture::class),
        new ReflectionMethod(WrapperFixture::class, 'wrapped'),
        'GET',
    );

    expect($responses['200'])->toBe(['description' => 'OK']);
});
