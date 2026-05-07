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
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH'];

    public function __construct(
        private SchemaBuilder $schemaBuilder,
        private bool $includeValidationErrors = true,
        private bool $inferErrorResponses = true,
        private bool $includePaginationMeta = true,
        private bool $documentRateLimits = true,
    ) {}

    /**
     * @param  string|null  $httpMethod  the verb being documented; defaults to every
     *                                   verb the route answers
     * @return array<string, array<string, mixed>>
     */
    public function extract(Route $route, ReflectionClass $class, ReflectionMethod $method, ?string $httpMethod = null): array
    {
        $responses = [];

        // 1. Explicit #[ApiResponse] attributes
        foreach (AttributeReader::all($method, ApiResponse::class) as $attr) {
            /** @var ApiResponse $attr */
            $responses[$this->responseKey($attr->status)] = $this->buildFromAttribute($attr);
        }

        // 2. Infer a success response from the return type — but only when the
        //    author has not already declared one. An endpoint documented as
        //    201 Created must not also sprout a phantom 200.
        if (! $this->hasSuccessResponse($responses)) {
            $responses['200'] = $this->inferFromReturnType($method) ?? ['description' => 'OK'];
        }

        // 3. Middleware-based error responses
        if ($this->inferErrorResponses) {
            $this->addMiddlewareResponses($responses, $route);
        }

        // 4. Validation errors for write methods
        if ($this->includeValidationErrors && $this->isWrite($route, $httpMethod)) {
            $responses['422'] ??= ['$ref' => '#/components/responses/ValidationError'];
        }

        ksort($responses, SORT_NATURAL);

        return $responses;
    }

    /**
     * A Responses Object key must be an HTTP status code or the literal
     * "default"; anything outside 100–599 goes into the default bucket rather
     * than producing a key no validator accepts.
     */
    private function responseKey(int $status): string
    {
        return $status >= 100 && $status <= 599 ? (string) $status : 'default';
    }

    /**
     * @param  array<string, array<string, mixed>>  $responses
     */
    private function hasSuccessResponse(array $responses): bool
    {
        foreach (array_keys($responses) as $status) {
            if ((int) $status >= 200 && (int) $status < 300) {
                return true;
            }
        }

        return false;
    }

    private function isWrite(Route $route, ?string $httpMethod): bool
    {
        $methods = $httpMethod !== null ? [strtoupper($httpMethod)] : $route->documentedMethods();

        return array_intersect($methods, self::WRITE_METHODS) !== [];
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
            $response['headers'] = $this->normaliseHeaders($attr->headers);
        }

        if ($attr->examples) {
            if (! isset($response['content'])) {
                $response['content'] = ['application/json' => ['schema' => ['type' => 'object']]];
            }
            $response['content']['application/json']['examples'] = self::normaliseExamples($attr->examples);
        }

        return $response;
    }

    /**
     * OpenAPI requires each entry of `examples` to be an Example Object, so a
     * raw value gets wrapped in `{value: …}`.
     *
     * @param  array<string, mixed>  $examples
     * @return array<string, array<string, mixed>>
     */
    public static function normaliseExamples(array $examples): array
    {
        $out = [];
        foreach ($examples as $name => $example) {
            if (is_array($example) && (isset($example['value']) || isset($example['$ref']) || isset($example['externalValue']))) {
                $out[(string) $name] = $example;

                continue;
            }
            $out[(string) $name] = ['value' => $example];
        }

        return $out;
    }

    /**
     * Header entries may be given as a bare type string or a full Header Object.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, array<string, mixed>>
     */
    private function normaliseHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $header) {
            if (is_string($header)) {
                $out[(string) $name] = ['schema' => ['type' => $header]];

                continue;
            }
            if (is_array($header) && ! isset($header['schema']) && ! isset($header['$ref'])) {
                $header['schema'] = ['type' => 'string'];
            }
            $out[(string) $name] = is_array($header) ? $header : ['schema' => ['type' => 'string']];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function inferFromReturnType(ReflectionMethod $method): ?array
    {
        $typeStr = TypeInferrer::returnType($method);
        if ($typeStr === null) {
            return null;
        }

        $schema = $this->schemaBuilder->fromTypeString($typeStr);
        if ($schema === []) {
            return null;
        }

        return [
            'description' => 'OK',
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $responses
     */
    private function addMiddlewareResponses(array &$responses, Route $route): void
    {
        foreach ($route->middleware as $mw) {
            if (! is_string($mw)) {
                continue;
            }
            if (preg_match('/\bauth\b/i', $mw) === 1) {
                $responses['401'] ??= ['$ref' => '#/components/responses/Unauthorized'];
            }
            if ($this->documentRateLimits
                && (str_contains(strtolower($mw), 'throttle') || str_contains(strtolower($mw), 'rate'))
            ) {
                $responses['429'] ??= ['$ref' => '#/components/responses/TooManyRequests'];
            }
        }
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    private function collectionSchema(string $class): array
    {
        if (! class_exists($class)) {
            return ['type' => 'object'];
        }

        $properties = [
            'data' => ['type' => 'array', 'items' => $this->schemaBuilder->fromClass($class)],
        ];

        if ($this->includePaginationMeta) {
            $properties['meta'] = ['$ref' => '#/components/schemas/PaginationMeta'];
            $properties['links'] = ['$ref' => '#/components/schemas/PaginationLinks'];
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    private function defaultDesc(int $status): string
    {
        return match ($status) {
            200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
            301 => 'Moved Permanently', 302 => 'Found', 304 => 'Not Modified',
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
            404 => 'Not Found', 405 => 'Method Not Allowed', 409 => 'Conflict',
            410 => 'Gone', 415 => 'Unsupported Media Type', 422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
            default => 'Response',
        };
    }
}
