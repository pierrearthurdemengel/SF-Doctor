<?php

namespace PierreArthur\SfDoctor;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\DependencyInjection\Compiler\AnalyzerCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Point d'entree du bundle SF-Doctor.
 *
 * Enregistre les regles d'autoconfiguration et les compiler passes.
 */
final class SfDoctorBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Autoconfigure : toute classe implementant AnalyzerInterface
        // recoit automatiquement le tag "sf_doctor.analyzer".
        $container
            ->registerForAutoconfiguration(AnalyzerInterface::class)
            ->addTag('sf_doctor.analyzer');

        // CompilerPass : verifie a la compilation que tous les services
        // tagges "sf_doctor.analyzer" implementent bien l'interface.
        $container->addCompilerPass(new AnalyzerCompilerPass());
    }
}