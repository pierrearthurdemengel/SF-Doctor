<?php

// src/Context/ProjectContextDetector.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Context;

/**
 * Construit un ProjectContext en lisant composer.json et composer.lock
 * du projet audite.
 */
class ProjectContextDetector
{
    // Mapping package Composer -> propriete ProjectContext.
    private const PACKAGE_MAP = [
        'doctrine/orm'                          => 'hasDoctrineOrm',
        'doctrine/doctrine-bundle'              => 'hasDoctrineOrm',
        'symfony/messenger'                     => 'hasMessenger',
        'api-platform/core'                     => 'hasApiPlatform',
        'api-platform/api-pack'                 => 'hasApiPlatform',
        'twig/twig'                             => 'hasTwig',
        'symfony/twig-bundle'                   => 'hasTwig',
        'symfony/security-bundle'               => 'hasSecurityBundle',
        'symfony/web-profiler-bundle'           => 'hasWebProfilerBundle',
        'symfony/mailer'                        => 'hasMailer',
        'nelmio/cors-bundle'                    => 'hasNelmioCors',
        'nelmio/security-bundle'                => 'hasNelmioSecurity',
        'lexik/jwt-authentication-bundle'       => 'hasJwtAuth',
    ];

    public function __construct(private readonly string $projectPath)
    {
    }

    /**
     * Construit le ProjectContext a partir des fichiers Composer du projet audite.
     * Retourne un contexte vide si composer.json est absent ou illisible.
     */
    public function detect(): ProjectContext
    {
        $packages = $this->readInstalledPackages();
        $flags = $this->resolveFlags($packages);
        $symfonyVersion = $this->detectSymfonyVersion();

        return new ProjectContext(
            projectPath: $this->projectPath,
            hasDoctrineOrm: $flags['hasDoctrineOrm'],
            hasMessenger: $flags['hasMessenger'],
            hasApiPlatform: $flags['hasApiPlatform'],
            hasTwig: $flags['hasTwig'],
            hasSecurityBundle: $flags['hasSecurityBundle'],
            hasWebProfilerBundle: $flags['hasWebProfilerBundle'],
            hasMailer: $flags['hasMailer'],
            hasNelmioCors: $flags['hasNelmioCors'],
            hasNelmioSecurity: $flags['hasNelmioSecurity'],
            hasJwtAuth: $flags['hasJwtAuth'],
            symfonyVersion: $symfonyVersion,
        );
    }

    /**
     * Lit la liste des packages declares dans composer.json (require + require-dev).
     * Retourne un tableau de noms de packages en minuscules.
     */
    private function readInstalledPackages(): array
    {
        $composerJsonPath = $this->projectPath . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            return [];
        }

        $content = file_get_contents($composerJsonPath);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        $require = array_keys($data['require'] ?? []);
        $requireDev = array_keys($data['require-dev'] ?? []);

        return array_map('strtolower', array_merge($require, $requireDev));
    }

    /**
     * Transforme la liste de packages en tableau de flags booleens.
     */
    private function resolveFlags(array $packages): array
    {
        $flags = array_fill_keys(array_values(self::PACKAGE_MAP), false);

        foreach ($packages as $package) {
            if (isset(self::PACKAGE_MAP[$package])) {
                $flags[self::PACKAGE_MAP[$package]] = true;
            }
        }

        return $flags;
    }

    /**
     * Detecte la version Symfony installee via composer.lock.
     * Retourne null si composer.lock est absent ou si Symfony n'est pas installe.
     */
    private function detectSymfonyVersion(): ?string
    {
        $lockPath = $this->projectPath . '/composer.lock';

        if (!file_exists($lockPath)) {
            return null;
        }

        $content = file_get_contents($lockPath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        $allPackages = array_merge(
            $data['packages'] ?? [],
            $data['packages-dev'] ?? [],
        );

        foreach ($allPackages as $package) {
            if (($package['name'] ?? '') === 'symfony/http-kernel') {
                // Supprime le prefixe "v" eventuel (ex: "v6.4.12" -> "6.4.12").
                return ltrim($package['version'] ?? '', 'v');
            }
        }

        return null;
    }
}