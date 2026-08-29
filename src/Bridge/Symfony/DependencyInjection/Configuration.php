<?php

declare(strict_types=1);

namespace ApexDocs\Bridge\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Symfony bundle configuration schema.
 *
 * Matches the structural shape of {@see \ApexDocs\Config::fromArray()} so the
 * underlying core stays framework-agnostic  this class is the only thing that
 * understands Symfony's config tree.
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('apex_docs');
        $root = $tree->getRootNode();

        $root
            ->children()
                ->arrayNode('info')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('title')->defaultValue('API')->end()
                        ->scalarNode('version')->defaultValue('1.0.0')->end()
                        ->scalarNode('description')->defaultValue('')->end()
                        ->scalarNode('terms_of_service')->defaultValue('')->end()
                        ->arrayNode('contact')
                            ->children()
                                ->scalarNode('name')->defaultValue('')->end()
                                ->scalarNode('email')->defaultValue('')->end()
                                ->scalarNode('url')->defaultValue('')->end()
                            ->end()
                        ->end()
                        ->arrayNode('license')
                            ->children()
                                ->scalarNode('name')->defaultValue('')->end()
                                ->scalarNode('url')->defaultValue('')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('api_path_prefix')->defaultValue('api')->end()
                ->arrayNode('exclude_paths')->scalarPrototype()->end()->end()
                ->arrayNode('servers')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('url')->isRequired()->end()
                            ->scalarNode('description')->defaultValue('')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('documentation/api')->end()
                        ->booleanNode('show_toolbar')->defaultTrue()->end()
                        ->scalarNode('theme')->defaultValue('dark')->end()
                        ->scalarNode('custom_logo')->defaultValue('')->end()
                        ->scalarNode('custom_css')->defaultValue('')->end()
                        ->scalarNode('announcement_banner')->defaultValue('')->end()
                        ->scalarNode('announcement_banner_type')->defaultValue('info')->end()
                        ->booleanNode('try_it_out')->defaultTrue()->end()
                        ->scalarNode('default_language')->defaultValue('curl')->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('auto_detect')->defaultTrue()->end()
                        ->arrayNode('schemes')
                            ->variablePrototype()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('responses')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('infer_error_responses')->defaultTrue()->end()
                        ->booleanNode('include_validation_errors')->defaultTrue()->end()
                        ->booleanNode('include_pagination_meta')->defaultTrue()->end()
                        ->integerNode('max_depth')->defaultValue(6)->end()
                    ->end()
                ->end()
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->integerNode('ttl')->defaultValue(3600)->end()
                    ->end()
                ->end()
                ->arrayNode('webhooks')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('scan_paths')->scalarPrototype()->end()->end()
                    ->end()
                ->end()
                ->arrayNode('export')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('default_path')->defaultValue('')->end()
                    ->end()
                ->end()
                ->arrayNode('rate_limits')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                    ->end()
                ->end()
                ->scalarNode('spec_group')->defaultValue('')->end()
                ->arrayNode('document_transformers')->scalarPrototype()->end()->end()
                ->arrayNode('operation_transformers')->scalarPrototype()->end()->end()
            ->end();

        return $tree;
    }
}
