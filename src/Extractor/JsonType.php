<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

/**
 * Coerces a type name written by hand in an attribute into one of the seven
 * JSON Schema types.
 *
 * `#[QueryParam('page', type: 'int')]` is the obvious thing to write and the
 * wrong thing to emit: `int`, `bool`, and `float` are PHP type names, not JSON
 * Schema ones, and a document containing them fails validation.
 */
final class JsonType
{
    public const VALID = ['null', 'boolean', 'object', 'array', 'number', 'string', 'integer'];

    private const ALIASES = [
        'int' => 'integer',
        'long' => 'integer',
        'bool' => 'boolean',
        'float' => 'number',
        'double' => 'number',
        'decimal' => 'number',
        'text' => 'string',
        'str' => 'string',
        'date' => 'string',
        'datetime' => 'string',
        'uuid' => 'string',
        'binary' => 'string',
        'file' => 'string',
        'list' => 'array',
        'map' => 'object',
        'dict' => 'object',
        'mixed' => 'string',
    ];

    private function __construct() {}

    public static function normalize(string $type, string $fallback = 'string'): string
    {
        $type = strtolower(trim($type));

        if (in_array($type, self::VALID, true)) {
            return $type;
        }

        return self::ALIASES[$type] ?? $fallback;
    }
}
