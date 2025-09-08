<?php

declare(strict_types=1);

namespace ApexDocs\Contract;

use ApexDocs\Route\Route;

/**
 * Framework bridge: extract request body schema from framework-specific validation.
 *
 * Laravel: reads FormRequest::rules()
 * Symfony: reads form types or assert annotations
 */
interface ValidationExtractorInterface
{
    /**
     * Return an OpenAPI requestBody object, or null if nothing found.
     *
     * @param  \ReflectionMethod  $handler  The controller action method.
     * @return array<string, mixed>|null
     */
    public function extract(\ReflectionMethod $handler, Route $route): ?array;
}
