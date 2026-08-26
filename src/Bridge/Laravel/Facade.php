<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Laravel;

use ApexDocs\ApexDocs;
use Illuminate\Support\Facades\Facade as LaravelFacade;

/**
 * @method static \ApexDocs\Spec\Document generate()
 * @method static \ApexDocs\ApexDocs routes(\ApexDocs\Contract\RouteCollectionInterface $collection)
 * @method static \ApexDocs\ApexDocs validation(?\ApexDocs\Contract\ValidationExtractorInterface $extractor)
 * @method static \ApexDocs\ApexDocs security(?\ApexDocs\Contract\SecurityDetectorInterface $detector)
 * @method static \ApexDocs\ApexDocs transformDocument(\ApexDocs\Contract\DocumentTransformerInterface|\Closure $transformer)
 * @method static \ApexDocs\ApexDocs transformOperation(\ApexDocs\Contract\OperationTransformerInterface|\Closure $transformer)
 * @method static \ApexDocs\ApexDocs webhook(string $name, array $spec)
 * @method static \ApexDocs\ApexDocs filterRoutes(\Closure $filter)
 * @method static \ApexDocs\ApexDocs withConfig(\ApexDocs\Config|array $config)
 * @method static \ApexDocs\Config getConfig()
 *
 * Note: ApexDocs is immutable — every fluent method returns a NEW instance.
 * `ApexDocs::filterRoutes(...)` on its own therefore changes nothing; chain the
 * call through to ->generate(), or rebind the container singleton:
 *
 *   app()->extend(ApexDocs::class, fn ($docs) => $docs->filterRoutes($filter));
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
