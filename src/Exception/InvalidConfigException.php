<?php

declare(strict_types=1);

namespace ApexDocs\Exception;

/**
 * Thrown when a {@see \ApexDocs\Config} value is rejected by validation —
 * e.g. unknown UI backend, malformed SemVer version, unreadable export path.
 */
final class InvalidConfigException extends ApexDocsRuntimeException
{
    public static function forField(string $field, string $reason): self
    {
        return new self(sprintf('Invalid ApexDocs config (%s): %s', $field, $reason));
    }
}
