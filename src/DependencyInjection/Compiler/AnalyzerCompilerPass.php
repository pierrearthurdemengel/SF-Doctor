<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\DependencyInjection\Compiler;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class AnalyzerCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Recupere les modules actives depuis la config resolue du bundle.
        $enabledModules = [
            'security'     => $container->getParameter('sf_doctor.analyzers.security'),
            'architecture' => $container->getParameter('sf_doctor.analyzers.architecture'),
            'performance'  => $container->getParameter('sf_doctor.analyzers.performance'),
        ];

        $taggedServices = $container->findTaggedServiceIds('sf_doctor.analyzer');

        foreach ($taggedServices as $id => $tags) {
            $definition = $container->getDefinition($id);

            // Verifie que le service implemente bien AnalyzerInterface.
            // Une erreur de configuration est detectee a la compilation, pas en production.
            $class = $definition->getClass();
            if ($class === null || !is_a($class, AnalyzerInterface::class, true)) {
                throw new \InvalidArgumentException(
                    sprintf('Le service "%s" est tague "sf_doctor.analyzer" mais n\'implemente pas AnalyzerInterface.', $id)
                );
            }

            // Determine le module de cet analyzer depuis l'attribut du tag.
            // Si aucun attribut "module" n'est declare, l'analyzer est toujours inclus.
            $module = $tags[0]['module'] ?? null;

            if ($module !== null && isset($enabledModules[$module]) && $enabledModules[$module] === false) {
                // Supprime le service du container si son module est desactive.
                $container->removeDefinition($id);
            }
        }
    }
}