<?php

declare(strict_types=1);

namespace ApexDocs\Contract;

use ApexDocs\Spec\Document;

interface DocumentTransformerInterface
{
    public function transform(Document $document): void;
}
