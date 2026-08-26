<?php

declare(strict_types=1);

namespace ApexDocs\Mcp;

use ApexDocs\ApexDocs;

/**
 * Builds the snapshot in the current process.
 *
 * Fast and dependency-free, but PHP cannot reload a class that has already
 * been autoloaded — so edits to controllers or DTOs made while the server is
 * running are not seen until the server restarts. Prefer
 * {@see SubprocessSnapshotProvider} where a CLI can rebuild the application.
 */
final class InProcessSnapshotProvider implements SnapshotProviderInterface
{
    public function __construct(private ApexDocs $apexDocs) {}

    public function snapshot(): Snapshot
    {
        return Snapshot::fromApexDocs($this->apexDocs);
    }
}
