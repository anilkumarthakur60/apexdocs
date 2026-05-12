<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/**
 * Register a class as an API webhook event.
 *
 * #[Webhook('payment.completed', summary: 'Payment completed')]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Webhook
{
    public function __construct(
        public readonly string $name,
        public readonly string $summary = '',
        public readonly string $description = '',
        public readonly ?array $schema = null,
        public readonly array $tags = [],
    ) {}
}
