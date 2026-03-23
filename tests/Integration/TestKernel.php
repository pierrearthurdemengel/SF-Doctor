<?php

namespace PierreArthur\SfDoctor\Tests\Integration;

use PierreArthur\SfDoctor\SfDoctorBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Kernel minimal pour les tests d'integration.
 *
 * Charge uniquement le FrameworkBundle (obligatoire)
 * et le SfDoctorBundle (celui qu'on teste).
 * Aucune base de donnees, aucun template, aucun routage.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new SfDoctorBundle(),
        ];
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        // Configuration minimale du FrameworkBundle.
        // "secret" est le seul parametre obligatoire.
        // "test" active le mode test (pas de cache HTTP, etc.).
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
        ]);
    }

    // Le cache et les logs du kernel de test sont stockes
    // dans un dossier temporaire du systeme.
    // Ca evite de polluer le projet avec des fichiers generes.

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/sf_doctor_test/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/sf_doctor_test/log';
    }
}