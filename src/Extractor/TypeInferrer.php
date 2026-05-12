<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Resolves the best possible type string for a method's return value.
 * Priority: PHPDoc @return > reflection return type.
 * No framework dependency.
 */
final class TypeInferrer
{
    private function __construct() {}

    /**
     * Returns a normalised type string, e.g.:
     *  "UserResource[]", "string", "int|null", "App\Models\User"
     */
    public static function returnType(ReflectionMethod $method): ?string
    {
        // 1. PHPDoc @return (richer type info, handles generics)
        $docType = DocBlockReader::returnType($method->getDocComment());
        if ($docType !== null) {
            return self::normalise($docType);
        }

        // 2. Reflection return type
        $reflType = $method->getReturnType();
        if ($reflType === null) {
            return null;
        }

        return self::reflectionTypeString($reflType);
    }

    /**
     * Normalise generic/collection type strings to something SchemaBuilder understands.
     *
     * Examples:
     *   "Collection<int,User>"     → "User[]"
     *   "array<string>"            → "string[]"
     *   "list<App\Models\User>"    → "App\Models\User[]"
     *   "\App\Models\User"         → "App\Models\User"
     */
    public static function normalise(string $type): string
    {
        $type = ltrim($type, '\\');

        // Collection<K, V> / LengthAwarePaginator<V>
        if (preg_match('/(?:Collection|Paginator|LengthAwarePaginator)<[^,>]+,\s*([^>]+)>/', $type, $m)) {
            return self::normalise(trim($m[1])).'[]';
        }
        // array<V> / list<V>
        if (preg_match('/(?:array|list)<([^>]+)>/', $type, $m)) {
            return self::normalise(trim($m[1])).'[]';
        }

        return $type;
    }

    private static function reflectionTypeString(\ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            return ($type->allowsNull() && $type->getName() !== 'null')
                ? $type->getName().'|null'
                : $type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(
                fn ($t) => $t->getName(),
                $type->getTypes(),
            ));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(
                fn ($t) => $t->getName(),
                $type->getTypes(),
            ));
        }

        return 'mixed';
    }
}
