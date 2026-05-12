<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/** Explicitly declare the security scheme(s) for this endpoint. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Security
{
    public function __construct(
        public readonly string $scheme,
        public readonly array $scopes = [],
    ) {}
}
