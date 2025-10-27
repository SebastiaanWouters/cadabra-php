<?php

declare(strict_types=1);

namespace Cadabra\SymfonyBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration definition for the Cadabra bundle.
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('cadabra');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('service_url')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('URL of the Cadabra service')
                ->end()
                ->scalarNode('prefix')
                    ->defaultValue('cadabra')
                    ->info('Cache key prefix/namespace')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
