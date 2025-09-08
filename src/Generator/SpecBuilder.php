<?php

declare(strict_types=1);

namespace ApexDocs\Generator;

use ApexDocs\Config;
use ApexDocs\Contract\DocumentTransformerInterface;
use ApexDocs\Contract\OperationTransformerInterface;
use ApexDocs\Contract\RouteCollectionInterface;
use ApexDocs\Contract\SecurityDetectorInterface;
use ApexDocs\Contract\ValidationExtractorInterface;
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

    /** @param list<DocumentTransformerInterface|Closure>  $documentTransformers */
    /** @param list<OperationTransformerInterface|Closure> $operationTransformers */
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
        $schemaBuilder = new SchemaBuilder($this->config->maxSchemaDepth);

        $this->operationBuilder = new OperationBuilder(
            parameterExtractor: new ParameterExtractor,
            responseExtractor: new ResponseExtractor($schemaBuilder),
            validationExtractor: $this->validationExtractor,
            securityDetector: $this->securityDetector,
            transformers: $this->operationTransformers,
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
        if (! empty($this->config->servers)) {
            foreach ($this->config->servers as $s) {
                $doc->addServer($s['url'] ?? '', $s['description'] ?? '');
            }
        } else {
            $doc->addServer(
                isset($_SERVER['HTTP_HOST'])
                    ? (isset($_SERVER['HTTPS']) ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST']
                    : 'http://localhost',
            );
        }
    }

    private function addSecuritySchemes(Document $doc): void
    {
        // From config
        foreach ($this->config->securitySchemes as $name => $scheme) {
            $doc->components()->addSecurityScheme($name, $scheme);
        }

        // From framework bridge
        if ($this->securityDetector !== null && $this->config->autoDetectSecurity) {
            foreach ($this->securityDetector->schemes() as $name => $scheme) {
                $doc->components()->addSecurityScheme($name, $scheme);
            }
        }
    }

    private function addPaths(Document $doc): void
    {
        $tags = [];
        $routes = $this->filteredRoutes();

        foreach ($routes as $route) {
            $operation = $this->operationBuilder->build($route);
            if ($operation === null) {
                continue;
            }

            $doc->addOperation($route->normalizedPath(), $route->method(), $operation);

            foreach ($operation->getTags() as $tag) {
                $tags[$tag] = true;
            }
        }

        foreach (array_keys($tags) as $tag) {
            $doc->addTag($tag);
        }
    }

    private function addWebhooks(Document $doc): void
    {
        // Manually registered
        foreach ($this->webhooks as $name => $spec) {
            $doc->addWebhook($name, $spec);
        }

        // Auto-scanned #[Webhook] classes
        foreach ($this->webhookScanner->scan() as $name => $spec) {
            $doc->addWebhook($name, $spec);
        }
    }

    private function addStandardComponents(Document $doc): void
    {
        $c = $doc->components();

        // Standard schemas
        $c->addSchema('ValidationError', [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'example' => 'The given data was invalid.'],
                'errors' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
        ]);

        $c->addSchema('UnauthorizedError', [
            'type' => 'object',
            'properties' => ['message' => ['type' => 'string', 'example' => 'Unauthenticated.']],
        ]);

        $c->addSchema('PaginationMeta', [
            'type' => 'object',
            'properties' => [
                'current_page' => ['type' => 'integer'],
                'from' => ['type' => 'integer', 'nullable' => true],
                'last_page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'],
                'to' => ['type' => 'integer', 'nullable' => true],
                'total' => ['type' => 'integer'],
            ],
        ]);

        $c->addSchema('PaginationLinks', [
            'type' => 'object',
            'properties' => [
                'first' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'last' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'prev' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'next' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
            ],
        ]);

        // Standard responses
        $c->addResponse('ValidationError', [
            'description' => 'Validation Error',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError']]],
        ]);

        $c->addResponse('Unauthorized', [
            'description' => 'Unauthenticated',
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/UnauthorizedError']]],
        ]);

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

    private function applyDocumentTransformers(Document $doc): void
    {
        foreach ($this->documentTransformers as $t) {
            if ($t instanceof DocumentTransformerInterface) {
                $t->transform($doc);
            } else {
                $t($doc);
            }
        }

        foreach ($this->config->documentTransformers as $class) {
            (new $class)->transform($doc);
        }
    }

    /** @return list<Route> */
    private function filteredRoutes(): array
    {
        $routes = $this->routeCollection->all();

        // Filter by configured path prefixes
        $routes = array_filter($routes, function (Route $route) {
            foreach ($this->config->pathPrefixes as $prefix) {
                $prefix = trim($prefix, '/');
                $uri = ltrim($route->path, '/');
                if ($prefix === '' || str_starts_with($uri, $prefix.'/') || $uri === $prefix) {
                    return true;
                }
            }

            return false;
        });

        // Exclude patterns
        $routes = array_filter($routes, function (Route $route) {
            foreach ($this->config->excludePaths as $pattern) {
                if (fnmatch($pattern, $route->path) || @preg_match('#'.$pattern.'#', $route->path)) {
                    return false;
                }
            }

            return true;
        });

        // Custom filter
        if ($this->routeFilter !== null) {
            $filter = $this->routeFilter;
            $routes = array_filter($routes, fn (Route $r) => (bool) $filter($r));
        }

        return array_values($routes);
    }
}
