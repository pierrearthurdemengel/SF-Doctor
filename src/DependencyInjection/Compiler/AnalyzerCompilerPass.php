<?php

namespace SfDoctor\DependencyInjection\Compiler;

use SfDoctor\Analyzer\AnalyzerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Verifie que tous les services tagges "sf_doctor.analyzer"
 * implementent bien AnalyzerInterface.
 *
 * Ce controle a lieu a la compilation du container, pas a l'execution.
 * Une erreur de configuration est detectee au deploy, pas en production.
 */
final class AnalyzerCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // findTaggedServiceIds() retourne un tableau associatif :
        //   cle = identifiant du service (ex: SfDoctor\Analyzer\Security\FirewallAnalyzer)
        //   valeur = tableau des attributs du tag (vide dans notre cas)
        $taggedServices = $container->findTaggedServiceIds('sf_doctor.analyzer');

        foreach ($taggedServices as $serviceId => $tags) {
            $definition = $container->getDefinition($serviceId);
            $class = $definition->getClass();

            // Si la classe n'est pas definie dans la definition,
            // Symfony utilise l'identifiant du service comme nom de classe
            // (convention FQCN).
            if ($class === null) {
                $class = $serviceId;
            }

            // Verifie que la classe existe et implemente AnalyzerInterface.
            // class_exists() + is_subclass_of() fonctionnent sur le nom
            // de classe (string), pas sur une instance.
            if (!is_subclass_of($class, AnalyzerInterface::class)) {
                throw new \InvalidArgumentException(sprintf(
                    'Le service "%s" est tagge "sf_doctor.analyzer" '
                    . 'mais sa classe "%s" n\'implemente pas %s.',
                    $serviceId,
                    $class,
                    AnalyzerInterface::class,
                ));
            }
        }
    }
}