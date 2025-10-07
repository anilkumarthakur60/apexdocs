<?php

declare(strict_types=1);

namespace ApexDocs\Exception;

/**
 * Thrown when {@see \ApexDocs\ApexDocs::generate()} is called before a
 * {@see \ApexDocs\Contract\RouteCollectionInterface} has been provided via
 * {@see \ApexDocs\ApexDocs::routes()}.
 */
final class MissingRouteCollectionException extends ApexDocsRuntimeException
{
    public static function create(): self
    {
        return new self('No route collection provided. Call ->routes($collection) before ->generate().');
    }
}
