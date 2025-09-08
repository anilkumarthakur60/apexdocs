<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Symfony;

use ApexDocs\Contract\RouteCollectionInterface;
use ApexDocs\Route\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Symfony bridge: converts Symfony routes to ApexDocs Route value objects.
 * Requires symfony/framework-bundle.
 */
final class RouteCollection implements RouteCollectionInterface
{
    public function __construct(private RouterInterface $router) {}

    /** @return list<Route> */
    public function all(): array
    {
        $routes = [];

        foreach ($this->router->getRouteCollection() as $name => $symfonyRoute) {
            $methods = $symfonyRoute->getMethods() ?: ['GET'];
            $methods = array_filter($methods, fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));
            if (empty($methods)) {
                continue;
            }

            $defaults = $symfonyRoute->getDefaults();
            $controller = $defaults['_controller'] ?? null;
            if (! is_string($controller)) {
                continue;
            }

            // Symfony uses "App\Controller\UserController::index" → convert to "App\Controller\UserController@index"
            $handler = str_replace('::', '@', $controller);

            $routes[] = new Route(
                methods: array_map('strtoupper', array_values($methods)),
                path: $symfonyRoute->getPath(),
                handler: $handler,
                metadata: ['name' => $name],
            );
        }

        return $routes;
    }
}
