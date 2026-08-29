<?php

declare(strict_types=1);

namespace ApexDocs\Mcp;

use ApexDocs\ApexDocs;
use ApexDocs\Config;
use ApexDocs\Generator\RouteSelector;
use ApexDocs\Route\Route;

/**
 * Everything the MCP server needs to answer questions, captured at one point
 * in time: the generated spec, every route the collection knows about (with
 * the reason a route is *not* documented), and the effective configuration.
 *
 * Built in-process by {@see fromApexDocs()} and serialisable to JSON so a
 * fresh PHP process can produce it  a long-lived server cannot reload
 * changed classes, so the Laravel bridge regenerates it in a subprocess.
 */
final class Snapshot
{
    public const REASON_HIDDEN = 'hidden (#[Hidden] or no documentable HTTP method)';

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<array<string, mixed>>  $routes
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public readonly array $spec,
        public readonly array $routes,
        public readonly array $config,
        public readonly string $generatedAt,
        public readonly int $durationMs,
    ) {}

    public static function fromApexDocs(ApexDocs $apexDocs): self
    {
        $started = microtime(true);
        $spec = $apexDocs->generate()->toArray();
        $ms = (int) round((microtime(true) - $started) * 1000);

        $config = $apexDocs->getConfig();
        $selector = new RouteSelector($config, $apexDocs->getRouteFilter());
        $routes = [];

        foreach ($apexDocs->getRouteCollection()?->all() ?? [] as $route) {
            $routes[] = self::describeRoute($route, $selector, $spec);
        }

        return new self(
            spec: $spec,
            routes: $routes,
            config: self::configToArray($config),
            generatedAt: date(DATE_ATOM),
            durationMs: $ms,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            spec: is_array($data['spec'] ?? null) ? $data['spec'] : [],
            routes: is_array($data['routes'] ?? null) ? array_values($data['routes']) : [],
            config: is_array($data['config'] ?? null) ? $data['config'] : [],
            generatedAt: is_string($data['generated_at'] ?? null) ? $data['generated_at'] : '',
            durationMs: is_int($data['duration_ms'] ?? null) ? $data['duration_ms'] : 0,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt,
            'duration_ms' => $this->durationMs,
            'config' => $this->config,
            'routes' => $this->routes,
            'spec' => $this->spec,
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private static function describeRoute(Route $route, RouteSelector $selector, array $spec): array
    {
        $reason = $selector->exclusionReason($route);
        $path = $route->normalizedPath();
        $operations = [];

        if ($reason === null) {
            foreach ($route->documentedMethods() ?: ['GET'] as $verb) {
                $op = $spec['paths'][$path][strtolower($verb)] ?? null;
                if (is_array($op)) {
                    $operations[strtolower($verb)] = (string) ($op['operationId'] ?? '');
                }
            }
            if ($operations === []) {
                $reason = self::REASON_HIDDEN;
            }
        }

        return [
            'methods' => $route->methods,
            'path' => $route->path,
            'handler' => $route->handler,
            'name' => $route->name(),
            'middleware' => $route->middleware,
            'included' => $reason === null,
            'reason' => $reason,
            'operations' => $operations,
        ];
    }

    /** @return array<string, mixed> */
    private static function configToArray(Config $config): array
    {
        $out = [];
        foreach ((new \ReflectionClass($config))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $out[$prop->getName()] = $prop->getValue($config);
        }

        return $out;
    }
}
