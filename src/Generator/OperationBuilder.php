<?php

declare(strict_types=1);

namespace ApexDocs\Generator;

use ApexDocs\Attribute\BodyParam;
use ApexDocs\Attribute\Deprecated;
use ApexDocs\Attribute\Endpoint;
use ApexDocs\Attribute\Example;
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
use ApexDocs\Extractor\JsonType;
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
 * Pure PHP  uses only the contract interfaces for framework-specific parts.
 */
final class OperationBuilder
{
    /** @var array<string, string> tag name => description, collected while building */
    private array $tagDescriptions = [];

    /** @var array<string, int> operationIds handed out, for uniqueness */
    private array $operationIds = [];

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
     * Returns null if the route should be skipped (marked #[Hidden]).
     *
     * @param  string|null  $httpMethod  the verb this operation documents; defaults
     *                                   to the route's primary method
     */
    public function build(Route $route, ?string $httpMethod = null): ?Operation
    {
        $httpMethod = strtolower($httpMethod ?? $route->method());
        [$class, $method] = $route->resolveHandler();

        try {
            $refClass = new ReflectionClass($class);
            $refMethod = $refClass->getMethod($method);
        } catch (ReflectionException) {
            // Closure or otherwise unreflectable handler: still document what
            // the route itself tells us rather than dropping the endpoint.
            return $this->buildFromRouteOnly($route, $httpMethod);
        }

        if (AttributeReader::has($refClass, Hidden::class) || AttributeReader::has($refMethod, Hidden::class)) {
            return null;
        }

        $op = new Operation;

        $this->applyMetadata($op, $route, $refClass, $refMethod, $httpMethod);
        $this->applyParameters($op, $route, $refClass, $refMethod);
        $this->applyRequestBody($op, $route, $refClass, $refMethod, $httpMethod);
        $this->applyResponses($op, $route, $refClass, $refMethod, $httpMethod);
        $this->applySecurity($op, $route, $refClass, $refMethod);
        $this->applyTransformers($op, $route);

        return $op;
    }

    /**
     * Minimal operation for handlers we cannot reflect (closure routes).
     *
     * Path parameters come from the same extractor the reflected path uses, so
     * a closure route and a controller route describe `{id}` identically.
     */
    private function buildFromRouteOnly(Route $route, string $httpMethod): Operation
    {
        $op = (new Operation)
            ->id($this->operationId($route, $httpMethod))
            ->tags([$this->tagFromPath($route)]);

        foreach ($this->parameterExtractor->fromRoute($route) as $param) {
            $op->addParameter($param);
        }

        $op->addResponse('200', ['description' => 'OK']);
        $this->applyTransformers($op, $route);

        return $op;
    }

    private function applyMetadata(
        Operation $op,
        Route $route,
        ReflectionClass $class,
        ReflectionMethod $method,
        string $httpMethod,
    ): void {
        $op->id($this->operationId($route, $httpMethod));

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

    private function applyRequestBody(
        Operation $op,
        Route $route,
        ReflectionClass $class,
        ReflectionMethod $method,
        string $httpMethod,
    ): void {
        $requestExamples = ResponseExtractor::normaliseExamples($this->examplesFor($method, 'request'));

        // 1. Explicit #[RequestBody] attribute  works in any framework
        $rbAttr = AttributeReader::first($method, RequestBodyAttr::class);
        if ($rbAttr !== null) {
            $schema = $this->schemaBuilder->fromClass($rbAttr->class);
            $mediaType = trim($rbAttr->contentType) !== '' ? trim($rbAttr->contentType) : 'application/json';
            $body = [
                'required' => $rbAttr->required,
                'content' => [$mediaType => ['schema' => $schema]],
            ];
            if ($rbAttr->description !== '') {
                $body['description'] = $rbAttr->description;
            }
            $op->requestBody($this->withExamples($body, $requestExamples));

            return;
        }

        // 2. Inline #[BodyParam] attributes  build request body from individual fields
        $bodyParams = array_merge(
            AttributeReader::all($class, BodyParam::class),
            AttributeReader::all($method, BodyParam::class),
        );
        if ($bodyParams) {
            $op->requestBody($this->withExamples($this->buildBodyFromParams($bodyParams), $requestExamples));

            return;
        }

        // 3. Framework-specific validation extractor (FormRequest, MapRequestPayload, etc.)
        if (! in_array(strtoupper($httpMethod), ['POST', 'PUT', 'PATCH'], true)) {
            return;
        }

        if ($this->validationExtractor === null) {
            return;
        }

        $body = $this->validationExtractor->extract($method, $route);
        if ($body !== null) {
            $op->requestBody($this->withExamples($body, $requestExamples));
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, array<string, mixed>>  $examples
     * @return array<string, mixed>
     */
    private function withExamples(array $body, array $examples): array
    {
        if ($examples === [] || ! isset($body['content']) || ! is_array($body['content'])) {
            return $body;
        }

        $contentType = isset($body['content']['application/json'])
            ? 'application/json'
            : (string) array_key_first($body['content']);

        $body['content'][$contentType]['examples'] = $examples;

        return $body;
    }

    /**
     * @param  list<BodyParam>  $params
     * @return array<string, mixed>
     */
    private function buildBodyFromParams(array $params): array
    {
        $properties = [];
        $required = [];

        foreach ($params as $p) {
            if (trim($p->name) === '') {
                continue;
            }

            $type = JsonType::normalize($p->type);
            // OpenAPI 3.1 encodes nullability in the type array; `nullable: true`
            // is 3.0-only and ignored by 3.1 tooling.
            $prop = ['type' => $p->nullable ? [$type, 'null'] : $type];
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
            if ($type === 'array' && ! isset($prop['items'])) {
                $prop['items'] = ['type' => 'string'];
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
            'required' => $required !== [],
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    private function applyResponses(
        Operation $op,
        Route $route,
        ReflectionClass $class,
        ReflectionMethod $method,
        string $httpMethod,
    ): void {
        $responses = $this->responseExtractor->extract($route, $class, $method, $httpMethod);
        $successStatus = $this->firstSuccessStatus($responses);

        $responses = $this->applyProduces($responses, $method, $successStatus);
        $responses = $this->applyResponseHeaders($responses, $class, $method, $successStatus);
        $responses = $this->applyResponseExamples($responses, $method, $successStatus);

        foreach ($responses as $status => $response) {
            $op->addResponse((string) $status, $response);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $responses
     */
    private function firstSuccessStatus(array $responses): ?string
    {
        foreach (array_keys($responses) as $status) {
            if ((int) $status >= 200 && (int) $status < 300) {
                return (string) $status;
            }
        }

        return null;
    }

    /**
     * #[Produces] replaces the media type of the success response.
     *
     * @param  array<string, array<string, mixed>>  $responses
     * @return array<string, array<string, mixed>>
     */
    private function applyProduces(array $responses, ReflectionMethod $method, ?string $status): array
    {
        $producesAttrs = AttributeReader::all($method, Produces::class);
        if ($producesAttrs === [] || $status === null) {
            return $responses;
        }

        $existing = $responses[$status]['content'] ?? [];
        $inheritedSchema = [];
        if (is_array($existing) && $existing !== []) {
            $firstKey = (string) array_key_first($existing);
            $inheritedSchema = $existing['application/json']['schema'] ?? $existing[$firstKey]['schema'] ?? [];
        }

        $content = [];
        foreach ($producesAttrs as $produces) {
            /** @var Produces $produces */
            $mediaType = trim($produces->contentType);
            if ($mediaType === '') {
                // A media type is the map key; an empty one is unaddressable.
                continue;
            }
            $content[$mediaType] = [
                'schema' => $produces->schema ?: ($inheritedSchema ?: ['type' => 'string', 'format' => 'binary']),
            ];
            if ($produces->description !== '' && ($responses[$status]['description'] ?? 'OK') === 'OK') {
                $responses[$status]['description'] = $produces->description;
            }
        }

        if ($content === []) {
            return $responses;
        }

        $responses[$status]['description'] ??= 'OK';
        $responses[$status]['content'] = $content;

        return $responses;
    }

    /**
     * #[ResponseHeader] documents headers on the success response  where the
     * OpenAPI Response Object expects them.
     *
     * @param  array<string, array<string, mixed>>  $responses
     * @return array<string, array<string, mixed>>
     */
    private function applyResponseHeaders(array $responses, ReflectionClass $class, ReflectionMethod $method, ?string $status): array
    {
        $headers = array_merge(
            AttributeReader::all($class, ResponseHeader::class),
            AttributeReader::all($method, ResponseHeader::class),
        );

        $built = [];
        foreach ($headers as $h) {
            /** @var ResponseHeader $h */
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
            if (trim($h->name) === '') {
                continue;
            }
            $built[$h->name] = $header;
        }

        // A Sunset date is an HTTP header in its own right (RFC 8594).
        $sunset = AttributeReader::first($method, SunsetDate::class)
            ?? AttributeReader::first($class, SunsetDate::class);
        if ($sunset !== null) {
            $built['Sunset'] ??= [
                'description' => 'Date this endpoint is scheduled for removal (RFC 8594).',
                'schema' => ['type' => 'string'],
                'example' => $sunset->date,
            ];
        }

        if ($built === [] || $status === null) {
            return $responses;
        }

        $responses[$status]['description'] ??= 'OK';
        // Not array_merge: a header called "123" is a numeric-string key, and
        // array_merge would renumber it  collapsing the map into a list.
        $existingHeaders = is_array($responses[$status]['headers'] ?? null) ? $responses[$status]['headers'] : [];
        $headerMap = [];
        foreach ($built + $existingHeaders as $name => $header) {
            $headerMap[(string) $name] = $existingHeaders[$name] ?? $header;
        }
        $responses[$status]['headers'] = $headerMap;

        return $responses;
    }

    /**
     * @param  array<string, array<string, mixed>>  $responses
     * @return array<string, array<string, mixed>>
     */
    private function applyResponseExamples(array $responses, ReflectionMethod $method, ?string $status): array
    {
        $examples = $this->examplesFor($method, 'response');
        if ($examples === [] || $status === null) {
            return $responses;
        }

        $responses[$status]['description'] ??= 'OK';
        if (! isset($responses[$status]['content'])) {
            $responses[$status]['content'] = ['application/json' => ['schema' => ['type' => 'object']]];
        }

        $contentType = isset($responses[$status]['content']['application/json'])
            ? 'application/json'
            : (string) array_key_first($responses[$status]['content']);

        $responses[$status]['content'][$contentType]['examples'] = array_merge(
            $responses[$status]['content'][$contentType]['examples'] ?? [],
            ResponseExtractor::normaliseExamples($examples),
        );

        return $responses;
    }

    /**
     * #[Example] attributes targeting the request or the response.
     *
     * @return array<string, mixed> name => raw example value
     */
    private function examplesFor(ReflectionMethod $method, string $for): array
    {
        $examples = [];
        foreach (AttributeReader::all($method, Example::class) as $example) {
            /** @var Example $example */
            if (strtolower($example->for) !== $for) {
                continue;
            }
            $entry = ['value' => $example->value];
            if ($example->summary !== '') {
                $entry['summary'] = $example->summary;
            }
            $examples[$example->name] = $entry;
        }

        return $examples;
    }

    private function applySecurity(Operation $op, Route $route, ReflectionClass $class, ReflectionMethod $method): void
    {
        if (AttributeReader::has($method, NoSecurity::class) || AttributeReader::has($class, NoSecurity::class)) {
            $op->security([]);

            return;
        }

        // Method-level #[Security] overrides the controller's declaration;
        // several attributes at the same level are alternatives (OR).
        $secAttrs = AttributeReader::all($method, Security::class)
            ?: AttributeReader::all($class, Security::class);

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

    private function applyTransformers(Operation $op, Route $route): void
    {
        foreach ($this->transformers as $t) {
            if ($t instanceof OperationTransformerInterface) {
                $t->transform($op);
            } else {
                // Closures may declare a second parameter to receive the route.
                $t($op, $route);
            }
        }
    }

    /**
     * Descriptions declared on #[Tag] / #[Group], keyed by tag name, so the
     * document's tag list can carry them.
     *
     * @return array<string, string>
     */
    public function tagDescriptions(): array
    {
        return $this->tagDescriptions;
    }

    /** @return list<string> */
    private function resolveTags(Route $route, ReflectionClass $class, ReflectionMethod $method): array
    {
        $tags = AttributeReader::all($method, Tag::class) ?: AttributeReader::all($class, Tag::class);
        if ($tags) {
            foreach ($tags as $tag) {
                /** @var Tag $tag */
                if ($tag->description !== '') {
                    $this->tagDescriptions[$tag->name] ??= $tag->description;
                }
            }

            return array_values(array_unique(array_map(fn (Tag $t) => $t->name, $tags)));
        }

        $group = AttributeReader::first($class, Group::class);
        if ($group) {
            if ($group->description !== '') {
                $this->tagDescriptions[$group->name] ??= $group->description;
            }

            return [$group->name];
        }

        $name = preg_replace('/Controller$/', '', $class->getShortName()) ?? '';

        return [$name !== '' ? $name : 'General'];
    }

    /**
     * The first meaningful path segment, used to tag routes whose handler we
     * cannot reflect.
     */
    private function tagFromPath(Route $route): string
    {
        foreach (explode('/', trim($route->normalizedPath(), '/')) as $segment) {
            if ($segment !== '' && ! str_starts_with($segment, '{')) {
                if (preg_match('/^v\d+$/i', $segment) === 1) {
                    continue;
                }

                return ucfirst($segment);
            }
        }

        return 'General';
    }

    private function operationId(Route $route, string $httpMethod): string
    {
        if ($route->name() !== '') {
            $id = preg_replace('/[^A-Za-z0-9_]+/', '_', $route->name()) ?? $route->name();

            // One named route can serve several verbs  keep the ids distinct.
            $id = count($route->documentedMethods()) > 1
                ? $httpMethod.'_'.$id
                : $id;

            return $this->uniqueId($id);
        }

        $id = $httpMethod.'_'.trim((string) preg_replace(
            '/[^a-z0-9]+/i',
            '_',
            trim($route->normalizedPath(), '/'),
        ), '_');

        return $this->uniqueId($id);
    }

    /**
     * `operationId` MUST be unique across the document, but squashing
     * punctuation to `_` maps /api/a-b, /api/a.b and /api/a_b onto one id.
     */
    private function uniqueId(string $id): string
    {
        $id = $id !== '' ? $id : 'operation';

        if (! isset($this->operationIds[$id])) {
            $this->operationIds[$id] = 1;

            return $id;
        }

        do {
            $candidate = $id.'_'.(++$this->operationIds[$id]);
        } while (isset($this->operationIds[$candidate]));

        $this->operationIds[$candidate] = 1;

        return $candidate;
    }
}
