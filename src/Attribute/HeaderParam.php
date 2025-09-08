<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class HeaderParam
{
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'string',
        public readonly string $description = '',
        public readonly bool $required = false,
        public readonly mixed $example = null,
        public readonly bool $deprecated = false,
    ) {}
}
