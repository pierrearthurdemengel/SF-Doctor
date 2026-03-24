<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class SfDoctorExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        // Fusionne et valide toutes les sources de config contre l'arbre defini
        // dans Configuration.php. Retourne un tableau normalise avec les valeurs
        // par defaut appliquees.
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Expose la config resolue comme parametres du container pour que
        // les services puissent y acceder via injection ou %sf_doctor.*%.
        $container->setParameter('sf_doctor.score_threshold', $config['score_threshold']);
        $container->setParameter('sf_doctor.analyzers.security', $config['analyzers']['security']);
        $container->setParameter('sf_doctor.analyzers.architecture', $config['analyzers']['architecture']);
        $container->setParameter('sf_doctor.analyzers.performance', $config['analyzers']['performance']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');
    }
}