<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/** Override summary and description for a single action method. */
#[Attribute(Attribute::TARGET_METHOD)]
final class Endpoint
{
    public function __construct(
        public readonly string $summary = '',
        public readonly string $description = '',
    ) {}
}
