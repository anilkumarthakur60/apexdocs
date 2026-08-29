<?php

declare(strict_types=1);

namespace ApexDocs\Exception;

use Throwable;

/**
 * Thrown when an exporter (JSON/YAML/Postman/Insomnia/Bruno) fails to write
 * its output  typically a filesystem permission or unencodable value.
 */
final class ExporterException extends ApexDocsRuntimeException
{
    public static function writeFailed(string $path, ?Throwable $previous = null): self
    {
        return new self(sprintf('Failed to write export to "%s".', $path), 0, $previous);
    }
}
