<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/**
 * Link to external documentation for an operation.
 * Renders as the OpenAPI externalDocs field on the operation object.
 *
 * #[ExternalDocs(url: 'https://docs.example.com/users', description: 'Full API guide')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class ExternalDocs
{
    public function __construct(
        public readonly string $url,
        public readonly string $description = '',
    ) {}
}
