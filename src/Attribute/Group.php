<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/** Tag every method in this controller under the given group/tag. */
#[Attribute(Attribute::TARGET_CLASS)]
final class Group
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
    ) {}
}
