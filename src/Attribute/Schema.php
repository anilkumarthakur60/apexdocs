<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/**
 * Provide OpenAPI schema metadata for a DTO/resource class.
 * Picked up by SchemaBuilder when the class appears in any response or request body.
 *
 * #[Schema(title: 'User Resource', description: 'Represents a registered user.', example: ['id' => 1, 'name' => 'Alice'])]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Schema
{
    public function __construct(
        public readonly string $title = '',
        public readonly string $description = '',
        public readonly mixed $example = null,
        public readonly bool $deprecated = false,
        public readonly array $externalDocs = [],
    ) {}
}
