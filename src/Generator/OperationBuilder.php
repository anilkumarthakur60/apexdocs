<?php

declare(strict_types=1);

namespace ApexDocs\Generator;

use ApexDocs\Attribute\BodyParam;
use ApexDocs\Attribute\Deprecated;
use ApexDocs\Attribute\Endpoint;
use ApexDocs\Attribute\ExternalDocs;
use ApexDocs\Attribute\Group;
use ApexDocs\Attribute\Hidden;
use ApexDocs\Attribute\NoSecurity;
use ApexDocs\Attribute\Produces;
use ApexDocs\Attribute\RequestBody as RequestBodyAttr;
use ApexDocs\Attribute\ResponseHeader;
use ApexDocs\Attribute\Security;
use ApexDocs\Attribute\SunsetDate;
use ApexDocs\Attribute\Tag;
use ApexDocs\Contract\OperationTransformerInterface;
use ApexDocs\Contract\SecurityDetectorInterface;
use ApexDocs\Contract\ValidationExtractorInterface;
use ApexDocs\Extractor\AttributeReader;
use ApexDocs\Extractor\DocBlockReader;
use ApexDocs\Extractor\ParameterExtractor;
use ApexDocs\Extractor\ResponseExtractor;
use ApexDocs\Extractor\SchemaBuilder;
use ApexDocs\Route\Route;
use ApexDocs\Spec\Operation;
use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * Builds a single OpenAPI Operation from a Route.
 * Pure PHP — uses only the contract interfaces for framework-specific parts.
 */
final class OperationBuilder
{
    public function __construct(
        private ParameterExtractor $parameterExtractor,
        private ResponseExtractor $responseExtractor,
        private SchemaBuilder $schemaBuilder,
        private ?ValidationExtractorInterface $validationExtractor,
        private ?SecurityDetectorInterface $securityDetector,
        /** @var list<OperationTransformerInterface|Closure> */
        private array $transformers,
    ) {}

    /**
     * Returns null if the route should be skipped (hidden, unresolvable handler, etc.)
     */
    public function build(Route $route): ?Operation
    {
        [$class, $method] = $route->resolveHandler();

        try {
            $refClass = new ReflectionClass($class);
            $refMethod = $refClass->getMethod($method);
        } catch (ReflectionException) {
            return null;
        }

        if (AttributeReader::has($refClass, Hidden::class) || AttributeReader::has($refMethod, Hidden::class)) {
            return null;
        }

        $op = new Operation;

        $this->applyMetadata($op, $route, $refClass, $refMethod);
        $this->applyParameters($op, $route, $refClass, $refMethod);
        $this->applyRequestBody($op, $route, $refClass, $refMethod);
        $this->applyResponses($op, $route, $refClass, $refMethod);
        $this->applyResponseHeaders($op, $refClass, $refMethod);
        $this->applySecurity($op, $route, $refClass, $refMethod);
        $this->applyTransformers($op);

        return $op;
    }

    private function applyMetadata(Operation $op, Route $route, ReflectionClass $class, ReflectionMethod $method): void
    {
        $op->id($this->operationId($route));

        // Summary & description: #[Endpoint] > PHPDoc
        $endpointAttr = AttributeReader::first($method, Endpoint::class);
        if ($endpointAttr) {
            if ($endpointAttr->summary !== '') {
                $op->summary($endpointAttr->summary);
            }
            if ($endpointAttr->description !== '') {
                $op->description($endpointAttr->description);
            }
        } else {
            $doc = $method->getDocComment();
            if ($sum = DocBlockReader::summary($doc)) {
                $op->summary($sum);
            }
            if ($desc = DocBlockReader::description($doc)) {
                $op->description($desc);
            }
        }

        // Tags
        $op->tags($this->resolveTags($route, $class, $method));

        // Deprecated
        $deprAttr = AttributeReader::first($method, Deprecated::class)
            ?? AttributeReader::first($class, Deprecated::class);
        if ($deprAttr !== null) {
            $op->deprecated();
            if ($deprAttr->message !== '') {
                $op->extend('deprecation-notice', $deprAttr->message);
            }
            if ($deprAttr->since !== '') {
                $op->extend('deprecated-since', $deprAttr->since);
            }
        }

        // Sunset date (planned removal date for deprecated endpoints)
        $sunsetAttr = AttributeReader::first($method, SunsetDate::class)
            ?? AttributeReader::first($class, SunsetDate::class);
        if ($sunsetAttr !== null) {
            $op->extend('sunset-date', $sunsetAttr->date);
            if ($sunsetAttr->migrationGuide !== '') {
                $op->extend('migration-guide', $sunsetAttr->migrationGuide);
            }
        }

        // External docs: method #[ExternalDocs] > class #[ExternalDocs]
        $extDocs = AttributeReader::first($method, ExternalDocs::class)
            ?? AttributeReader::first($class, ExternalDocs::class);
        if ($extDocs !== null) {
            $op->externalDocs($extDocs->url, $extDocs->description);
        }
    }

    private function applyParameters(Operation $op, Route $route, ReflectionClass $class, ReflectionMethod $method): void
    {
        foreach ($this->parameterExtractor->extract($route, $class, $method) as $param) {
            $op->addParameter($param);
        }
    }

