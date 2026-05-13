<?php

declare(strict_types=1);

namespace ApexDocs\Tests\Fixtures\Webhooks;

use ApexDocs\Attribute\Webhook;

#[Webhook(name: 'order.shipped', summary: 'Order shipped')]
final class OrderShippedWebhook
{
    public function __construct(
        public string $orderId = '',
    ) {}
}
