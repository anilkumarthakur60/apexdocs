<?php

declare(strict_types=1);

namespace ApexDocs\Route;

use ApexDocs\Contract\RouteCollectionInterface;

/**
 * Simple hand-built route collection for use without a framework.
 *
 * Fluent:
 *   $routes = (new ArrayRouteCollection)
 *       ->add('GET',  '/api/users', UserController::class.'@index')
 *       ->add('POST', '/api/users', UserController::class.'@store');
 *
 * Or pass Route objects straight to the constructor:
 *   $routes = new ArrayRouteCollection([
 *       new Route(['GET'],  '/api/users',      [UserController::class, 'index']),
 *       new Route(['POST'], '/api/users',      [UserController::class, 'store']),
 *   ]);
 */
final class ArrayRouteCollection implements RouteCollectionInterface
{
    /** @var list<Route> */
    private array $routes = [];

    /** @param iterable<Route> $routes */
    public function __construct(iterable $routes = [])
    {
        foreach ($routes as $route) {
            $this->push($route);
        }
    }

    /**
     * @param  string|list<string>  $methods
     * @param  string|array{0: string, 1?: string}  $handler
     * @param  list<string>  $middleware
     * @param  array<string, mixed>  $metadata
     */
    public function add(
        string|array $methods,
        string $path,
        string|array $handler,
        array $middleware = [],
        array $metadata = [],
    ): self {
        return $this->push(new Route(
            methods: (array) $methods,
            path: $path,
            handler: $handler,
            middleware: $middleware,
            metadata: $metadata,
        ));
    }

    public function push(Route $route): self
    {
        $this->routes[] = $route;

        return $this;
    }

    /** @return list<Route> */
    public function all(): array
    {
        return $this->routes;
    }
}
