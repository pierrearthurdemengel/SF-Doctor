<?php

namespace SfDoctor\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Charge la configuration des services du bundle SF-Doctor.
 *
 * Symfony detecte cette classe automatiquement grace a la convention
 * de nommage : SfDoctorBundle → SfDoctorExtension.
 */
final class SfDoctorExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        // FileLocator indique dans quel dossier chercher les fichiers de config.
        // __DIR__ = src/DependencyInjection/
        // /../config = src/../config = config/ (a la racine du bundle)
        //
        // ATTENTION : ce n'est PAS le dossier config/ du projet utilisateur.
        // C'est le dossier config/ du bundle SF-Doctor lui-meme.
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../config'),
        );

        // Charge le fichier services.yaml du bundle.
        // Ce fichier declare tous les services : analyzers, reporters, commande.
        $loader->load('services.yaml');
    }
}