<?php

declare(strict_types=1);

namespace ApexDocs\Export;

/**
 * Shared traversal helpers for the API-client exporters (Postman, Insomnia,
 * Bruno). They all need the same three things: operations grouped by tag,
 * parameters filtered by location, and a JSON body example.
 */
trait CollectionExport
{
    /** HTTP verbs that may appear as keys of a Path Item Object. */
    private const HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, list<array{0: string, 1: string, 2: array<string, mixed>}>>
     */
    private function groupByTag(array $spec): array
    {
        $groups = [];

        foreach (($spec['paths'] ?? []) as $path => $methods) {
            if (! is_string($path) || ! is_array($methods)) {
                continue;
            }
            foreach ($methods as $method => $op) {
                // A path item also holds `parameters`, `summary`, `$ref`  only
                // the verb keys are operations.
                if (! is_string($method) || ! in_array(strtolower($method), self::HTTP_METHODS, true) || ! is_array($op)) {
                    continue;
                }
                $tag = is_string($op['tags'][0] ?? null) ? $op['tags'][0] : 'General';
                $groups[$tag][] = [$path, strtoupper($method), $op];
            }
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $op
     * @return list<array<string, mixed>>
     */
    private function parametersIn(array $op, string $in): array
    {
        $out = [];
        foreach (($op['parameters'] ?? []) as $param) {
            if (is_array($param) && ($param['in'] ?? '') === $in && isset($param['name'])) {
                $out[] = $param;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $param */
    private function paramValue(array $param): string
    {
        if (array_key_exists('example', $param)) {
            return $this->scalar($param['example']);
        }

        $schema = is_array($param['schema'] ?? null) ? $param['schema'] : [];
        foreach (['example', 'default'] as $key) {
            if (array_key_exists($key, $schema)) {
                return $this->scalar($schema[$key]);
            }
        }
        if (isset($schema['enum'][0])) {
            return $this->scalar($schema['enum'][0]);
        }

        return '';
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Pretty-printed JSON body example for a media-type object.
     *
     * @param  mixed  $mediaType
     */
    private function jsonBody($mediaType, SchemaExample $example): string
    {
        $schema = is_array($mediaType) && is_array($mediaType['schema'] ?? null) ? $mediaType['schema'] : [];

        // An author-supplied example beats a generated one.
        if (is_array($mediaType) && is_array($mediaType['examples'] ?? null) && $mediaType['examples'] !== []) {
            $first = reset($mediaType['examples']);
            $value = is_array($first) && array_key_exists('value', $first) ? $first['value'] : $first;

            return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        if (is_array($mediaType) && array_key_exists('example', $mediaType)) {
            return (string) json_encode($mediaType['example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return (string) json_encode($example->build($schema), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Properties of a form-ish media type, with any $ref resolved first.
     *
     * @param  mixed  $mediaType
     * @return array<string, array<string, mixed>>
     */
    private function propertiesOf($mediaType, SchemaExample $example): array
    {
        $schema = is_array($mediaType) && is_array($mediaType['schema'] ?? null) ? $mediaType['schema'] : [];
        $built = $example->build($schema);
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        if ($properties === [] && is_object($built)) {
            // $ref or allOf  fall back to the generated keys with no metadata.
            foreach (array_keys((array) $built) as $key) {
                $properties[(string) $key] = [];
            }
        }

        $out = [];
        foreach ($properties as $name => $property) {
            $out[(string) $name] = is_array($property) ? $property : [];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, array<string, mixed>>
     */
    private function securitySchemes(array $spec): array
    {
        $schemes = $spec['components']['securitySchemes'] ?? [];
        if (! is_array($schemes)) {
            return [];
        }

        $out = [];
        foreach ($schemes as $name => $scheme) {
            if (is_string($name) && is_array($scheme)) {
                $out[$name] = $scheme;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $op */
    private function itemName(string $path, array $op): string
    {
        $name = '';
        foreach (['summary', 'operationId'] as $key) {
            if (is_string($op[$key] ?? null) && $op[$key] !== '') {
                $name = $op[$key];
                break;
            }
        }
        if ($name === '') {
            $name = $path;
        }

        return ($op['deprecated'] ?? false) ? $name.' [DEPRECATED]' : $name;
    }
}
