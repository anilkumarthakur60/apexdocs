<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Resolves the best possible type string for a method's return value.
 * Priority: PHPDoc @return > reflection return type.
 * No framework dependency.
 */
final class TypeInferrer
{
    /**
     * Generic wrappers whose value type is what actually appears in the JSON
     * payload. Matched on the short name, case-insensitively, so userland
     * subclasses (`UserCollection extends ResourceCollection`) work too.
     */
    private const ITERABLE_GENERICS = [
        'array', 'list', 'non-empty-array', 'non-empty-list', 'iterable',
        'traversable', 'generator', 'arrayobject', 'arrayiterator',
        'collection', 'eloquentcollection', 'supportcollection', 'arraycollection',
        'paginator', 'lengthawarepaginator', 'cursorpaginator', 'simplepaginator',
        'resourcecollection', 'anonymousresourcecollection', 'collectionitem',
    ];

    private function __construct() {}

    /**
     * Is this class one of the wrappers whose JSON form is a list?
     *
     * `Collection<Post>` is unwrapped to `Post[]` by {@see normalise()}. Written
     * without its generic, the same class would otherwise be reflected as an
     * object with no properties - a `Collection` component schema that says
     * nothing. It is an array of something unknown, and that is worth saying.
     *
     * The name is not enough on its own: a DTO of one's own called `Collection`
     * is an object like any other. It must also actually be iterable.
     */
    public static function isIterableWrapper(string $class): bool
    {
        $class = trim($class, '\\');

        return in_array(strtolower(ClassName::short($class)), self::ITERABLE_GENERICS, true)
            && is_a($class, \Traversable::class, true);
    }

    /**
     * Returns a normalised type string, e.g.:
     *  "UserResource[]", "string", "int|null", "App\Models\User"
     */
    public static function returnType(ReflectionMethod $method): ?string
    {
        // 1. PHPDoc @return (richer type info, handles generics)
        $docType = DocBlockReader::returnType($method->getDocComment());
        if ($docType !== null && $docType !== '') {
            // A docblock is written in the vocabulary of its own file, so
            // `@return UserResource` means the imported one. Left unresolved it
            // matches no class, and a JSON payload ends up documented as the
            // fallback type instead of as a schema.
            return NameResolver::forClass($method->getDeclaringClass())
                ->resolveTypeString(self::normalise($docType));
        }

        // 2. Reflection return type - already fully qualified, so only the
        //    relative keywords need a class to point at.
        $reflType = $method->getReturnType();
        if ($reflType === null) {
            return null;
        }

        $type = self::normalise(self::reflectionTypeString($reflType));

        return in_array(strtolower($type), ['self', 'static', '$this'], true)
            ? $method->getDeclaringClass()->getName()
            : $type;
    }

    /**
     * Normalise generic/collection type strings to something SchemaBuilder
     * understands. `[]` marks a list, `{}` marks a string-keyed object map.
     *
     * Examples:
     *   "Collection<int,User>"      → "User[]"
     *   "Collection<User>"          → "User[]"
     *   "array<string, User>"       → "User{}"
     *   "list<App\Models\User>"     → "App\Models\User[]"
     *   "array<int, array<int, T>>" → "T[][]"
     *   "\App\Models\User"          → "App\Models\User"
     */
    public static function normalise(string $type): string
    {
        $type = ltrim(trim($type), '\\');
        if ($type === '') {
            return $type;
        }

        // Unions/intersections: normalise each member independently.
        foreach (['|', '&'] as $sep) {
            $parts = self::splitTopLevel($type, $sep);
            if (count($parts) > 1) {
                return implode($sep, array_map([self::class, 'normalise'], $parts));
            }
        }

        // Trim PHPStan/Psalm noise the schema layer has no use for.
        $type = trim($type, '()');
        if (str_ends_with($type, '[]')) {
            return self::normalise(substr($type, 0, -2)).'[]';
        }

        $lt = strpos($type, '<');
        if ($lt === false || ! str_ends_with($type, '>')) {
            return $type;
        }

        $base = ltrim(substr($type, 0, $lt), '\\');
        $args = array_values(array_filter(array_map(
            'trim',
            self::splitTopLevel(substr($type, $lt + 1, -1), ','),
        ), static fn (string $a): bool => $a !== ''));

        if ($args === []) {
            return $base;
        }

        if (! in_array(strtolower(ClassName::short($base)), self::ITERABLE_GENERICS, true)) {
            // Unknown generic wrapper  document the wrapper class itself
            // rather than guessing at its shape.
            return $base;
        }

        $value = self::normalise((string) end($args));

        // array<string, T> is a JSON object keyed by string, not a list.
        if (count($args) >= 2 && ! self::isIntegerKey($args[0])) {
            return $value.'{}';
        }

        return $value.'[]';
    }

    private static function isIntegerKey(string $key): bool
    {
        return in_array(strtolower(trim($key)), ['int', 'integer', 'array-key', 'positive-int', 'non-negative-int'], true);
    }

    /**
     * Split on $sep, ignoring separators nested inside <>, (), or [].
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $type, string $sep): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $len = strlen($type);

        for ($i = 0; $i < $len; $i++) {
            $char = $type[$i];
            if ($char === '<' || $char === '(' || $char === '{') {
                $depth++;
            } elseif ($char === '>' || $char === ')' || $char === '}') {
                $depth--;
            }

            if ($char === $sep && $depth <= 0) {
                $parts[] = trim($buffer);
                $buffer = '';

                continue;
            }
            $buffer .= $char;
        }
        $parts[] = trim($buffer);

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    private static function reflectionTypeString(ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            return ($type->allowsNull() && $type->getName() !== 'null' && $type->getName() !== 'mixed')
                ? $type->getName().'|null'
                : $type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            // PHP 8.2 DNF types put intersections inside unions, so members are
            // not necessarily named types.
            return implode('|', array_map([self::class, 'reflectionTypeString'], $type->getTypes()));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map([self::class, 'reflectionTypeString'], $type->getTypes()));
        }

        return 'mixed';
    }
}
