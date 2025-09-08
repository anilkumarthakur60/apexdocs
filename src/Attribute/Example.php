<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/** Attach a named example to a method's request or response. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Example
{
    public function __construct(
        public readonly string $name,
        public readonly array $value,
        public readonly string $summary = '',
        public readonly string $for = 'response',  // 'request' | 'response'
    ) {}
}
