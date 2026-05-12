<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

/**
 * Shared schema store for a single document build.
 * SchemaBuilder registers schemas here; SpecBuilder flushes them to components/schemas.
 * Using a registry means every DTO appears once as a $ref instead of being inlined everywhere.
 */
final class ComponentRegistry
{
    /** @var array<string, array{name: string, schema: array<string, mixed>}> keyed by FQCN */
    private array $schemas = [];

    public function has(string $fqcn): bool
    {
        return isset($this->schemas[$fqcn]);
    }

    /** @param array<string, mixed> $schema */
    public function register(string $fqcn, string $name, array $schema): void
    {
        $this->schemas[$fqcn] = ['name' => $name, 'schema' => $schema];
    }

    public function refFor(string $fqcn): string
    {
        return '#/components/schemas/'.($this->schemas[$fqcn]['name'] ?? class_basename($fqcn));
    }

    /** @return array<string, array<string, mixed>> keyed by schema name */
    public function all(): array
    {
        $result = [];
        foreach ($this->schemas as $data) {
            $result[$data['name']] = $data['schema'];
        }

        return $result;
    }
}
