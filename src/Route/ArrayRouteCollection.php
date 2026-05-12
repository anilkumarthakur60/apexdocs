<?php

declare(strict_types=1);

namespace ApexDocs\Route;

use ApexDocs\Contract\RouteCollectionInterface;

/**
 * Simple hand-built route collection for use without a framework.
 *
 *   $routes = new ArrayRouteCollection();
 *   $routes->add('GET', '/api/users', UserController::class . '@index');
 *   $routes->add('POST', '/api/users', UserController::class . '@store');
 */
final class ArrayRouteCollection implements RouteCollectionInterface
{
    /** @var list<Route> */
    private array $routes = [];

    public function add(
        string|array $methods,
        string $path,
        string $handler,
        array $middleware = [],
        array $metadata = [],
    ): self {
        $this->routes[] = new Route(
            methods: array_map('strtoupper', (array) $methods),
            path: $path,
            handler: $handler,
            middleware: $middleware,
            metadata: $metadata,
        );

        return $this;
    }

    public function all(): array
    {
        return $this->routes;
    }
}
