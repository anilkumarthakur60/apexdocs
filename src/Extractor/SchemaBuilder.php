<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

use ReflectionClass;
use ReflectionNamedType;

/**
 * Converts PHP type strings into OpenAPI schema arrays.
 * Pure PHP — no framework dependency.
 */
final class SchemaBuilder
{
    /** @var array<string, true>  prevent infinite recursion */
    private array $building = [];

    private int $maxDepth;

    public function __construct(int $maxDepth = 6)
    {
        $this->maxDepth = $maxDepth;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function fromTypeString(string $type, int $depth = 0): array
    {
        $type = ltrim($type, '\\');

        return match (true) {
            $type === 'void', $type === 'never' => [],
            $type === 'mixed' => [],
            $type === 'null' => ['type' => 'null'],
            $type === 'bool', $type === 'boolean' => ['type' => 'boolean'],
            $type === 'int',  $type === 'integer' => ['type' => 'integer'],
            $type === 'float', $type === 'double', $type === 'number' => ['type' => 'number', 'format' => 'float'],
            $type === 'string' => ['type' => 'string'],
            $type === 'array' => ['type' => 'array', 'items' => new \stdClass],
            str_ends_with($type, '[]') => $this->arrayOf(substr($type, 0, -2), $depth),
            str_contains($type, '|') => $this->union($type, $depth),
            class_exists($type) || interface_exists($type) => $this->fromClass($type, $depth),
            default => ['type' => 'string'],
        };
    }

    /** @return array<string, mixed> */
    public function fromClass(string $class, int $depth = 0): array
    {
        if ($depth >= $this->maxDepth) {
            return ['type' => 'object'];
        }

        if (isset($this->building[$class])) {
            return ['$ref' => '#/components/schemas/'.$this->schemaName($class)];
        }

        $this->building[$class] = true;

        try {
            $ref = new ReflectionClass($class);
        } catch (\ReflectionException) {
            return ['type' => 'object'];
        } finally {
            unset($this->building[$class]);
        }

        if ($ref->isEnum()) {
            return $this->fromEnum($class);
        }

        return $this->fromReflection($ref, $depth);
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
        $schema = ['type' => 'object', 'properties' => []];

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $propType = $prop->getType();
            if (! ($propType instanceof ReflectionNamedType)) {
                $schema['properties'][$prop->getName()] = [];

                continue;
            }

            $s = $this->fromTypeString($propType->getName(), $depth + 1);
            if ($propType->allowsNull()) {
                $s['nullable'] = true;
            }
            $schema['properties'][$prop->getName()] = $s;
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
            if ($nullable) {
                $s['nullable'] = true;
            }

            return $s;
        }

        $schema = ['oneOf' => array_map(fn ($t) => $this->fromTypeString($t, $depth), $parts)];
        if ($nullable) {
            $schema['nullable'] = true;
        }

        return $schema;
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
