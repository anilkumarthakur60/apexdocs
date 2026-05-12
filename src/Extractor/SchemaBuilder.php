<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

use ApexDocs\Attribute\Schema as SchemaAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Converts PHP type strings into OpenAPI schema arrays.
 * Pure PHP — no framework dependency.
 *
 * When a ComponentRegistry is attached, every class schema is registered once
 * and subsequent references return $ref pointers instead of inlining the schema.
 */
final class SchemaBuilder
{
    /** @var array<string, true> prevent infinite recursion during build */
    private array $building = [];

    private int $maxDepth;

    private ?ComponentRegistry $registry;

    public function __construct(int $maxDepth = 6, ?ComponentRegistry $registry = null)
    {
        $this->maxDepth = $maxDepth;
        $this->registry = $registry;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function fromTypeString(string $type, int $depth = 0): array
    {
        $type = ltrim($type, '\\');

        return match (true) {
            $type === 'void', $type === 'never'    => [],
            $type === 'mixed'                      => [],
            $type === 'null'                       => ['type' => 'null'],
            $type === 'bool', $type === 'boolean'  => ['type' => 'boolean'],
            $type === 'int', $type === 'integer'   => ['type' => 'integer'],
            $type === 'float', $type === 'double', $type === 'number'
                                                   => ['type' => 'number', 'format' => 'float'],
            $type === 'string'                     => ['type' => 'string'],
            $type === 'array'                      => ['type' => 'array', 'items' => new \stdClass],
            str_ends_with($type, '[]')             => $this->arrayOf(substr($type, 0, -2), $depth),
            str_contains($type, '&')               => $this->intersection($type, $depth),
            str_contains($type, '|')               => $this->union($type, $depth),
            class_exists($type) || interface_exists($type) => $this->fromClass($type, $depth),
            default                                => ['type' => 'string'],
        };
    }

    /** @return array<string, mixed> */
    public function fromClass(string $class, int $depth = 0): array
    {
        $name = $this->schemaName($class);

        // Already registered in registry → return $ref immediately
        if ($this->registry?->has($class)) {
            return ['$ref' => $this->registry->refFor($class)];
        }

        // Circular reference mid-build → return $ref (schema will be complete on unwind)
        if (isset($this->building[$class])) {
            return ['$ref' => '#/components/schemas/'.$name];
        }

        if ($depth >= $this->maxDepth) {
            return ['type' => 'object'];
        }

        $this->building[$class] = true;

        try {
            $ref = new ReflectionClass($class);
        } catch (\ReflectionException) {
            unset($this->building[$class]);

            return ['type' => 'object'];
        }

        $schema = $ref->isEnum() ? $this->fromEnum($class) : $this->fromReflection($ref, $depth);

        // Apply #[Schema] metadata from the class itself
        $schema = $this->applySchemaAttribute($ref, $schema);

        unset($this->building[$class]);

        if ($this->registry !== null) {
            $this->registry->register($class, $name, $schema);

            return ['$ref' => '#/components/schemas/'.$name];
        }

        return $schema;
    }

    public function schemaName(string $class): string
    {
        return class_basename($class);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function fromEnum(string $class): array
    {
        try {
            $cases = $class::cases();
            $values = [];
            $type = 'string';

            foreach ($cases as $case) {
                if (property_exists($case, 'value')) {
                    $values[] = $case->value;
                    $type = is_int($case->value) ? 'integer' : 'string';
                } else {
                    $values[] = $case->name;
                }
            }

            return ['type' => $type, 'enum' => $values];
        } catch (\Throwable) {
            return ['type' => 'string'];
        }
    }

    private function fromReflection(ReflectionClass $ref, int $depth): array
    {
        $ownProperties = [];
        $required = [];

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            // Only own properties — parent properties handled via allOf
            if ($prop->getDeclaringClass()->getName() !== $ref->getName()) {
                continue;
            }

            $propType = $prop->getType();
            if (! ($propType instanceof ReflectionNamedType)) {
                $ownProperties[$prop->getName()] = [];

                continue;
            }

            $s = $this->fromTypeString($propType->getName(), $depth + 1);
            if ($propType->allowsNull()) {
                $s = self::asNullable($s);
            }
            $ownProperties[$prop->getName()] = $s;

            // A property is required when it is non-nullable and has no default value,
            // or is readonly (must be supplied at construction time).
            if ($this->isRequired($prop, $propType)) {
                $required[] = $prop->getName();
            }
        }

        $ownSchema = ['type' => 'object', 'properties' => $ownProperties];
        if ($required) {
            $ownSchema['required'] = $required;
        }

        // Schema inheritance: if the class has a non-abstract parent with public
        // properties, model it as allOf so the parent schema is reused via $ref.
        $parent = $ref->getParentClass();
        if ($parent !== false && ! $this->isPhpBuiltin($parent->getName()) && $parent->getProperties(ReflectionProperty::IS_PUBLIC)) {
            $parentSchema = $this->fromClass($parent->getName(), $depth + 1);

            return ['allOf' => [$parentSchema, $ownSchema]];
        }

        return $ownSchema;
    }

    private function isRequired(ReflectionProperty $prop, ReflectionNamedType $type): bool
    {
        if ($prop->isReadOnly()) {
            return true;
        }

        if ($type->allowsNull()) {
            return false;
        }

        return ! $prop->hasDefaultValue();
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
        $attrs = $ref->getAttributes(SchemaAttribute::class);
        if (! $attrs) {
            return $schema;
        }

        /** @var SchemaAttribute $meta */
        $meta = $attrs[0]->newInstance();

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

        if (count($parts) === 1) {
            $s = $this->fromTypeString($parts[0], $depth);

            return $nullable ? self::asNullable($s) : $s;
        }

        $schema = ['oneOf' => array_map(fn ($t) => $this->fromTypeString($t, $depth), $parts)];

        return $nullable ? self::asNullable($schema) : $schema;
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

        // No type / $ref to anchor onto — fall back to the union form.
        return ['oneOf' => [$schema, ['type' => 'null']]];
    }

    private function intersection(string $type, int $depth): array
    {
        $parts = array_map('trim', explode('&', $type));

        // Single part after split → treat as plain class
        if (count($parts) === 1) {
            return $this->fromTypeString($parts[0], $depth);
        }

        return ['allOf' => array_map(fn ($t) => $this->fromTypeString($t, $depth), $parts)];
    }

    private function arrayOf(string $itemType, int $depth): array
    {
        return [
            'type' => 'array',
            'items' => $this->fromTypeString($itemType, $depth + 1),
        ];
    }
}

// ── Helper function ────────────────────────────────────────────────────────────

if (! function_exists('class_basename')) {
    function class_basename(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }
}
