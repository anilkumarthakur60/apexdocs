<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

/**
 * Shared schema store for a single document build.
 * SchemaBuilder registers schemas here; SpecBuilder flushes them to components/schemas.
 * Using a registry means every DTO appears once as a $ref instead of being inlined everywhere.
 *
 * Schema names are reserved up-front and de-duplicated: two classes that share
 * a short name (App\Http\Resources\V1\UserResource and …\V2\UserResource) get
 * distinct component names instead of silently overwriting each other.
 */
final class ComponentRegistry
{
    /** @var array<string, array<string, mixed>> built schemas, keyed by FQCN */
    private array $schemas = [];

    /** @var array<string, string> FQCN => reserved component name */
    private array $names = [];

    /** @var array<string, true> component names already handed out */
    private array $taken = [];

    /** Has a complete schema been built and stored for this class? */
    public function has(string $fqcn): bool
    {
        return isset($this->schemas[$fqcn]);
    }

    /**
     * Claim a unique component name for a class. Idempotent: calling it again
     * for the same class returns the previously reserved name.
     */
    public function reserve(string $fqcn, string $preferredName): string
    {
        if (isset($this->names[$fqcn])) {
            return $this->names[$fqcn];
        }

        $base = $this->sanitize($preferredName);
        $name = $base;

        if (isset($this->taken[$name])) {
            // Qualify with the parent namespace segment first (V1UserResource),
            // then fall back to a numeric suffix.
            $qualified = $this->sanitize($this->parentSegment($fqcn).$base);
            $name = $qualified !== $base && ! isset($this->taken[$qualified]) ? $qualified : '';

            if ($name === '') {
                $i = 2;
                while (isset($this->taken[$base.$i])) {
                    $i++;
                }
                $name = $base.$i;
            }
        }

        $this->taken[$name] = true;

        return $this->names[$fqcn] = $name;
    }

    /** Give up a reservation made for a class whose schema could not be built. */
    public function release(string $fqcn): void
    {
        if (isset($this->names[$fqcn]) && ! isset($this->schemas[$fqcn])) {
            unset($this->taken[$this->names[$fqcn]], $this->names[$fqcn]);
        }
    }

    /** @param array<string, mixed> $schema */
    public function register(string $fqcn, array $schema): void
    {
        $this->reserve($fqcn, ClassName::short($fqcn));
        $this->schemas[$fqcn] = $schema;
    }

    public function nameFor(string $fqcn): string
    {
        return $this->names[$fqcn] ?? $this->reserve($fqcn, ClassName::short($fqcn));
    }

    public function refFor(string $fqcn): string
    {
        return '#/components/schemas/'.$this->nameFor($fqcn);
    }

    /** @return array<string, array<string, mixed>> keyed by component name */
    public function all(): array
    {
        $result = [];
        foreach ($this->schemas as $fqcn => $schema) {
            $result[$this->nameFor($fqcn)] = $schema;
        }

        return $result;
    }

    private function parentSegment(string $fqcn): string
    {
        $parts = explode('\\', trim($fqcn, '\\'));
        array_pop($parts);

        return (string) array_pop($parts);
    }

    /** Component keys must match ^[a-zA-Z0-9._-]+$ per the OpenAPI spec. */
    private function sanitize(string $name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9._-]/', '', $name) ?? '';

        return $clean !== '' ? $clean : 'Schema';
    }
}
