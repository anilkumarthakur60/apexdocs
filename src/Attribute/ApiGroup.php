<?php

declare(strict_types=1);

namespace ApexDocs\Attribute;

use Attribute;

/**
 * Tag a controller or method as belonging to a named spec group.
 * Used for multi-spec generation: set Config::$specGroup to only include
 * routes carrying the matching #[ApiGroup] attribute.
 *
 * #[ApiGroup('public')]      // included when specGroup = 'public'
 * #[ApiGroup('internal')]    // included when specGroup = 'internal'
 *
 * Routes with no #[ApiGroup] are always included unless specGroup is set.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ApiGroup
{
    public function __construct(
        public readonly string $name,
    ) {}
}
