<?php

declare(strict_types=1);

namespace ApexDocs\Contract;

use ApexDocs\Spec\Operation;

interface OperationTransformerInterface
{
    public function transform(Operation $operation): void;
}
