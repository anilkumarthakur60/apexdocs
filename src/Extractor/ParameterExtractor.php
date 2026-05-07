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
use ReflectionNamedType;

/**
 * Extracts path, query, header, and cookie parameters.
 * Pure PHP — reads PHP 8 attributes, route URI patterns, and the handler signature.
 *
 * Precedence, strongest first: method attributes → class attributes → values
 * derived from the route itself.
 */
final class ParameterExtractor
{
    /**
     * @return list<array<string, mixed>>
     */
    public function extract(Route $route, ReflectionClass $class, ReflectionMethod $method): array
    {
        // Weakest source first: later buckets overwrite the fields they define
        // while keeping the earlier bucket's position in the list, so path
        // parameters stay at the top of the rendered table.
        $buckets = [
            $this->fromPath($route, $method),
            $this->fromAttributes($class),
            $this->fromAttributes($method),
        ];

        $template = $route->pathParamNames();

        $params = [];
        foreach ($buckets as $bucket) {
            foreach ($bucket as $param) {
                // "Each template expression in the path MUST correspond to a
                // path parameter" — and the converse: a path parameter with no
                // template expression makes the document invalid. A class-level
                // #[PathParam] naturally does not apply to every route.
                if ($param['in'] === 'path' && ! in_array($param['name'], $template, true)) {
                    continue;
                }

                $key = $param['in'].':'.$param['name'];
                $params[$key] = isset($params[$key])
                    ? array_merge($params[$key], $param)
                    : $param;
            }
        }

        return array_values($params);
    }

    /**
     * Path parameters derivable from the route alone — for handlers that cannot
     * be reflected, such as closure routes.
     *
     * @return list<array<string, mixed>>
     */
    public function fromRoute(Route $route): array
    {
        return $this->pathParams($route, [], []);
    }

    /** @return list<array<string, mixed>> */
    private function fromPath(Route $route, ReflectionMethod $method): array
    {
        return $this->pathParams(
            $route,
            $this->signatureTypes($method),
            DocBlockReader::paramDescriptions($method->getDocComment()),
        );
    }

    /**
     * @param  array<string, string>  $signature
     * @param  array<string, string>  $descriptions
     * @return list<array<string, mixed>>
     */
    private function pathParams(Route $route, array $signature, array $descriptions): array
    {
        $constraints = $route->paramConstraints();

        $params = [];
        foreach ($route->pathParamNames() as $name) {
            $optional = str_contains($route->path, '{'.$name.'?}');

            $param = [
                'name' => $name,
                'in' => 'path',
                // The spec requires `required: true` for every path parameter:
                // the parameter appears in the template we emit, so it has to be
                // supplied. A router's optional segment is described in prose.
                'required' => true,
                'schema' => $this->pathParamSchema($name, $signature, $constraints),
            ];

            $description = $descriptions[$name] ?? '';
            if ($optional) {
                $description = trim($description.' (optional segment — the route also matches without it)');
            }
            if ($description !== '') {
                $param['description'] = $description;
            }

            $params[] = $param;
        }

        return $params;
    }

    /**
     * Best available type for a path parameter: the handler's own signature
     * beats a router constraint, which beats guessing from the name.
     *
     * @param  array<string, string>  $signature
     * @param  array<string, string>  $constraints
     * @return array<string, mixed>
     */
    private function pathParamSchema(string $name, array $signature, array $constraints): array
    {
        $declared = $signature[$name] ?? null;
        if ($declared !== null) {
            return ['type' => $declared];
        }

        $pattern = $constraints[$name] ?? '';
        if ($pattern !== '') {
            if (preg_match('/^\[?(?:0-9|\\\\d)/', $pattern) === 1) {
                return ['type' => 'integer'];
            }
            if (preg_match('/^[a-z0-9|_-]+$/i', $pattern) === 1 && str_contains($pattern, '|')) {
                return ['type' => 'string', 'enum' => explode('|', $pattern)];
            }

            return ['type' => 'string', 'pattern' => $pattern];
        }

        return ['type' => $this->guessIdType($name)];
    }

    /**
     * Scalar parameter types declared on the controller action, keyed by name.
     * Route-model-bound and service parameters are skipped — their JSON shape
     * is the bound key, not the object.
     *
     * @return array<string, string>
     */
    private function signatureTypes(ReflectionMethod $method): array
    {
        $types = [];
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if (! $type instanceof ReflectionNamedType || ! $type->isBuiltin()) {
                continue;
            }
            $mapped = match ($type->getName()) {
                'int' => 'integer',
                'float' => 'number',
                'bool' => 'boolean',
                'string' => 'string',
                default => null,
            };
            if ($mapped !== null) {
                $types[$param->getName()] = $mapped;
            }
        }

        return $types;
    }

    /** @return list<array<string, mixed>> */
    private function fromAttributes(ReflectionClass|ReflectionMethod $ref): array
    {
        $params = [];

        foreach (AttributeReader::all($ref, QueryParam::class) as $attr) {
            /** @var QueryParam $attr */
            $p = $this->buildParam($attr->name, 'query', $attr->type, $attr->description, $attr->required, $attr->example, $attr->deprecated);
            if ($p === null) {
                continue;
            }
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
            $params[] = $this->buildParam($attr->name, 'cookie', $attr->type, $attr->description, $attr->required, $attr->example, $attr->deprecated);
        }

        foreach (AttributeReader::all($ref, PathParam::class) as $attr) {
            /** @var PathParam $attr */
            $params[] = $this->buildParam($attr->name, 'path', $attr->type, $attr->description, true, $attr->example, $attr->deprecated);
        }

        return array_values(array_filter($params));
    }

    /** @return array<string, mixed>|null */
    private function buildParam(
        string $name,
        string $in,
        string $type,
        string $description,
        bool $required,
        mixed $example,
        bool $deprecated,
    ): ?array {
        if (trim($name) === '') {
            // `name` is required on a Parameter Object; a nameless one would
            // make the whole document invalid.
            return null;
        }

        $schema = ['type' => JsonType::normalize($type)];
        if ($schema['type'] === 'array') {
            // `type: array` with no `items` tells a consumer nothing.
            $schema['items'] = ['type' => 'string'];
        }

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
}