    private function applyRequestBody(Operation $op, Route $route, ReflectionClass $class, ReflectionMethod $method): void
    {
        // 1. Explicit #[RequestBody] attribute — works in any framework
        $rbAttr = AttributeReader::first($method, RequestBodyAttr::class);
        if ($rbAttr !== null) {
            $schema = $this->schemaBuilder->fromClass($rbAttr->class);
            $body = [
                'required' => $rbAttr->required,
                'content' => [$rbAttr->contentType => ['schema' => $schema]],
            ];
            if ($rbAttr->description !== '') {
                $body['description'] = $rbAttr->description;
            }
            $op->requestBody($body);

            return;
        }

        // 2. Inline #[BodyParam] attributes — build request body from individual fields
        $bodyParams = array_merge(
            AttributeReader::all($class, BodyParam::class),
            AttributeReader::all($method, BodyParam::class),
        );
        if ($bodyParams) {
            $op->requestBody($this->buildBodyFromParams($bodyParams));

            return;
        }

        // 3. Framework-specific validation extractor (FormRequest, MapRequestPayload, etc.)
        $writes = ['POST', 'PUT', 'PATCH'];
        if (empty(array_intersect(array_map('strtoupper', $route->methods), $writes))) {
            return;
        }

        if ($this->validationExtractor === null) {
            return;
        }

        $body = $this->validationExtractor->extract($method, $route);
        if ($body !== null) {
            $op->requestBody($body);
        }
    }

    /** @param list<BodyParam> $params */
    private function buildBodyFromParams(array $params): array
    {
        $properties = [];
        $required = [];

        foreach ($params as $p) {
            $prop = ['type' => $p->type];
            if ($p->description !== '') {
                $prop['description'] = $p->description;
            }
            if ($p->format !== '') {
                $prop['format'] = $p->format;
            }
            if ($p->example !== null) {
                $prop['example'] = $p->example;
            }
            if ($p->enum !== null) {
                $prop['enum'] = $p->enum;
            }
            if ($p->nullable) {
                $prop['nullable'] = true;
            }
            $properties[$p->name] = $prop;

            if ($p->required) {
                $required[] = $p->name;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required) {
            $schema['required'] = $required;
        }

        return [
            'required' => (bool) $required,
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    private function applyResponses(Operation $op, Route $route, ReflectionClass $class, ReflectionMethod $method): void
    {
        $producesAttrs = AttributeReader::all($method, Produces::class);
        $responses = $this->responseExtractor->extract($route, $class, $method);

        // If #[Produces] is set, replace content type in the 200 response
        if ($producesAttrs) {
            foreach ($producesAttrs as $produces) {
                if (isset($responses['200']['content'])) {
                    $existingSchema = $responses['200']['content']['application/json']['schema']
                        ?? $responses['200']['content'][array_key_first($responses['200']['content'])]['schema']
                        ?? [];

                    unset($responses['200']['content']['application/json']);
                    $responses['200']['content'][$produces->contentType] = ['schema' => $produces->schema ?: $existingSchema];
                    if ($produces->description !== '' && $responses['200']['description'] === 'OK') {
                        $responses['200']['description'] = $produces->description;
                    }
                } else {
                    // No existing content — add the declared type
                    $responses['200']['content'] = [
                        $produces->contentType => [
                            'schema' => $produces->schema ?: ['type' => 'string', 'format' => 'binary'],
                        ],
                    ];
                }
            }
        }

        foreach ($responses as $status => $response) {
            $op->addResponse((string) $status, $response);
        }
    }

    private function applyResponseHeaders(Operation $op, ReflectionClass $class, ReflectionMethod $method): void
    {
        $headers = array_merge(
            AttributeReader::all($class, ResponseHeader::class),
            AttributeReader::all($method, ResponseHeader::class),
        );

        if (! $headers) {
            return;
        }

        $built = [];
        foreach ($headers as $h) {
            $header = ['schema' => ['type' => $h->type]];
            if ($h->description !== '') {
                $header['description'] = $h->description;
            }
            if ($h->example !== null) {
                $header['example'] = $h->example;
            }
            if ($h->required) {
                $header['required'] = true;
            }
            $built[$h->name] = $header;
        }

        // Inject into the operation via extension (transformer can move them into a real response if needed)
        $op->extend('response-headers', $built);
    }

    private function applySecurity(Operation $op, Route $route, ReflectionClass $class, ReflectionMethod $method): void
    {
        if (AttributeReader::has($method, NoSecurity::class) || AttributeReader::has($class, NoSecurity::class)) {
            $op->security([]);

            return;
        }

        $secAttrs = array_merge(
            AttributeReader::all($class, Security::class),
            AttributeReader::all($method, Security::class),
        );
        if ($secAttrs) {
            $op->security(array_map(
                fn (Security $s) => [$s->scheme => $s->scopes],
                $secAttrs,
            ));

            return;
        }

        if ($this->securityDetector !== null) {
            $detected = $this->securityDetector->forRoute($route, $method);
            if ($detected !== null) {
                $op->security($detected);
            }
        }
    }

    private function applyTransformers(Operation $op): void
    {
        foreach ($this->transformers as $t) {
            if ($t instanceof OperationTransformerInterface) {
                $t->transform($op);
            } else {
                $t($op);
            }
        }
    }

    private function resolveTags(Route $route, ReflectionClass $class, ReflectionMethod $method): array
    {
        $methodTags = AttributeReader::all($method, Tag::class);
        if ($methodTags) {
            return [reset($methodTags)->name];
        }

        $classTags = AttributeReader::all($class, Tag::class);
        if ($classTags) {
            return [reset($classTags)->name];
        }

        $group = AttributeReader::first($class, Group::class);
        if ($group) {
            return [$group->name];
        }

        $name = preg_replace('/Controller$/', '', $class->getShortName());

        return [$name ?: 'General'];
    }

    private function operationId(Route $route): string
    {
        if ($route->name() !== '') {
            return str_replace(['.', '-'], '_', $route->name());
        }

        return strtolower($route->method()).'_'.
            preg_replace('/[^a-z0-9]+/i', '_', trim($route->normalizedPath(), '/'));
    }
}
