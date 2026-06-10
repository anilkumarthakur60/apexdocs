<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/**
 * Declare the request body for any controller method  framework-agnostic.
 * The class is reflected to build an OpenAPI schema automatically.
 *
 * #[RequestBody(class: CreateUserDto::class)]
 * #[RequestBody(class: CreateUserDto::class, description: 'User payload', required: true, contentType: 'application/json')]
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RequestBody
{
    public function __construct(
        /** @var class-string */
        public readonly string $class,
        public readonly string $description = '',
        public readonly bool $required = true,
        public readonly string $contentType = 'application/json',
    ) {}
}
