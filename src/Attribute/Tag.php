<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/** Override the tag for a single action. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Tag
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
    ) {}
}
