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
        return self::all($ref, $attributeClass)[0] ?? null;
    }

    /**
     * Get all instances of $attributeClass (for repeatable attributes).
     *
     * An attribute that cannot be instantiated  wrong target, unknown named
     * argument, a constant that no longer resolves  is skipped rather than
     * allowed to abort the whole documentation build.
     *
     * @return list<object>
     */
    public static function all(
        ReflectionClass|ReflectionMethod $ref,
        string $attributeClass,
    ): array {
        $instances = [];

        foreach ($ref->getAttributes($attributeClass) as $attribute) {
            try {
                $instances[] = $attribute->newInstance();
            } catch (\Throwable) {
                continue;
            }
        }

        return $instances;
    }

    public static function has(
        ReflectionClass|ReflectionMethod $ref,
        string $attributeClass,
    ): bool {
        return ! empty($ref->getAttributes($attributeClass));
    }
}
