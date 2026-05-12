<?php

declare(strict_types=1);

namespace ApexDocs\Extractor;

use ApexDocs\Attribute\ApiResponse;
use ApexDocs\Route\Route;
use ReflectionClass;
use ReflectionMethod;

/**
 * Extracts OpenAPI responses from attributes, return type, and route middleware.
 * Pure PHP — no framework dependency.
 */
final class ResponseExtractor
{
    public function __construct(private SchemaBuilder $schemaBuilder) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function extract(Route $route, ReflectionClass $class, ReflectionMethod $method): array
    {
        $responses = [];

        // 1. Explicit #[ApiResponse] attributes
        foreach (AttributeReader::all($method, ApiResponse::class) as $attr) {
            /** @var ApiResponse $attr */
            $responses[(string) $attr->status] = $this->buildFromAttribute($attr);
        }

        // 2. Infer 200 from return type if not explicitly defined
        if (! isset($responses['200'])) {
            $inferred = $this->inferFromReturnType($method);
            $responses['200'] = $inferred ?? ['description' => 'OK'];
        }

        // 3. Middleware-based error responses
        $this->addMiddlewareResponses($responses, $route);

        // 4. Validation errors for write methods
        $writes = ['POST', 'PUT', 'PATCH'];
        if (! empty(array_intersect(array_map('strtoupper', $route->methods), $writes))) {
            $responses['422'] ??= ['$ref' => '#/components/responses/ValidationError'];
        }

        return $responses;
    }

    /** @return array<string, mixed> */
    private function buildFromAttribute(ApiResponse $attr): array
    {
        $response = ['description' => $attr->description ?: $this->defaultDesc($attr->status)];

        if ($attr->schema !== null) {
            $response['content'] = ['application/json' => ['schema' => $attr->schema]];
        } elseif ($attr->resource !== null) {
            $schema = $attr->collection
                ? $this->collectionSchema($attr->resource)
                : $this->resourceSchema($attr->resource);
            $response['content'] = ['application/json' => ['schema' => $schema]];
        }

        if ($attr->headers) {
            $response['headers'] = $attr->headers;
        }

        if ($attr->examples) {
            if (! isset($response['content'])) {
                $response['content'] = ['application/json' => ['schema' => ['type' => 'object']]];
            }
            $response['content']['application/json']['examples'] = $attr->examples;
        }

        return $response;
    }

    private function inferFromReturnType(ReflectionMethod $method): ?array
    {
        $typeStr = TypeInferrer::returnType($method);
        if ($typeStr === null) {
            return null;
        }

        $schema = $this->schemaBuilder->fromTypeString(TypeInferrer::normalise($typeStr));
        if (empty($schema)) {
            return null;
        }

        return [
            'description' => 'OK',
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    private function addMiddlewareResponses(array &$responses, Route $route): void
    {
        foreach ($route->middleware as $mw) {
            if (str_contains($mw, 'auth') && ! isset($responses['401'])) {
                $responses['401'] = ['$ref' => '#/components/responses/Unauthorized'];
            }
            if (str_contains($mw, 'throttle') && ! isset($responses['429'])) {
                $responses['429'] = ['$ref' => '#/components/responses/TooManyRequests'];
            }
        }
    }

    private function resourceSchema(string $class): array
    {
        if (! class_exists($class)) {
            return ['type' => 'object'];
        }

        return [
            'type' => 'object',
            'properties' => ['data' => $this->schemaBuilder->fromClass($class)],
        ];
    }

    private function collectionSchema(string $class): array
    {
        if (! class_exists($class)) {
            return ['type' => 'object'];
        }

        return [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => $this->schemaBuilder->fromClass($class)],
                'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                'links' => ['$ref' => '#/components/schemas/PaginationLinks'],
            ],
        ];
    }

    private function defaultDesc(int $status): string
    {
        return match ($status) {
            200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
            404 => 'Not Found', 409 => 'Conflict', 422 => 'Unprocessable Entity',
            429 => 'Too Many Requests', 500 => 'Internal Server Error',
            default => 'Response',
        };
    }
}
