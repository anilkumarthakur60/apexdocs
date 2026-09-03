<?php

declare(strict_types=1);

use ApexDocs\Extractor\DocBlockReader;
use ApexDocs\Extractor\SchemaBuilder;
use ApexDocs\Extractor\TypeInferrer;
use ApexDocs\Tests\Fixtures\Dtos\UserDto;

/**
 * The parser is wired differently for phpstan/phpdoc-parser v1 and v2. When
 * that wiring is wrong, construction throws, every parse returns null, and
 * @return / @param annotations quietly stop contributing to the spec - with no
 * error anywhere. These tests fail loudly instead.
 */
class DocBlockFixture
{
    /**
     * Summary line.
     *
     * Longer description that spans
     * two lines.
     *
     * @param  int  $id  The identifier
     * @return \ApexDocs\Tests\Fixtures\Dtos\UserDto[]
     */
    public function index(int $id): mixed
    {
        return [];
    }

    /** @return \Illuminate\Support\Collection<int, \ApexDocs\Tests\Fixtures\Dtos\UserDto> */
    public function collection(): mixed
    {
        return [];
    }

    public function untyped() {}
}

it('parses a docblock into a node rather than swallowing the failure', function () {
    $doc = (new ReflectionMethod(DocBlockFixture::class, 'index'))->getDocComment();

    expect(DocBlockReader::parse($doc))->not->toBeNull();
});

it('reads summary, description, and param metadata', function () {
    $doc = (new ReflectionMethod(DocBlockFixture::class, 'index'))->getDocComment();

    expect(DocBlockReader::summary($doc))->toBe('Summary line.')
        ->and(DocBlockReader::description($doc))->toBe("Longer description that spans\ntwo lines.")
        ->and(DocBlockReader::paramTypes($doc))->toBe(['id' => 'int'])
        ->and(DocBlockReader::paramDescriptions($doc))->toBe(['id' => 'The identifier']);
});

it('prefers the @return annotation over the reflection type', function () {
    $method = new ReflectionMethod(DocBlockFixture::class, 'index');

    expect(TypeInferrer::returnType($method))->toBe(UserDto::class.'[]');
});

it('unwraps a generic collection to its value type', function () {
    $method = new ReflectionMethod(DocBlockFixture::class, 'collection');

    expect(TypeInferrer::returnType($method))->toBe(UserDto::class.'[]');
});

it('returns null when there is nothing to infer', function () {
    expect(TypeInferrer::returnType(new ReflectionMethod(DocBlockFixture::class, 'untyped')))->toBeNull();
});

it('turns an annotated collection into an array schema of $refs', function () {
    $builder = new SchemaBuilder(6, new ApexDocs\Extractor\ComponentRegistry);
    $schema = $builder->fromTypeString(UserDto::class.'[]');

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['$ref'])->toBe('#/components/schemas/UserDto');
});

it('maps a string-keyed generic to an object with additionalProperties', function () {
    $builder = new SchemaBuilder;
    $schema = $builder->fromTypeString(TypeInferrer::normalise('array<string, int>'));

    expect($schema['type'])->toBe('object')
        ->and($schema['additionalProperties'])->toBe(['type' => 'integer']);
});
