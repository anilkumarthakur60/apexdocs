<?php

declare(strict_types=1);

namespace ApexDocs\Contract;

use ApexDocs\Route\Route;

/**
 * Framework bridge: detect security schemes and per-route security requirements.
 */
interface SecurityDetectorInterface
{
    /**
     * Return global security scheme definitions to add to components.
     *
     * @return array<string, array<string, mixed>>
     */
    public function schemes(): array;

    /**
     * Return the security requirement for one route, or null to inherit global default.
     * Return [] to mark route as unsecured (no auth).
     *
     * @return list<array<string, list<string>>>|null
     */
    public function forRoute(Route $route, \ReflectionMethod $handler): ?array;
}
