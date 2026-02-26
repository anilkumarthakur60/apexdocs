<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Symfony;

use ApexDocs\Contract\RouteCollectionInterface;
use ApexDocs\Route\Route;
use Symfony\Component\Routing\Route as SymfonyRoute;
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
            $methods = array_filter($methods, static fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));
            if ($methods === []) {
                continue;
            }

            $controller = $symfonyRoute->getDefaults()['_controller'] ?? null;
            if (! is_string($controller) || $controller === '') {
                continue;
            }

            // Symfony uses "App\Controller\UserController::index" → "…@index"
            $handler = str_replace('::', '@', $controller);

            [$path, $inlineRequirements] = $this->normalisePath($symfonyRoute->getPath());

            $routes[] = new Route(
                methods: array_values($methods),
                path: '/'.ltrim($path, '/'),
                handler: $handler,
                middleware: [],
                metadata: [
                    'name' => (string) $name,
                    // Inline requirements win: they are the ones written on the
                    // path itself and cannot be overridden elsewhere.
                    'wheres' => array_merge($this->requirements($symfonyRoute), $inlineRequirements),
                    'host' => $symfonyRoute->getHost(),
                ],
            );
        }

        return $routes;
    }

    /**
     * Reduce Symfony's route syntax to a plain OpenAPI path template.
     *
     *   /users/{id<\d+>}      → /users/{id}          requirement id = \d+
     *   /posts/{page?1}       → /posts/{page}
     *   /posts/{!slug}        → /posts/{slug}
     *   /feed{._format}       → /feed
     *
     * Left alone, "{id<\d+>}" would reach the spec verbatim: an invalid path
     * template whose parameter name no one can match.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function normalisePath(string $path): array
    {
        $requirements = [];

        // Optional format suffix — not part of the documented resource path.
        $path = (string) preg_replace('/\{\.[A-Za-z_][A-Za-z0-9_]*\}/', '', $path);

        $path = (string) preg_replace_callback(
            '/\{(!?)([A-Za-z_][A-Za-z0-9_]*)(?:<(.+?)>)?(?:\?([^}]*))?\}/',
            static function (array $m) use (&$requirements): string {
                if (($m[3] ?? '') !== '') {
                    $requirements[$m[2]] = $m[3];
                }

                return '{'.$m[2].'}';
            },
            $path,
        );

        return [$path, $requirements];
    }

    /** @return array<string, string> */
    private function requirements(SymfonyRoute $route): array
    {
        $out = [];
        foreach ($route->getRequirements() as $param => $pattern) {
            if (is_string($param) && is_string($pattern) && ! str_starts_with($param, '_')) {
                $out[$param] = $pattern;
            }
        }

        return $out;
    }
}
