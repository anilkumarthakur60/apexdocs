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
                static fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true),
            );

            if ($methods === []) {
                continue;
            }

            $routes[] = new Route(
                methods: array_values($methods),
                path: '/'.ltrim($route->uri(), '/'),
                // Closure routes have no class to reflect; they are still real
                // endpoints, so they are documented from the route itself.
                handler: $this->resolveHandler($route) ?? '',
                middleware: $this->middleware($route),
                metadata: [
                    'name' => $route->getName() ?? '',
                    'wheres' => $route->wheres ?? [],
                    'domain' => $route->getDomain() ?? '',
                ],
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

        return null;
    }

    /** @return list<string> */
    private function middleware(\Illuminate\Routing\Route $route): array
    {
        try {
            $middleware = $route->gatherMiddleware();
        } catch (\Throwable) {
            // gatherMiddleware() resolves the controller, which can fail for a
            // route pointing at a class that no longer exists.
            return [];
        }

        $out = [];
        foreach ($middleware as $mw) {
            if (is_string($mw)) {
                $out[] = $mw;
            } elseif (is_object($mw)) {
                $out[] = $mw::class;
            }
        }

        return array_values(array_unique($out));
    }
}
