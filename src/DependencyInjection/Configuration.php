<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

// Definit la structure de configuration acceptee par le bundle.
// Symfony valide automatiquement la config utilisateur contre cet arbre au boot.
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sf_doctor');

        $treeBuilder->getRootNode()
            ->children()
                // Score minimum en dessous duquel la commande retourne un code d'erreur non-zero.
                // Utile pour faire echouer un pipeline CI/CD.
                ->integerNode('score_threshold')
                    ->defaultValue(0)
                    ->min(0)
                    ->max(100)
                ->end()
                ->arrayNode('analyzers')
                    ->addDefaultsIfNotSet()
                    ->children()
                        // Chaque module peut etre active ou desactive independamment.
                        ->booleanNode('security')->defaultTrue()->end()
                        ->booleanNode('architecture')->defaultTrue()->end()
                        ->booleanNode('performance')->defaultTrue()->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}