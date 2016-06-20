<?php

namespace Electrotiti\SwaggerLoaderBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;


class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('swagger_loader');

        $rootNode
            ->children()
                ->arrayNode('apis')
                    ->requiresAtLeastOneElement()
                    ->prototype('array')
                        ->children()
                            ->scalarNode('name')
                                ->isRequired()
                            ->end()
                            ->scalarNode('path')
                                ->isRequired()
                            ->end()
//                            ->arrayNode('scopes')
//                                ->requiresAtLeastOneElement()
//                                ->prototype('scalar')->end()
//                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
