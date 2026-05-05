<?php

declare(strict_types=1);

namespace ApexDocs\Generator;

use ApexDocs\Config;
use ApexDocs\Contract\DocumentTransformerInterface;
use ApexDocs\Contract\OperationTransformerInterface;
use ApexDocs\Contract\RouteCollectionInterface;
use ApexDocs\Contract\SecurityDetectorInterface;
use ApexDocs\Contract\ValidationExtractorInterface;
use ApexDocs\Exception\InvalidConfigException;
use ApexDocs\Extractor\ComponentRegistry;
use ApexDocs\Extractor\ParameterExtractor;
use ApexDocs\Extractor\ResponseExtractor;
use ApexDocs\Extractor\SchemaBuilder;
use ApexDocs\Extractor\WebhookScanner;
use ApexDocs\Route\Route;
use ApexDocs\Spec\Document;
use Closure;

/**
 * Orchestrates the full OpenAPI document build.
 * No framework dependencies — accepts interfaces for framework-specific parts.
 */
final class SpecBuilder
{
    private OperationBuilder $operationBuilder;

    private WebhookScanner $webhookScanner;

    private ComponentRegistry $componentRegistry;

    /**
     * @param  list<DocumentTransformerInterface|Closure>  $documentTransformers
     * @param  list<OperationTransformerInterface|Closure>  $operationTransformers
     * @param  array<string, array<string, mixed>>  $webhooks
     */
    public function __construct(
        private Config $config,
        private RouteCollectionInterface $routeCollection,
        private ?ValidationExtractorInterface $validationExtractor,
        private ?SecurityDetectorInterface $securityDetector,
        private array $documentTransformers,
        private array $operationTransformers,
        private array $webhooks,
        private ?Closure $routeFilter,
    ) {
        $this->componentRegistry = new ComponentRegistry;
        $schemaBuilder = new SchemaBuilder($this->config->maxSchemaDepth, $this->componentRegistry);

        $this->operationBuilder = new OperationBuilder(
            parameterExtractor: new ParameterExtractor,
            responseExtractor: new ResponseExtractor(
                schemaBuilder: $schemaBuilder,
                includeValidationErrors: $this->config->includeValidationErrors,
                inferErrorResponses: $this->config->inferErrorResponses,
                includePaginationMeta: $this->config->includePaginationMeta,
                documentRateLimits: $this->config->documentRateLimits,
            ),
            schemaBuilder: $schemaBuilder,
            validationExtractor: $this->validationExtractor,
            // Auto-detection off means the detector's scheme definitions are
            // never published, so its per-route requirements must not be either
            // — an operation naming an undeclared scheme is invalid.
            securityDetector: $this->config->autoDetectSecurity ? $this->securityDetector : null,
            transformers: array_merge(
                $this->operationTransformers,
                $this->instantiate($this->config->operationTransformers, OperationTransformerInterface::class, 'operation_transformers'),
            ),
        );

        $this->webhookScanner = new WebhookScanner($this->config->webhookScanPaths);
    }

    public function build(): Document
    {
        $doc = new Document;

        $this->setInfo($doc);
        $this->setServers($doc);
        $this->addSecuritySchemes($doc);
        $this->addPaths($doc);
        $this->addWebhooks($doc);
        $this->addRegisteredSchemas($doc);
        $this->addStandardComponents($doc);
        $this->applyDocumentTransformers($doc);

        return $doc;
    }

    private function setInfo(Document $doc): void
    {
        $doc->info($this->config->title, $this->config->version, $this->config->description);

        if ($this->config->contact) {
            $doc->addInfoField('contact', $this->config->contact);
        }
        if ($this->config->license) {
            $doc->addInfoField('license', $this->config->license);
        }
        if ($this->config->termsOfService !== '') {
            $doc->addInfoField('termsOfService', $this->config->termsOfService);
        }
    }

