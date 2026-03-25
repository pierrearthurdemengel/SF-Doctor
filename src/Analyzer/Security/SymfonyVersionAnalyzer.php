<?php

// src/Analyzer/Security/SymfonyVersionAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Security;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Verifie la version de Symfony installee et detecte :
 * 1. Les versions avec des CVE connus non patches
 * 2. Les versions en fin de vie (end of life)
 * 3. Les versions non-LTS (risque de perte de support)
 */
final class SymfonyVersionAnalyzer implements AnalyzerInterface
{
    private ?string $symfonyVersion = null;

    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $this->detectSymfonyVersion();

        if ($this->symfonyVersion === null) {
            return;
        }

        $this->checkKnownCve($report);
        $this->checkEndOfLife($report);
        $this->checkNonLts($report);
    }

    public function getName(): string
    {
        return 'Symfony Version Analyzer';
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->getSymfonyVersion() !== null;
    }

    /**
     * Lit composer.lock pour extraire la version de symfony/framework-bundle.
     * Stocke le resultat dans $this->symfonyVersion.
     */
    private function detectSymfonyVersion(): void
    {
        $lockFile = $this->projectPath . '/composer.lock';

        if (!file_exists($lockFile)) {
            return;
        }

        $content = file_get_contents($lockFile);

        if ($content === false) {
            return;
        }

        $lock = json_decode($content, true);

        if (!is_array($lock)) {
            return;
        }

        $packages = array_merge(
            $lock['packages'] ?? [],
            $lock['packages-dev'] ?? [],
        );

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            if (($package['name'] ?? '') !== 'symfony/framework-bundle') {
                continue;
            }

            $version = $package['version'] ?? '';

            // Supprimer le prefixe "v" eventuel (ex: "v6.4.12" -> "6.4.12").
            $this->symfonyVersion = ltrim($version, 'v');

            return;
        }
    }

    /**
     * Verifie si la version installee est anterieure aux patchs de securite connus.
     * Seuils bases sur les derniers advisories Symfony :
     * - 5.4.46 : patchs CVE pour la branche 5.4
     * - 6.4.14 : patchs CVE pour la branche 6.4
     * - 7.1.7  : patchs CVE pour la branche 7.1
     */
    private function checkKnownCve(AuditReport $report): void
    {
        $version = $this->symfonyVersion;

        if ($version === null) {
            return;
        }

        $vulnerable = false;

        // Branche 5.4.x : vulnerable si < 5.4.46
        if (
            version_compare($version, '5.4.0', '>=')
            && version_compare($version, '5.4.46', '<')
        ) {
            $vulnerable = true;
        }

        // Branche 6.x : vulnerable si < 6.4.14
        if (
            version_compare($version, '6.0.0', '>=')
            && version_compare($version, '6.4.14', '<')
        ) {
            $vulnerable = true;
        }

        // Branche 7.x : vulnerable si < 7.1.7
        if (
            version_compare($version, '7.0.0', '>=')
            && version_compare($version, '7.1.7', '<')
        ) {
            $vulnerable = true;
        }

        if (!$vulnerable) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: "Symfony {$version} contient des failles de securite connues (CVE)",
            detail: "La version {$version} est anterieure aux derniers patchs de securite. "
                . "Des correctifs critiques ont ete publies dans les versions 5.4.46, 6.4.14 et 7.1.7. "
                . "Consulter https://symfony.com/blog/category/security-advisories pour les details.",
            suggestion: "Mettre a jour Symfony vers la derniere version patchee de votre branche : "
                . "composer update 'symfony/*'",
            file: 'composer.lock',
            fixCode: "composer update 'symfony/*'",
            docUrl: 'https://symfony.com/blog/category/security-advisories',
            businessImpact: 'La version installee contient des vulnerabilites de securite connues et documentees. '
                . 'Un attaquant peut exploiter ces failles pour compromettre l\'application.',
            estimatedFixMinutes: 30,
        ));
    }

    /**
     * Detecte les versions Symfony 5.x, en fin de vie depuis novembre 2024.
     * Plus aucun patch de securite n'est publie pour ces versions.
     */
    private function checkEndOfLife(AuditReport $report): void
    {
        $version = $this->symfonyVersion;

        if ($version === null) {
            return;
        }

        if (
            !version_compare($version, '5.0.0', '>=')
            || !version_compare($version, '6.0.0', '<')
        ) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: "Symfony {$version} est en fin de vie (end of life depuis novembre 2024)",
            detail: "La branche Symfony 5.x ne recoit plus aucun patch de securite ni correction "
                . "de bug depuis novembre 2024. Toute nouvelle vulnerabilite decouverte ne sera "
                . "pas corrigee pour cette version.",
            suggestion: "Planifier la migration vers Symfony 6.4 (LTS) ou 7.x. "
                . "Utiliser rector/rector pour automatiser les modifications de code.",
            file: 'composer.json',
            fixCode: "composer require symfony/flex\ncomposer recipes:update\n# Puis mettre a jour les dependances vers 6.4",
            docUrl: 'https://symfony.com/releases',
            businessImpact: 'Aucun correctif de securite ne sera publie pour Symfony 5.x. '
                . 'Toute faille decouverte a partir de maintenant restera exploitable indefiniment.',
            estimatedFixMinutes: 480,
        ));
    }

    /**
     * Detecte les versions Symfony anterieures a 6.4 (hors branche 5.x deja traitee).
     * Symfony 6.4 est la version LTS recommandee.
     */
    private function checkNonLts(AuditReport $report): void
    {
        $version = $this->symfonyVersion;

        if ($version === null) {
            return;
        }

        // Ne pas dupliquer l'alerte pour les 5.x (deja traitee dans checkEndOfLife).
        if (version_compare($version, '6.0.0', '<')) {
            return;
        }

        if (version_compare($version, '6.4.0', '>=')) {
            return;
        }

        // Version 6.0, 6.1, 6.2 ou 6.3 - non LTS.
        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: "Symfony {$version} n'est pas une version LTS",
            detail: "Les versions Symfony 6.0 a 6.3 ne sont plus maintenues. "
                . "Seule la version 6.4 (LTS) recoit des correctifs de securite "
                . "et de bugs jusqu'en novembre 2027.",
            suggestion: "Mettre a jour vers Symfony 6.4 (LTS) : composer update 'symfony/*'",
            file: 'composer.json',
            fixCode: "composer update 'symfony/*'",
            docUrl: 'https://symfony.com/releases/6.4',
            businessImpact: 'Cette version de Symfony ne recoit plus de correctifs de securite. '
                . 'La migration vers la version 6.4 LTS est necessaire pour garantir le support.',
            estimatedFixMinutes: 120,
        ));
    }
}
