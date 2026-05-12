<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/**
 * Specify the planned removal date for a deprecated endpoint.
 * Emits x-sunset-date on the operation and documents a Sunset response header.
 * Should be combined with #[Deprecated].
 *
 * #[Deprecated('Use /v2/users instead'), SunsetDate('2026-01-01')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class SunsetDate
{
    public function __construct(
        /** ISO 8601 date string, e.g. "2026-01-01" */
        public readonly string $date,
        public readonly string $migrationGuide = '',
    ) {}
}
