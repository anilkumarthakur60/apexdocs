<?php

declare(strict_types=1);

namespace ApexDocs\Generator;

use ApexDocs\Attribute\Deprecated;
use ApexDocs\Attribute\Endpoint;
use ApexDocs\Attribute\Group;
use ApexDocs\Attribute\Hidden;
use ApexDocs\Attribute\NoSecurity;
use ApexDocs\Attribute\Security;
use ApexDocs\Attribute\Tag;
use ApexDocs\Contract\OperationTransformerInterface;
use ApexDocs\Contract\SecurityDetectorInterface;
use ApexDocs\Contract\ValidationExtractorInterface;
use ApexDocs\Extractor\AttributeReader;
use ApexDocs\Extractor\DocBlockReader;
use ApexDocs\Extractor\ParameterExtractor;
use ApexDocs\Extractor\ResponseExtractor;
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
        $this->applyRequestBody($op, $route, $refMethod);
        $this->applyResponses($op, $route, $refClass, $refMethod);
        $this->applySecurity($op, $route, $refClass, $refMethod);
        $this->applyTransformers($op);

        return $op;
    }

    private function applyMetadata(Operation $op, Route $route, ReflectionClass $class, ReflectionMethod $method): void
    {
        // Operation ID
        $op->id($this->operationId($route));

        // Summary & description: #[Endpoint] > PHPDoc > nothing
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

        // Tags: method #[Tag] > class #[Tag] > class #[Group] > controller name
        $op->tags($this->resolveTags($route, $class, $method));

        // Deprecated
        $deprAttr = AttributeReader::first($method, Deprecated::class)
            ?? AttributeReader::first($class, Deprecated::class);
        if ($deprAttr !== null) {
            $op->deprecated();
            if ($deprAttr->message !== '') {
                $op->extend('deprecation-notice', $deprAttr->message);
            }
        }
    }

    private function applyParameters(Operation $op, Route $route, ReflectionClass $class, ReflectionMethod $method): void
    {
        foreach ($this->parameterExtractor->extract($route, $class, $method) as $param) {
            $op->addParameter($param);
        }
    }

    private function applyRequestBody(Operation $op, Route $route, ReflectionMethod $method): void
    {
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

    private function applyResponses(Operation $op, Route $route, ReflectionClass $class, ReflectionMethod $method): void
    {
        foreach ($this->responseExtractor->extract($route, $class, $method) as $status => $response) {
            $op->addResponse((string) $status, $response);
        }
    }

    private function applySecurity(Operation $op, Route $route, ReflectionClass $class, ReflectionMethod $method): void
    {
        // #[NoSecurity] = empty array (public endpoint)
        if (AttributeReader::has($method, NoSecurity::class) || AttributeReader::has($class, NoSecurity::class)) {
            $op->security([]);

            return;
        }

        // Explicit #[Security] attributes
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

        // Framework bridge auto-detection
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
        // Method-level
        $methodTags = AttributeReader::all($method, Tag::class);
        if ($methodTags) {
            return [reset($methodTags)->name];
        }

        // Class-level tag
        $classTags = AttributeReader::all($class, Tag::class);
        if ($classTags) {
            return [reset($classTags)->name];
        }

        // Class-level group
        $group = AttributeReader::first($class, Group::class);
        if ($group) {
            return [$group->name];
        }

        // Derive from controller short name: PostController → Post
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
