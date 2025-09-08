<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/** Mark an endpoint as deprecated with an optional migration note. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Deprecated
{
    public function __construct(
        public readonly string $message = '',
        public readonly string $since = '',
    ) {}
}
