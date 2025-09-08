<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/**
 * Document a specific response status for a method.
 *
 * #[ApiResponse(200, description: 'OK', resource: UserResource::class)]
 * #[ApiResponse(404, description: 'User not found')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ApiResponse
{
    public function __construct(
        public readonly int $status = 200,
        public readonly string $description = '',
        public readonly ?string $resource = null,
        public readonly bool $collection = false,
        public readonly ?array $schema = null,
        public readonly array $headers = [],
        public readonly array $examples = [],
    ) {}
}
