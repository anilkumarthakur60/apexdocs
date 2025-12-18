<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/**
 * Document an individual request body field — like #[QueryParam] but for the body.
 * Use when you don't have a dedicated DTO class.
 *
 * #[BodyParam('name', type: 'string', required: true)]
 * #[BodyParam('role', type: 'string', enum: ['admin','user'], required: true)]
 * #[BodyParam('avatar', type: 'string', format: 'binary')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class BodyParam
{
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'string',
        public readonly string $description = '',
        public readonly bool $required = false,
        public readonly mixed $example = null,
        public readonly ?array $enum = null,
        public readonly string $format = '',
        public readonly bool $nullable = false,
    ) {}
}
