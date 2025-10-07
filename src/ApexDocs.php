<?php

declare(strict_types=1);

namespace ApexDocs;

use ApexDocs\Contract\DocumentTransformerInterface;
use ApexDocs\Contract\OperationTransformerInterface;
use ApexDocs\Contract\RouteCollectionInterface;
use ApexDocs\Contract\SecurityDetectorInterface;
use ApexDocs\Contract\ValidationExtractorInterface;
use ApexDocs\Exception\MissingRouteCollectionException;
use ApexDocs\Generator\SpecBuilder;
use ApexDocs\Spec\Document;
use Closure;

/**
 * Main entry point. Framework-agnostic — works with any PHP project.
 *
 * Usage (standalone):
 *   $docs = ApexDocs::build($config, $routeCollection);
 *   echo $docs->toJson();
 *
 * Usage (fluent builder):
 *   $apexdocs = new ApexDocs(new Config(['title' => 'My API']));
 *   $apexdocs->routes($collection)->generate();
 */
final class ApexDocs
{
    private ?RouteCollectionInterface $routeCollection = null;

    private ?ValidationExtractorInterface $validationExtractor = null;

    private ?SecurityDetectorInterface $securityDetector = null;

    /** @var list<DocumentTransformerInterface|Closure> */
    private array $documentTransformers = [];

    /** @var list<OperationTransformerInterface|Closure> */
    private array $operationTransformers = [];

    /** @var array<string, array<string, mixed>> Manually registered webhooks */
    private array $webhooks = [];

    /** @var Closure|null Custom route filter */
    private ?Closure $routeFilter = null;

    public function __construct(private Config $config = new Config) {}

    // ── Static factory ────────────────────────────────────────────────────────

    public static function make(Config|array $config = []): self
    {
        $cfg = $config instanceof Config ? $config : Config::fromArray($config);

        return new self($cfg);
    }

    /**
     * One-shot: build an OpenAPI document directly.
     */
    public static function build(
        Config|array $config,
        RouteCollectionInterface $routes,
        ?ValidationExtractorInterface $validation = null,
        ?SecurityDetectorInterface $security = null,
    ): Document {
        return self::make($config)
            ->routes($routes)
            ->validation($validation)
            ->security($security)
            ->generate();
    }

    // ── Fluent builder ────────────────────────────────────────────────────────

    public function routes(RouteCollectionInterface $collection): self
    {
        $clone = clone $this;
        $clone->routeCollection = $collection;

        return $clone;
    }

    public function validation(?ValidationExtractorInterface $extractor): self
    {
        $clone = clone $this;
        $clone->validationExtractor = $extractor;

        return $clone;
    }

    public function security(?SecurityDetectorInterface $detector): self
    {
        $clone = clone $this;
        $clone->securityDetector = $detector;

        return $clone;
    }

    public function transformDocument(DocumentTransformerInterface|Closure $transformer): self
    {
        $clone = clone $this;
        $clone->documentTransformers[] = $transformer;

        return $clone;
    }

    public function transformOperation(OperationTransformerInterface|Closure $transformer): self
    {
        $clone = clone $this;
        $clone->operationTransformers[] = $transformer;

        return $clone;
    }

    /**
     * Manually register a webhook event in the spec.
     *
     * @param  array<string, mixed>  $spec  OpenAPI webhook spec (path-item object)
     */
    public function webhook(string $name, array $spec): self
    {
        $clone = clone $this;
        $clone->webhooks[$name] = $spec;

        return $clone;
    }

    /**
     * Filter routes via a closure. Return false to exclude a route.
     * The closure receives an ApexDocs\Route\Route value object.
     */
    public function filterRoutes(Closure $filter): self
    {
        $clone = clone $this;
        $clone->routeFilter = $filter;

        return $clone;
    }

    public function withConfig(Config|array $config): self
    {
        $clone = clone $this;
        $clone->config = $config instanceof Config
            ? $config
            : $this->config->with($config);

        return $clone;
    }

    // ── Generation ────────────────────────────────────────────────────────────

    public function generate(): Document
    {
        if ($this->routeCollection === null) {
            throw MissingRouteCollectionException::create();
        }

        $builder = new SpecBuilder(
            config: $this->config,
            routeCollection: $this->routeCollection,
            validationExtractor: $this->validationExtractor,
            securityDetector: $this->securityDetector,
            documentTransformers: $this->documentTransformers,
            operationTransformers: $this->operationTransformers,
            webhooks: $this->webhooks,
            routeFilter: $this->routeFilter,
        );

        return $builder->build();
    }

    public function getConfig(): Config
    {
        return $this->config;
    }
}
