<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/**
 * Override the response content type for an operation.
 * By default all endpoints are assumed to return application/json.
 * Add this when an endpoint returns PDF, CSV, binary, XML, etc.
 *
 * #[Produces('application/pdf')]
 * #[Produces('text/csv', description: 'CSV export')]
 * #[Produces('application/octet-stream', schema: ['type' => 'string', 'format' => 'binary'])]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Produces
{
    public function __construct(
        public readonly string $contentType,
        public readonly string $description = '',
        public readonly array $schema = [],
    ) {}
}
