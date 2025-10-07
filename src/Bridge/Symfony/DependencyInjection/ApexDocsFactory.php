<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Symfony\DependencyInjection;

use ApexDocs\ApexDocs;
use ApexDocs\Config;
use ApexDocs\Contract\RouteCollectionInterface;
use ApexDocs\Contract\SecurityDetectorInterface;
use ApexDocs\Contract\ValidationExtractorInterface;

/**
 * Builds a fully-wired {@see ApexDocs} for the Symfony container.
 *
 * Required because {@see ApexDocs} setters return clones; a plain
 * configurator wouldn't capture the chained instance.
 */
final readonly class ApexDocsFactory
{
    public function __construct(
        private Config $config,
        private RouteCollectionInterface $routes,
        private ValidationExtractorInterface $validation,
        private SecurityDetectorInterface $security,
    ) {}

    public function build(): ApexDocs
    {
        return ApexDocs::make($this->config)
            ->routes($this->routes)
            ->validation($this->validation)
            ->security($this->security);
    }
}