    private function setServers(Document $doc): void
    {
        $added = 0;
        foreach ($this->config->servers as $server) {
            $url = is_array($server) ? ($server['url'] ?? '') : $server;
            if (! is_string($url) || $url === '') {
                // `url` is the only required field of a Server Object; a server
                // without one would make the document invalid.
                continue;
            }
            $description = is_array($server) && is_string($server['description'] ?? null)
                ? $server['description']
                : '';
            $variables = is_array($server) && is_array($server['variables'] ?? null)
                ? $server['variables']
                : [];

            $doc->addServer($url, $description, $variables);
            $added++;
        }

        if ($added > 0) {
            return;
        }

        // No servers configured — fall back to a constant default.
        // The builder is a pure function of Config; never derive from $_SERVER
        // (host header injection: attacker-controlled values would land in the
        // cached spec served to every consumer).
        $doc->addServer('http://localhost');
    }

    private function addSecuritySchemes(Document $doc): void
    {
        foreach ($this->config->securitySchemes as $name => $scheme) {
            if (! is_string($name) || ! is_array($scheme)) {
                continue;
            }
            // Component keys are constrained to ^[a-zA-Z0-9._-]+$.
            $key = preg_replace('/[^a-zA-Z0-9._-]/', '', $name) ?? '';
            if ($key !== '') {
                $doc->components()->addSecurityScheme($key, $scheme);
            }
        }

        if ($this->securityDetector !== null && $this->config->autoDetectSecurity) {
            foreach ($this->securityDetector->schemes() as $name => $scheme) {
                $doc->components()->addSecurityScheme($name, $scheme);
            }
        }
    }

    private function addPaths(Document $doc): void
    {
        $tags = [];

        foreach ($this->filteredRoutes() as $route) {
            $path = $route->normalizedPath();

            // A route with no methods at all is treated as GET (Symfony's
            // "any method" form); a route whose only verbs cannot appear in a
            // Path Item Object — PROPFIND, PURGE — is skipped rather than
            // relabelled as something it does not answer.
            $methods = $route->documentedMethods();
            if ($methods === []) {
                if ($route->methods !== []) {
                    continue;
                }
                $methods = ['GET'];
            }

            // One route may answer several verbs (Laravel's match/any, a Symfony
            // route with no method requirement) — each becomes its own operation.
            foreach ($methods as $httpMethod) {
                if ($doc->hasOperation($path, $httpMethod)) {
                    continue;
                }

                $operation = $this->operationBuilder->build($route, $httpMethod);
                if ($operation === null) {
                    continue;
                }

                $doc->addOperation($path, $httpMethod, $operation);

                foreach ($operation->getTags() as $tag) {
                    if (is_string($tag) && $tag !== '') {
                        $tags[$tag] = true;
                    }
                }
            }
        }

        $descriptions = $this->operationBuilder->tagDescriptions();
        foreach (array_keys($tags) as $tag) {
            // PHP coerces a numeric-string array key to int, so cast back.
            $tag = (string) $tag;
            $doc->addTag($tag, $descriptions[$tag] ?? '');
        }
    }

    private function addWebhooks(Document $doc): void
    {
        foreach ($this->webhooks as $name => $spec) {
            if (is_array($spec)) {
                $doc->addWebhook((string) $name, $spec);
            }
        }

        foreach ($this->webhookScanner->scan() as $name => $spec) {
            $doc->addWebhook((string) $name, $spec);
        }
    }

    /**
     * Flush all class schemas collected during the build into components/schemas.
     * This is what makes $ref pointers in operations resolve correctly.
     */
    private function addRegisteredSchemas(Document $doc): void
    {
        foreach ($this->componentRegistry->all() as $name => $schema) {
            if (! $doc->components()->hasSchema($name)) {
                $doc->components()->addSchema($name, $schema);
            }
        }
    }

