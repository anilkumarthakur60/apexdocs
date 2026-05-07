<?php

declare(strict_types=1);

use ApexDocs\Export\SchemaExample;

/**
 * The API-client exporters used to emit "{}" for every request body built from
 * a DTO, because the body schema is a $ref and the example builder only looked
 * at inline `type`/`properties`.
 */
function exampleSpec(): array
{
    return [
        'components' => [
            'schemas' => [
                'Address' => [
                    'type' => 'object',
                    'properties' => [
                        'city' => ['type' => 'string'],
                        'zip' => ['type' => ['string', 'null']],
                    ],
                ],
                'User' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'address' => ['$ref' => '#/components/schemas/Address'],
                        'roles' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['admin', 'user']]],
                    ],
                ],
                'Employee' => [
                    'allOf' => [
                        ['$ref' => '#/components/schemas/User'],
                        ['type' => 'object', 'properties' => ['salary' => ['type' => 'number']]],
                    ],
                ],
                'Node' => [
                    'type' => 'object',
                    'properties' => ['child' => ['$ref' => '#/components/schemas/Node']],
                ],
            ],
        ],
    ];
}

it('resolves a $ref instead of returning an empty object', function () {
    $example = (new SchemaExample(exampleSpec()))->build(['$ref' => '#/components/schemas/User']);

    expect((array) $example)->toHaveKeys(['id', 'email', 'address', 'roles'])
        ->and(((array) $example)['id'])->toBe(1)
        ->and(((array) $example)['email'])->toBe('user@example.com');
});

it('follows nested $refs and 3.1 nullable type arrays', function () {
    $example = (array) (new SchemaExample(exampleSpec()))->build(['$ref' => '#/components/schemas/User']);

    expect((array) $example['address'])->toBe(['city' => 'string', 'zip' => 'string']);
});

it('prefers the first enum value for a constrained item', function () {
    $example = (array) (new SchemaExample(exampleSpec()))->build(['$ref' => '#/components/schemas/User']);

    expect($example['roles'])->toBe(['admin']);
});

it('merges allOf branches into one object', function () {
    $example = (array) (new SchemaExample(exampleSpec()))->build(['$ref' => '#/components/schemas/Employee']);

    expect($example)->toHaveKeys(['id', 'email', 'salary'])
        ->and($example['salary'])->toBe(1.0);
});

it('picks the non-null branch of a nullable oneOf', function () {
    $example = (new SchemaExample(exampleSpec()))->build([
        'oneOf' => [['type' => 'null'], ['type' => 'integer']],
    ]);

    expect($example)->toBe(1);
});

it('terminates on a self-referencing schema', function () {
    $example = (new SchemaExample(exampleSpec()))->build(['$ref' => '#/components/schemas/Node']);

    expect($example)->toBeObject();
});

it('returns null for an unresolvable pointer rather than throwing', function () {
    expect((new SchemaExample(exampleSpec()))->build(['$ref' => '#/components/schemas/Missing']))->toBeNull();
});

it('honours an author-supplied example over a generated one', function () {
    expect((new SchemaExample)->build(['type' => 'string', 'example' => 'fixed']))->toBe('fixed')
        ->and((new SchemaExample)->build(['type' => 'integer', 'default' => 42]))->toBe(42);
});

it('shapes strings by format', function () {
    $example = new SchemaExample;

    expect($example->build(['type' => 'string', 'format' => 'date']))->toBe('2024-01-31')
        ->and($example->build(['type' => 'string', 'format' => 'uuid']))->toBe('00000000-0000-4000-8000-000000000000')
        ->and($example->build(['type' => 'string', 'format' => 'uri']))->toBe('https://example.com');
});
