<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel;

use ApexDocs\Contract\RouteCollectionInterface;
use ApexDocs\Route\Route;
use Illuminate\Routing\Router;

/**
 * Laravel bridge: converts Illuminate routes to ApexDocs Route value objects.
 */
final class RouteCollection implements RouteCollectionInterface
{
    public function __construct(private Router $router) {}

    /** @return list<Route> */
    public function all(): array
    {
        $routes = [];

        foreach ($this->router->getRoutes() as $route) {
            /** @var \Illuminate\Routing\Route $route */
            $methods = array_filter(
                $route->methods(),
                fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true),
            );

            if (empty($methods)) {
                continue;
            }

            $handler = $this->resolveHandler($route);
            if ($handler === null) {
                continue;
            }

            $routes[] = new Route(
                methods: array_map('strtoupper', array_values($methods)),
                path: '/'.ltrim($route->uri(), '/'),
                handler: $handler,
                middleware: $route->gatherMiddleware(),
                metadata: ['name' => $route->getName() ?? ''],
            );
        }

        return $routes;
    }

    private function resolveHandler(\Illuminate\Routing\Route $route): ?string
    {
        $action = $route->getAction();

        if (isset($action['uses']) && is_string($action['uses'])) {
            // "App\Http\Controllers\UserController@index"
            return $action['uses'];
        }

        if (isset($action['controller']) && is_string($action['controller'])) {
            return $action['controller'];
        }

        // Closure-based routes — skip
        return null;
    }
}
