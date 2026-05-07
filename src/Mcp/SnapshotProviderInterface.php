<?php

declare(strict_types=1);

namespace ApexDocs\Mcp;

interface SnapshotProviderInterface
{
    /**
     * Produce a fresh snapshot of the application's API documentation.
     *
     * @throws \RuntimeException when the snapshot cannot be produced
     */
    public function snapshot(): Snapshot;
}
