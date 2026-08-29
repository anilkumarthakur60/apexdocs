<?php

declare(strict_types=1);

namespace ApexDocs\Export;

/**
 * Builds a representative example value for a JSON Schema, used by the API
 * client exporters to pre-fill request bodies.
 *
 * Handles the shapes the generator actually emits: `$ref` pointers into
 * components (resolved against the whole document), OpenAPI 3.1 type arrays
 * (`["string","null"]`), and the allOf/oneOf/anyOf combinators produced by DTO
 * inheritance and nullable unions.
 */
final class SchemaExample
{
    private const MAX_DEPTH = 12;

    /** @param array<string, mixed> $spec the full document, for $ref resolution */
    public function __construct(private array $spec = []) {}

    /** @param array<string, mixed> $schema */
    public function build(array $schema, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        if (array_key_exists('example', $schema)) {
            return $schema['example'];
        }
        if (array_key_exists('default', $schema)) {
            return $schema['default'];
        }
        if (isset($schema['examples']) && is_array($schema['examples']) && $schema['examples'] !== []) {
            $first = reset($schema['examples']);

            return is_array($first) && array_key_exists('value', $first) ? $first['value'] : $first;
        }

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $resolved = $this->resolve($schema['$ref']);

            return $resolved === null ? null : $this->build($resolved, $depth + 1);
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            return $this->mergeAllOf($schema['allOf'], $depth);
        }

        foreach (['oneOf', 'anyOf'] as $key) {
            if (isset($schema[$key]) && is_array($schema[$key])) {
                foreach ($schema[$key] as $branch) {
                    // Skip the pure-null branch of a nullable union.
                    if (is_array($branch) && ($branch['type'] ?? null) !== 'null') {
                        return $this->build($branch, $depth + 1);
                    }
                }

                return null;
            }
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }

        return $this->fromType($this->primaryType($schema), $schema, $depth);
    }

    /**
     * @param  array<int, mixed>  $branches
     * @return object|array<int, mixed>|null
     */
    private function mergeAllOf(array $branches, int $depth): mixed
    {
        $merged = [];
        foreach ($branches as $branch) {
            if (! is_array($branch)) {
                continue;
            }
            $value = $this->build($branch, $depth + 1);
            if (is_object($value)) {
                $value = (array) $value;
            }
            if (is_array($value)) {
                $merged = array_merge($merged, $value);
            }
        }

        return (object) $merged;
    }

    /** @param array<string, mixed> $schema */
    private function primaryType(array $schema): string
    {
        $type = $schema['type'] ?? null;

        if (is_array($type)) {
            // OpenAPI 3.1 nullable form  the null branch carries no example.
            $concrete = array_values(array_filter($type, static fn ($t) => $t !== 'null'));
            $type = $concrete[0] ?? 'null';
        }

        if (is_string($type)) {
            return $type;
        }

        return isset($schema['properties']) ? 'object' : 'string';
    }

    /** @param array<string, mixed> $schema */
    private function fromType(string $type, array $schema, int $depth): mixed
    {
        return match ($type) {
            'object' => $this->objectExample($schema, $depth),
            'array' => [$this->build(is_array($schema['items'] ?? null) ? $schema['items'] : [], $depth + 1)],
            'integer' => 1,
            'number' => 1.0,
            'boolean' => true,
            'null' => null,
            default => $this->stringExample($schema),
        };
    }

    /** @param array<string, mixed> $schema */
    private function objectExample(array $schema, int $depth): object
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $out = [];

        foreach ($properties as $name => $property) {
            $out[(string) $name] = is_array($property) ? $this->build($property, $depth + 1) : null;
        }

        return (object) $out;
    }

    /** @param array<string, mixed> $schema */
    private function stringExample(array $schema): string
    {
        return match ($schema['format'] ?? '') {
            'date' => '2024-01-31',
            'date-time' => '2024-01-31T12:00:00Z',
            'email' => 'user@example.com',
            'uri', 'url' => 'https://example.com',
            'uuid' => '00000000-0000-4000-8000-000000000000',
            'binary' => '',
            default => 'string',
        };
    }

    /** @return array<string, mixed>|null */
    private function resolve(string $ref): ?array
    {
        if (! str_starts_with($ref, '#/')) {
            return null;
        }

        $node = $this->spec;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return is_array($node) ? $node : null;
    }
}
