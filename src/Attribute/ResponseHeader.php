<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/**
 * Document a response header returned by an endpoint.
 * Added to the 200 response object (or the first successful response).
 *
 * #[ResponseHeader('X-Request-Id', type: 'string', description: 'Unique trace ID for this request')]
 * #[ResponseHeader('X-RateLimit-Remaining', type: 'integer', description: 'Remaining requests in window')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ResponseHeader
{
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'string',
        public readonly string $description = '',
        public readonly mixed $example = null,
        public readonly bool $required = false,
    ) {}
}
