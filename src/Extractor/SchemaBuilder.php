<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

use ApexDocs\Attribute\Schema as SchemaAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Converts PHP type strings into OpenAPI schema arrays.
 * Pure PHP  no framework dependency.
 *
 * When a ComponentRegistry is attached, every class schema is registered once
 * and subsequent references return $ref pointers instead of inlining the schema.
 */
final class SchemaBuilder
{
    private const REF_PREFIX = '#/components/schemas/';

    /** @var array<string, true> prevent infinite recursion during build */
    private array $building = [];

    private int $maxDepth;

    private ?ComponentRegistry $registry;

    public function __construct(int $maxDepth = 6, ?ComponentRegistry $registry = null)
    {
        $this->maxDepth = max(1, $maxDepth);
        $this->registry = $registry;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function fromTypeString(string $type, int $depth = 0): array
    {
        $type = trim(ltrim(trim($type), '\\'));
        $lower = strtolower($type);

        return match (true) {
            $type === ''                             => [],
            $lower === 'void', $lower === 'never'    => [],
            $lower === 'mixed'                       => [],
            $lower === 'null'                        => ['type' => 'null'],
            $lower === 'bool', $lower === 'boolean', $lower === 'true', $lower === 'false'
                                                     => ['type' => 'boolean'],
            $lower === 'int', $lower === 'integer'   => ['type' => 'integer'],
            $lower === 'float', $lower === 'double', $lower === 'number'
                                                     => ['type' => 'number', 'format' => 'float'],
            $lower === 'string', $lower === 'class-string', $lower === 'non-empty-string'
                                                     => ['type' => 'string'],
            $lower === 'array', $lower === 'iterable', $lower === 'list'
                                                     => ['type' => 'array', 'items' => new \stdClass],
            $lower === 'object', $lower === 'stdclass' => ['type' => 'object'],
            str_contains($type, '&')                 => $this->intersection($type, $depth),
            str_contains($type, '|')                 => $this->union($type, $depth),
            str_ends_with($type, '[]')               => $this->arrayOf(substr($type, 0, -2), $depth),
            str_ends_with($type, '{}')               => $this->mapOf(substr($type, 0, -2), $depth),
            class_exists($type) || interface_exists($type) || enum_exists($type)
                                                     => $this->fromClass($type, $depth),
            default                                  => ['type' => 'string'],
        };
    }

    /** @return array<string, mixed> */
    public function fromClass(string $class, int $depth = 0): array
    {
        $class = trim($class, '\\');

        // Already registered in registry → return $ref immediately
        if ($this->registry?->has($class)) {
            return ['$ref' => $this->registry->refFor($class)];
        }

        // Circular reference mid-build → return $ref (schema will be complete on unwind)
        if (isset($this->building[$class])) {
            return ['$ref' => self::REF_PREFIX.$this->schemaName($class)];
        }

        if ($depth >= $this->maxDepth) {
            return ['type' => 'object'];
        }

        // Claim the component name before descending so recursive references
        // point at the same name the finished schema is stored under.
        $name = $this->schemaName($class);
        $this->building[$class] = true;

        try {
            $ref = new ReflectionClass($class);
        } catch (\ReflectionException) {
            unset($this->building[$class]);
            $this->registry?->release($class);

            return ['type' => 'object'];
        }

        $schema = $ref->isEnum() ? $this->fromEnum($class) : $this->fromReflection($ref, $depth);

        // Apply #[Schema] metadata from the class itself
        $schema = $this->applySchemaAttribute($ref, $schema);

        unset($this->building[$class]);

        if ($this->registry !== null) {
            $this->registry->register($class, $schema);

            return ['$ref' => self::REF_PREFIX.$name];
        }

        return $schema;
    }

    /**
     * The components/schemas key this class is published under. Unique per
     * class when a registry is attached, otherwise the bare short name.
     */
    public function schemaName(string $class): string
    {
        $class = trim($class, '\\');

        return $this->registry?->reserve($class, ClassName::short($class)) ?? ClassName::short($class);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function fromEnum(string $class): array
    {
        try {
            $values = [];
            $type = 'string';

            foreach ($class::cases() as $case) {
                if (property_exists($case, 'value')) {
                    $values[] = $case->value;
                    $type = is_int($case->value) ? 'integer' : 'string';
                } else {
                    $values[] = $case->name;
                }
            }

            // An empty `enum` is not a meaningful constraint  omit it.
            return $values === [] ? ['type' => $type] : ['type' => $type, 'enum' => $values];
        } catch (\Throwable) {
            return ['type' => 'string'];
        }
    }

    private function fromReflection(ReflectionClass $ref, int $depth): array
    {
        $ownProperties = [];
        $required = [];

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            // Only own properties  parent properties handled via allOf
            if ($prop->getDeclaringClass()->getName() !== $ref->getName()) {
                continue;
            }

            $propType = $prop->getType();
            $annotated = $this->annotatedType($prop);

            if (! ($propType instanceof ReflectionNamedType)) {
                // Untyped, union, or intersection property: the annotation is
                // the only type information available.
                $ownProperties[$prop->getName()] = $annotated !== null
                    ? $this->fromTypeString($annotated, $depth + 1)
                    : new \stdClass;

                continue;
            }

            // `@var Item[]` beats a bare `array` declaration.
            $useAnnotation = $annotated !== null
                && in_array(strtolower($propType->getName()), ['array', 'iterable', 'mixed', 'object'], true);

            $s = $this->fromTypeString(
                $useAnnotation ? $annotated : $propType->getName(),
                $depth + 1,
            );
            if ($propType->allowsNull() && ($s['type'] ?? null) !== 'null') {
                $s = self::asNullable($s);
            }
            // `mixed` constrains nothing, and an empty array encodes as `[]`
            // rather than the empty schema object `{}`.
            $ownProperties[$prop->getName()] = $s === [] ? new \stdClass : $s;

            // A property is required when it is non-nullable and has no default value,
            // or is readonly (must be supplied at construction time).
            if ($this->isRequired($prop, $propType)) {
                $required[] = $prop->getName();
            }
        }

        $ownSchema = ['type' => 'object'];
        if ($ownProperties !== []) {
            // An empty PHP array encodes as `[]`, and `properties: []` is not a
            // valid JSON Schema. A class with nothing public (an Eloquent model,
            // say) is simply an untyped object.
            $ownSchema['properties'] = $ownProperties;
        }
        if ($required) {
            $ownSchema['required'] = $required;
        }

        // Schema inheritance: when the parent is a DTO of its own, model it as
        // allOf so the parent schema is reused via $ref.
        $parent = $ref->getParentClass();
        if ($parent !== false && $this->isDocumentableParent($parent)) {
            $parentSchema = $this->fromClass($parent->getName(), $depth + 1);

            return ['allOf' => [$parentSchema, $ownSchema]];
        }

        return $ownSchema;
    }

    /**
     * Should a parent class become its own component schema?
     *
     * Only user-space parents with public properties qualify. Framework base
     * classes (Eloquent's Model, Symfony's controllers, …) expose unrelated
     * public state  $timestamps, $exists, $wasRecentlyCreated  that has no
     * business in an API payload schema.
     */
    private function isDocumentableParent(ReflectionClass $parent): bool
    {
        if ($parent->getProperties(ReflectionProperty::IS_PUBLIC) === []) {
            return false;
        }

        if ($this->isPhpBuiltin($parent->getName())) {
            return false;
        }

        $file = $parent->getFileName();

        return $file === false || ! str_contains(str_replace('\\', '/', $file), '/vendor/');
    }

    /**
     * The `@var` type on a property (or on its promoted constructor parameter),
     * normalised for {@see fromTypeString()}.
     */
    private function annotatedType(ReflectionProperty $prop): ?string
    {
        $doc = $prop->getDocComment();
        $type = $doc === false ? null : DocBlockReader::varType($doc);

        if ($type === null && $prop->isPromoted()) {
            $ctor = $prop->getDeclaringClass()->getConstructor();
            $type = $ctor === null
                ? null
                : DocBlockReader::paramTypes($ctor->getDocComment())[$prop->getName()] ?? null;
        }

        if ($type === null || $type === '') {
            return null;
        }

        $normalised = TypeInferrer::normalise($type);

        return $normalised === '' ? null : $normalised;
    }

    /**
     * Is the property required in the JSON representation?
     *
     * Required precisely when a JSON payload omitting it would be invalid:
     *
     *   - Has a default value → optional. Whether the default lives on the
     *     property itself or on the constructor-promoted parameter, missing
     *     keys are filled in.
     *   - Allows null and no default → still required to be PRESENT in the
     *     payload (clients send `null` explicitly), but only for readonly
     *     props. Mutable nullable props with no default are treated as
     *     optional  the conventional Eloquent/DTO read.
     *   - Non-nullable, no default → required.
     *
     * PHP quirk: ReflectionProperty::hasDefaultValue() returns false for
     * promoted constructor parameters even when the parameter declares a
     * default. We probe the matching constructor parameter to recover the
     * truth.
     */
    private function isRequired(ReflectionProperty $prop, ReflectionNamedType $type): bool
    {
        if ($this->propertyHasDefault($prop)) {
            return false;
        }

        if ($type->allowsNull()) {
            return $prop->isReadOnly();
        }

        return true;
    }

    private function propertyHasDefault(ReflectionProperty $prop): bool
    {
        if ($prop->hasDefaultValue()) {
            return true;
        }

        if (! $prop->isPromoted()) {
            return false;
        }

        try {
            $ctor = $prop->getDeclaringClass()->getConstructor();
            if ($ctor === null) {
                return false;
            }
            foreach ($ctor->getParameters() as $param) {
                if ($param->getName() === $prop->getName()) {
                    return $param->isDefaultValueAvailable();
                }
            }
        } catch (\ReflectionException) {
            return false;
        }

        return false;
    }

    private function isPhpBuiltin(string $class): bool
    {
        try {
            return (new ReflectionClass($class))->isInternal();
        } catch (\ReflectionException) {
            return false;
        }
    }

    /** @param array<string, mixed> $schema */
    private function applySchemaAttribute(ReflectionClass $ref, array $schema): array
    {
        // Read through AttributeReader: instantiating an attribute whose
        // arguments no longer resolve throws, and a broken annotation must not
        // take down the whole build.
        $meta = AttributeReader::first($ref, SchemaAttribute::class);
        if (! $meta instanceof SchemaAttribute) {
            return $schema;
        }

        if ($meta->title !== '') {
            $schema['title'] = $meta->title;
        }
        if ($meta->description !== '') {
            $schema['description'] = $meta->description;
        }
        if ($meta->example !== null) {
            $schema['example'] = $meta->example;
        }
        if ($meta->deprecated) {
            $schema['deprecated'] = true;
        }
        if ($meta->externalDocs) {
            $schema['externalDocs'] = $meta->externalDocs;
        }

        return $schema;
    }

    private function union(string $type, int $depth): array
    {
        $parts = array_map('trim', explode('|', $type));
        $nullable = in_array('null', $parts, true);
        $parts = array_values(array_filter($parts, fn ($t) => $t !== 'null'));

        // A `mixed`/`void`/`never` member yields an empty schema, which is not a
        // valid oneOf branch  and it also makes the whole union unconstrained.
        $branches = [];
        foreach ($parts as $part) {
            $branch = $this->fromTypeString($part, $depth);
            if ($branch === []) {
                return [];
            }
            $branches[] = $branch;
        }

        if ($branches === []) {
            return $nullable ? ['type' => 'null'] : [];
        }

        if (count($branches) === 1) {
            return $nullable ? self::asNullable($branches[0]) : $branches[0];
        }

        return $nullable
            ? self::asNullable(['oneOf' => $branches])
            : ['oneOf' => $branches];
    }

    /**
     * Encode a schema as nullable per the spec's declared OpenAPI version.
     * OpenAPI 3.1 uses `type: [..., 'null']`; older 3.0 specs use `nullable: true`.
     * For primitive-typed schemas we use the 3.1 form; for $ref or composite schemas
     * (oneOf/allOf/anyOf) we wrap with `oneOf` so the null branch is explicit.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function asNullable(array $schema): array
    {
        if ($schema === []) {
            // `mixed`/`void` carry no constraint at all, and an empty array
            // encodes as `[]`  not a Schema Object. "Anything, including null"
            // is best expressed by saying nothing.
            return [];
        }

        if (isset($schema['$ref'])) {
            return ['oneOf' => [$schema, ['type' => 'null']]];
        }

        if (isset($schema['oneOf']) || isset($schema['anyOf']) || isset($schema['allOf'])) {
            $key = isset($schema['oneOf']) ? 'oneOf' : (isset($schema['anyOf']) ? 'anyOf' : 'allOf');
            $schema[$key][] = ['type' => 'null'];

            return $schema;
        }

        if (isset($schema['type'])) {
            $type = $schema['type'];
            if (is_array($type)) {
                if (! in_array('null', $type, true)) {
                    $type[] = 'null';
                }
                $schema['type'] = $type;

                return $schema;
            }

            $schema['type'] = [$type, 'null'];

            return $schema;
        }

        // No type / $ref to anchor onto  fall back to the union form.
        return ['oneOf' => [$schema, ['type' => 'null']]];
    }

    private function intersection(string $type, int $depth): array
    {
        $parts = array_map('trim', explode('&', $type));

        // Single part after split → treat as plain class
        if (count($parts) === 1) {
            return $this->fromTypeString($parts[0], $depth);
        }

        $branches = [];
        foreach ($parts as $part) {
            $branch = $this->fromTypeString($part, $depth);
            if ($branch !== []) {
                $branches[] = $branch;
            }
        }

        return match (count($branches)) {
            0 => [],
            1 => $branches[0],
            default => ['allOf' => $branches],
        };
    }

    /**
     * A string-keyed map (`array<string, User>`) is a JSON object whose values
     * all share one schema.
     */
    private function mapOf(string $valueType, int $depth): array
    {
        $value = $this->fromTypeString($valueType, $depth + 1);

        return [
            'type' => 'object',
            'additionalProperties' => $value === [] ? true : $value,
        ];
    }

    private function arrayOf(string $itemType, int $depth): array
    {
        $items = $this->fromTypeString($itemType, $depth + 1);

        return [
            'type' => 'array',
            // `mixed`/`void` yield an empty schema; JSON Schema needs an object
            // there, not an empty array (which encodes as `[]`).
            'items' => $items === [] ? new \stdClass : $items,
        ];
    }
}