    /**
     * Shared schemas and responses the extractors $ref by name. Only the ones
     * the current configuration can actually reference are emitted.
     */
    private function addStandardComponents(Document $doc): void
    {
        $c = $doc->components();

        $add = function (string $name, array $schema) use ($c): void {
            // A user DTO that already claimed this name wins — never clobber it.
            if (! $c->hasSchema($name)) {
                $c->addSchema($name, $schema);
            }
        };

        if ($this->config->includeValidationErrors) {
            $add('ValidationError', [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string', 'example' => 'The given data was invalid.'],
                    'errors' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ]);

            $c->addResponse('ValidationError', [
                'description' => 'Validation Error',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError']]],
            ]);
        }

        if ($this->config->inferErrorResponses) {
            $add('UnauthorizedError', [
                'type' => 'object',
                'properties' => ['message' => ['type' => 'string', 'example' => 'Unauthenticated.']],
            ]);

            $c->addResponse('Unauthorized', [
                'description' => 'Unauthenticated',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/UnauthorizedError']]],
            ]);
        }

        if ($this->config->includePaginationMeta) {
            // OpenAPI 3.1 is JSON Schema 2020-12: nullability is a type array,
            // never the 3.0-era `nullable: true` keyword.
            $add('PaginationMeta', [
                'type' => 'object',
                'properties' => [
                    'current_page' => ['type' => 'integer'],
                    'from' => ['type' => ['integer', 'null']],
                    'last_page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                    'to' => ['type' => ['integer', 'null']],
                    'total' => ['type' => 'integer'],
                ],
            ]);

            $add('PaginationLinks', [
                'type' => 'object',
                'properties' => [
                    'first' => ['type' => ['string', 'null'], 'format' => 'uri'],
                    'last' => ['type' => ['string', 'null'], 'format' => 'uri'],
                    'prev' => ['type' => ['string', 'null'], 'format' => 'uri'],
                    'next' => ['type' => ['string', 'null'], 'format' => 'uri'],
                ],
            ]);
        }

        if ($this->config->documentRateLimits) {
            $c->addResponse('TooManyRequests', [
                'description' => 'Too Many Requests',
                'headers' => [
                    'Retry-After' => ['schema' => ['type' => 'integer']],
                    'X-RateLimit-Limit' => ['schema' => ['type' => 'integer']],
                    'X-RateLimit-Remaining' => ['schema' => ['type' => 'integer']],
                ],
                'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]]]],
            ]);
        }
    }

    private function applyDocumentTransformers(Document $doc): void
    {
        $transformers = array_merge(
            $this->documentTransformers,
            $this->instantiate($this->config->documentTransformers, DocumentTransformerInterface::class, 'document_transformers'),
        );

        foreach ($transformers as $t) {
            if ($t instanceof DocumentTransformerInterface) {
                $t->transform($doc);
            } else {
                $t($doc);
            }
        }
    }

    /**
     * Turn config-declared transformer class names into instances, failing loudly
     * on a typo instead of a bare "class not found" further down the stack.
     *
     * @param  array<int, mixed>  $classes
     * @param  class-string  $contract
     * @return list<object>
     */
    private function instantiate(array $classes, string $contract, string $configKey): array
    {
        $instances = [];

        foreach ($classes as $class) {
            if (is_object($class)) {
                $instances[] = $class;

                continue;
            }
            if (! is_string($class) || ! class_exists($class)) {
                throw InvalidConfigException::forField(
                    $configKey,
                    sprintf('"%s" is not a class that exists.', is_string($class) ? $class : get_debug_type($class)),
                );
            }
            if (! is_subclass_of($class, $contract)) {
                throw InvalidConfigException::forField(
                    $configKey,
                    sprintf('%s must implement %s.', $class, $contract),
                );
            }
            $instances[] = new $class;
        }

        return $instances;
    }

    /** @return list<Route> */
    private function filteredRoutes(): array
    {
        return (new RouteSelector($this->config, $this->routeFilter))->select($this->routeCollection->all());
    }
}
