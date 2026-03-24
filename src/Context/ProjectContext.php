<?php

// src/Context/ProjectContext.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Context;

/**
 * Represente le contexte technique d'un projet Symfony audite.
 * Construit une seule fois au debut de l'analyse, transmis a chaque analyzer.
 */
final class ProjectContext
{
    public function __construct(
        // Chemin absolu vers la racine du projet audite.
        private readonly string $projectPath,

        // Bundles et composants detectes dans composer.json.
        private readonly bool $hasDoctrineOrm,
        private readonly bool $hasMessenger,
        private readonly bool $hasApiPlatform,
        private readonly bool $hasTwig,
        private readonly bool $hasSecurityBundle,
        private readonly bool $hasWebProfilerBundle,
        private readonly bool $hasMailer,
        private readonly bool $hasNelmioCors,
        private readonly bool $hasNelmioSecurity,
        private readonly bool $hasJwtAuth,

        // Version Symfony installee (ex: "6.4.12" ou "7.1.3").
        // Null si non determinable.
        private readonly ?string $symfonyVersion,
    ) {
    }

    public function getProjectPath(): string
    {
        return $this->projectPath;
    }

    public function hasDoctrineOrm(): bool
    {
        return $this->hasDoctrineOrm;
    }

    public function hasMessenger(): bool
    {
        return $this->hasMessenger;
    }

    public function hasApiPlatform(): bool
    {
        return $this->hasApiPlatform;
    }

    public function hasTwig(): bool
    {
        return $this->hasTwig;
    }

    public function hasSecurityBundle(): bool
    {
        return $this->hasSecurityBundle;
    }

    public function hasWebProfilerBundle(): bool
    {
        return $this->hasWebProfilerBundle;
    }

    public function hasMailer(): bool
    {
        return $this->hasMailer;
    }

    public function hasNelmioCors(): bool
    {
        return $this->hasNelmioCors;
    }

    public function hasNelmioSecurity(): bool
    {
        return $this->hasNelmioSecurity;
    }

    public function hasJwtAuth(): bool
    {
        return $this->hasJwtAuth;
    }

    public function getSymfonyVersion(): ?string
    {
        return $this->symfonyVersion;
    }

    /**
     * Verifie si la version Symfony installee est superieure ou egale a la version donnee.
     * Retourne false si la version est indeterminee.
     */
    public function isSymfonyAtLeast(string $minVersion): bool
    {
        if ($this->symfonyVersion === null) {
            return false;
        }

        return version_compare($this->symfonyVersion, $minVersion, '>=');
    }
}