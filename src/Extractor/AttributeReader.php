<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

use ReflectionClass;
use ReflectionMethod;

/**
 * Utility: read PHP 8.2 attributes from reflection objects.
 * No framework dependency.
 */
final class AttributeReader
{
    private function __construct() {}

    /**
     * Get the first instance of $attributeClass from a class or method, or null.
     */
    public static function first(
        ReflectionClass|ReflectionMethod $ref,
        string $attributeClass,
    ): ?object {
        $attrs = $ref->getAttributes($attributeClass);

        return $attrs ? $attrs[0]->newInstance() : null;
    }

    /**
     * Get all instances of $attributeClass (for repeatable attributes).
     *
     * @return list<object>
     */
    public static function all(
        ReflectionClass|ReflectionMethod $ref,
        string $attributeClass,
    ): array {
        return array_map(
            fn ($a) => $a->newInstance(),
            $ref->getAttributes($attributeClass),
        );
    }

    public static function has(
        ReflectionClass|ReflectionMethod $ref,
        string $attributeClass,
    ): bool {
        return ! empty($ref->getAttributes($attributeClass));
    }
}
