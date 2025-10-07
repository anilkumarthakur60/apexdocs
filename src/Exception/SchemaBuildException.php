<?php

declare(strict_types=1);

namespace ApexDocs\Exception;

use Throwable;

/**
 * Thrown when {@see \ApexDocs\Extractor\SchemaBuilder} cannot build a schema —
 * usually because the underlying class is unloadable or recursion limits were
 * hit.
 */
final class SchemaBuildException extends ApexDocsRuntimeException
{
    public static function forClass(string $class, ?Throwable $previous = null): self
    {
        return new self(sprintf('Failed to build schema for %s.', $class), 0, $previous);
    }
}
