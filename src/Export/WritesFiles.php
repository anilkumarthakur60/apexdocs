<?php

declare(strict_types=1);

namespace ApexDocs\Export;

use ApexDocs\Exception\ExporterException;

/**
 * Shared file-writing behaviour for the exporters.
 *
 * A failed write must be loud: a command that prints "✓ Exported" while the
 * target directory is unwritable is worse than a crash.
 */
trait WritesFiles
{
    private function write(string $path, string $contents): void
    {
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw ExporterException::writeFailed($path);
        }

        if (@file_put_contents($path, $contents) === false) {
            throw ExporterException::writeFailed($path);
        }
    }
}
