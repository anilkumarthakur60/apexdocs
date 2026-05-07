<?php

declare(strict_types=1);

namespace ApexDocs\Tests\Unit\Extractor\V1 {
    class UserResource
    {
        public string $name = '';
    }
}

namespace ApexDocs\Tests\Unit\Extractor\V2 {
    class UserResource
    {
        public int $id = 0;

        public string $email = '';
    }
}

namespace {
    use ApexDocs\Extractor\ComponentRegistry;
    use ApexDocs\Extractor\SchemaBuilder;

    /**
     * Two classes with the same short name used to collide: both got the same
     * $ref while only the last-registered schema survived, so half the document
     * silently pointed at the wrong shape.
     */
    it('gives colliding short names distinct component names', function () {
        $registry = new ComponentRegistry;
        $builder = new SchemaBuilder(6, $registry);

        $v1 = $builder->fromClass(ApexDocs\Tests\Unit\Extractor\V1\UserResource::class);
        $v2 = $builder->fromClass(ApexDocs\Tests\Unit\Extractor\V2\UserResource::class);

        expect($v1['$ref'])->not->toBe($v2['$ref']);

        $schemas = $registry->all();
        expect($schemas)->toHaveCount(2);

        $first = $schemas[basename(str_replace('\\', '/', $v1['$ref']))];
        $second = $schemas[basename(str_replace('\\', '/', $v2['$ref']))];

        expect(array_keys($first['properties']))->toBe(['name'])
            ->and(array_keys($second['properties']))->toBe(['id', 'email']);
    });

    it('reserves a name once per class', function () {
        $registry = new ComponentRegistry;

        expect($registry->reserve('App\\Dto\\Thing', 'Thing'))->toBe('Thing')
            ->and($registry->reserve('App\\Dto\\Thing', 'Thing'))->toBe('Thing')
            ->and($registry->reserve('Other\\Ns\\Thing', 'Thing'))->not->toBe('Thing');
    });

    it('strips characters that are not legal in a component key', function () {
        $registry = new ComponentRegistry;

        expect($registry->reserve('X', 'Some Name!'))->toBe('SomeName');
    });

    it('resolves a leading-backslash class to the same schema', function () {
        $registry = new ComponentRegistry;
        $builder = new SchemaBuilder(6, $registry);

        $builder->fromClass(ApexDocs\Tests\Unit\Extractor\V1\UserResource::class);
        $builder->fromClass('\\'.ApexDocs\Tests\Unit\Extractor\V1\UserResource::class);

        expect($registry->all())->toHaveCount(1);
    });
}
