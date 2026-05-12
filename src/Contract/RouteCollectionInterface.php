<?php

declare(strict_types=1);

namespace ApexDocs\Contract;

use ApexDocs\Route\Route;

/**
 * Framework bridge: implement this to feed routes into ApexDocs.
 *
 * Laravel:  ApexDocs\Bridge\Laravel\RouteCollection
 * Symfony:  ApexDocs\Bridge\Symfony\RouteCollection
 * Manual:   ApexDocs\Route\ArrayRouteCollection
 */
interface RouteCollectionInterface
{
    /** @return list<Route> */
    public function all(): array;
}
