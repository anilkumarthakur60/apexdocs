<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel;

use ApexDocs\ApexDocs;
use Illuminate\Support\Facades\Facade as LaravelFacade;

/**
 * @method static \ApexDocs\Spec\Document generate()
 * @method static \ApexDocs\ApexDocs routes(\ApexDocs\Contract\RouteCollectionInterface $collection)
 * @method static \ApexDocs\ApexDocs transformDocument(\Closure $transformer)
 * @method static \ApexDocs\ApexDocs transformOperation(\Closure $transformer)
 * @method static \ApexDocs\ApexDocs webhook(string $name, array $spec)
 * @method static \ApexDocs\ApexDocs filterRoutes(\Closure $filter)
 * @method static \ApexDocs\Config getConfig()
 *
 * @see ApexDocs
 */
class Facade extends LaravelFacade
{
    protected static function getFacadeAccessor(): string
    {
        return 'apexdocs';
    }
}
