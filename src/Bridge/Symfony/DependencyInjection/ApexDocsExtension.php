<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Symfony\DependencyInjection;

use ApexDocs\ApexDocs;
use ApexDocs\Bridge\Symfony\RouteCollection;
use ApexDocs\Bridge\Symfony\SecurityDetector;
use ApexDocs\Bridge\Symfony\ValidationExtractor;
use ApexDocs\Config;
use ApexDocs\Contract\RouteCollectionInterface;
use ApexDocs\Contract\SecurityDetectorInterface;
use ApexDocs\Contract\ValidationExtractorInterface;
use ApexDocs\Export\BrunoExporter;
use ApexDocs\Export\InsomniaExporter;
use ApexDocs\Export\JsonExporter;
use ApexDocs\Export\PostmanExporter;
use ApexDocs\Export\YamlExporter;
use ApexDocs\Extractor\ComponentRegistry;
use ApexDocs\Extractor\SchemaBuilder;
use ApexDocs\Http\UiRenderer;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Routing\RouterInterface;

/**
 * Wires every ApexDocs service into the Symfony container. Reading the bundle
 * config tree once, building a singleton {@see Config}, then registering the
 * bridge implementations of the framework-agnostic contracts.
 */
final class ApexDocsExtension extends Extension
{
    /**
     * @param  array<int, array<string, mixed>>  $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->register(Config::class)
            ->setFactory([Config::class, 'fromArray'])
            ->setArguments([$config])
            ->setPublic(true);

        $container->register(ComponentRegistry::class, ComponentRegistry::class)
            ->setPublic(false);

        $container->register(SchemaBuilder::class, SchemaBuilder::class)
            ->setArguments([$config['responses']['max_depth'] ?? 6, new Reference(ComponentRegistry::class)])
            ->setPublic(false);

        $container->register(RouteCollectionInterface::class, RouteCollection::class)
            ->setArguments([new Reference(RouterInterface::class)])
            ->setPublic(true);

        $container->register(ValidationExtractorInterface::class, ValidationExtractor::class)
            ->setArguments([new Reference(SchemaBuilder::class)])
            ->setPublic(true);

        $container->register(SecurityDetectorInterface::class, SecurityDetector::class)
            ->setPublic(true);

        $container->register(UiRenderer::class, UiRenderer::class)
            ->setPublic(true);

        foreach ([JsonExporter::class, YamlExporter::class, PostmanExporter::class, InsomniaExporter::class, BrunoExporter::class] as $exporter) {
            $container->register($exporter, $exporter)->setPublic(true);
        }

        // The fluent ApexDocs façade — built via a factory so the cloning
        // fluent setters yield the final configured instance.
        $container->register('apex_docs.factory', ApexDocsFactory::class)
            ->setArguments([
                new Reference(Config::class),
                new Reference(RouteCollectionInterface::class),
                new Reference(ValidationExtractorInterface::class),
                new Reference(SecurityDetectorInterface::class),
            ])
            ->setPublic(false);

        $container->register(ApexDocs::class)
            ->setFactory([new Reference('apex_docs.factory'), 'build'])
            ->setPublic(true);
    }

    public function getAlias(): string
    {
        return 'apex_docs';
    }
}
