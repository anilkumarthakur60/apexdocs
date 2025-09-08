<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

use ApexDocs\Attribute\CookieParam;
use ApexDocs\Attribute\HeaderParam;
use ApexDocs\Attribute\PathParam;
use ApexDocs\Attribute\QueryParam;
use ApexDocs\Route\Route;
use ReflectionClass;
use ReflectionMethod;

/**
 * Extracts path, query, header, and cookie parameters.
 * Pure PHP — reads PHP 8 attributes and route URI patterns.
 */
final class ParameterExtractor
{
    /**
     * @return list<array<string, mixed>>
     */
    public function extract(Route $route, ReflectionClass $class, ReflectionMethod $method): array
    {
        $params = [];

        // 1. Path parameters from URI pattern (always present)
        foreach ($this->fromPath($route) as $p) {
            $params[] = $p;
        }

        // 2. Attributes on the method (highest priority, can override path params)
        foreach ($this->fromAttributes($method) as $p) {
            $params = $this->mergeOrAppend($params, $p);
        }

        // 3. Attributes on the class (applied to all methods)
        foreach ($this->fromAttributes($class) as $p) {
            $params = $this->mergeOrAppend($params, $p);
        }

        return $params;
    }

    /** @return list<array<string, mixed>> */
    private function fromPath(Route $route): array
    {
        $params = [];
        foreach ($route->pathParamNames() as $name) {
            $required = ! str_contains($route->path, '{'.$name.'?}');
            $params[] = [
                'name' => $name,
                'in' => 'path',
                'required' => $required,
                'schema' => ['type' => $this->guessIdType($name)],
            ];
        }

        return $params;
    }

    /** @return list<array<string, mixed>> */
    private function fromAttributes(ReflectionClass|ReflectionMethod $ref): array
    {
        $params = [];

        foreach (AttributeReader::all($ref, QueryParam::class) as $attr) {
            /** @var QueryParam $attr */
            $p = $this->buildParam($attr->name, 'query', $attr->type, $attr->description, $attr->required, $attr->example, $attr->deprecated);
            if ($attr->enum !== null) {
                $p['schema']['enum'] = $attr->enum;
            }
            $params[] = $p;
        }

        foreach (AttributeReader::all($ref, HeaderParam::class) as $attr) {
            /** @var HeaderParam $attr */
            $params[] = $this->buildParam($attr->name, 'header', $attr->type, $attr->description, $attr->required, $attr->example, $attr->deprecated);
        }

        foreach (AttributeReader::all($ref, CookieParam::class) as $attr) {
            /** @var CookieParam $attr */
            $params[] = $this->buildParam($attr->name, 'cookie', $attr->type, $attr->description, $attr->required, $attr->example, false);
        }

        foreach (AttributeReader::all($ref, PathParam::class) as $attr) {
            /** @var PathParam $attr */
            $params[] = $this->buildParam($attr->name, 'path', $attr->type, $attr->description, true, $attr->example, false);
        }

        return $params;
    }

    /** @return array<string, mixed> */
    private function buildParam(
        string $name,
        string $in,
        string $type,
        string $description,
        bool $required,
        mixed $example,
        bool $deprecated,
    ): array {
        $schema = ['type' => $type];
        $param = ['name' => $name, 'in' => $in, 'required' => $required, 'schema' => $schema];

        if ($description !== '') {
            $param['description'] = $description;
        }
        if ($example !== null) {
            $param['example'] = $example;
        }
        if ($deprecated) {
            $param['deprecated'] = true;
        }

        return $param;
    }

    private function guessIdType(string $name): string
    {
        if ($name === 'id' || str_ends_with($name, '_id') || str_ends_with($name, 'Id')) {
            return 'integer';
        }

        return 'string';
    }

    /** Merge a param into the list, replacing any existing param with same name+in. */
    private function mergeOrAppend(array $params, array $new): array
    {
        foreach ($params as &$existing) {
            if ($existing['name'] === $new['name'] && $existing['in'] === $new['in']) {
                $existing = array_merge($existing, $new);

                return $params;
            }
        }
        $params[] = $new;

        return $params;
    }
}
