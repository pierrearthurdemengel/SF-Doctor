<?php

// src/Analyzer/Migration/BundleDependencyAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Migration;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Analyse les dependances composer.json pour detecter les bundles
 * abandonnes ou incompatibles avec Symfony 7.x.
 *
 * Verifie que le projet n'utilise pas de bundles sans maintenance
 * et que les contraintes de version sont compatibles avec la migration.
 */
final class BundleDependencyAnalyzer implements AnalyzerInterface
{
    // Bundles connus comme abandonnes ou sans support Symfony 7.
    // Cle : nom du package Composer.
    // Valeur : message explicatif.
    private const ABANDONED_BUNDLES = [
        'friendsofsymfony/user-bundle' => 'FOSUserBundle est abandonne depuis 2022. Aucun support Symfony 6+.',
        'sonata-project/user-bundle' => 'SonataUserBundle < 5.0 ne supporte pas Symfony 7.',
    ];

    // Prefixes de packages Symfony principaux a verifier.
    private const SYMFONY_PACKAGE_PREFIXES = [
        'symfony/',
    ];

    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $composerFile = $this->projectPath . '/composer.json';
        $content = file_get_contents($composerFile);

        if ($content === false) {
            return;
        }

        $composer = json_decode($content, true);

        if (!is_array($composer) || !isset($composer['require'])) {
            return;
        }

        /** @var array<string, string> $require */
        $require = $composer['require'];

        $this->checkAbandonedBundles($report, $require);
        $this->checkSymfony7Compatibility($report, $require);
    }

    public function getName(): string
    {
        return 'Bundle Dependency Analyzer';
    }

    public function getModule(): Module
    {
        return Module::MIGRATION;
    }

    public function supports(ProjectContext $context): bool
    {
        return file_exists($context->getProjectPath() . '/composer.json');
    }

    /**
     * Detecte les bundles abandonnes dans les dependances.
     *
     * Ces bundles n'ont plus de maintenance active et bloquent
     * la migration vers Symfony 7 ou 8.
     *
     * @param array<string, string> $require
     */
    private function checkAbandonedBundles(AuditReport $report, array $require): void
    {
        foreach (self::ABANDONED_BUNDLES as $package => $reason) {
            if (!isset($require[$package])) {
                continue;
            }

            // Cas special : SonataUserBundle >= 5.0 est supporte.
            if ($package === 'sonata-project/user-bundle') {
                $constraint = $require[$package];
                if ($this->constraintSatisfiesMinimum($constraint, '5.0')) {
                    continue;
                }
            }

            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::MIGRATION,
                analyzer: $this->getName(),
                message: sprintf('Bundle abandonne detecte : %s', $package),
                detail: sprintf(
                    'Le projet depend de "%s" (contrainte : %s). %s '
                    . 'Ce bundle bloque la migration vers les versions recentes de Symfony.',
                    $package,
                    $require[$package],
                    $reason,
                ),
                suggestion: 'Remplacer ce bundle par une solution officielle ou maintenue activement. '
                    . 'Pour FOSUserBundle, utiliser le composant Security de Symfony directement. '
                    . 'Pour SonataUserBundle, migrer vers la version 5.x ou une alternative.',
                file: 'composer.json',
                fixCode: "composer remove {$package}\n# Puis migrer vers la solution de remplacement.",
                docUrl: 'https://symfony.com/doc/current/security.html',
                businessImpact: 'Un bundle abandonne ne recoit plus de correctifs de securite. '
                    . 'Les failles decouvertes ne seront jamais corrigees, exposant le projet '
                    . 'a des risques croissants avec le temps.',
                estimatedFixMinutes: 120,
            ));
        }
    }

    /**
     * Verifie que les bundles Symfony ont une contrainte compatible avec Symfony 7.x.
     *
     * @param array<string, string> $require
     */
    private function checkSymfony7Compatibility(AuditReport $report, array $require): void
    {
        $incompatiblePackages = [];

        foreach ($require as $package => $constraint) {
            // Ne verifier que les packages symfony/*.
            $isSymfonyPackage = false;
            foreach (self::SYMFONY_PACKAGE_PREFIXES as $prefix) {
                if (str_starts_with($package, $prefix)) {
                    $isSymfonyPackage = true;
                    break;
                }
            }

            if (!$isSymfonyPackage) {
                continue;
            }

            // Ignorer les packages utilitaires qui ne suivent pas le versionnement du framework.
            if (in_array($package, ['symfony/flex', 'symfony/runtime', 'symfony/polyfill-php83'], true)) {
                continue;
            }

            // Verifier si la contrainte autorise 7.x.
            if (!$this->constraintAllowsVersion($constraint, '7')) {
                $incompatiblePackages[$package] = $constraint;
            }
        }

        if (count($incompatiblePackages) === 0) {
            return;
        }

        $packageList = [];
        foreach ($incompatiblePackages as $package => $constraint) {
            $packageList[] = sprintf('%s (%s)', $package, $constraint);
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::MIGRATION,
            analyzer: $this->getName(),
            message: sprintf(
                '%d package%s Symfony sans contrainte compatible Symfony 7.x',
                count($incompatiblePackages),
                count($incompatiblePackages) > 1 ? 's' : '',
            ),
            detail: 'Les packages suivants ont une contrainte de version qui ne permet pas '
                . 'l\'installation de Symfony 7.x : ' . implode(', ', $packageList) . '. '
                . 'La migration vers Symfony 7 sera bloquee tant que ces contraintes ne seront pas '
                . 'elargies.',
            suggestion: 'Mettre a jour les contraintes dans composer.json pour autoriser ^7.0 '
                . 'en plus de la version actuelle. Exemple : "^6.4 || ^7.0".',
            file: 'composer.json',
            fixCode: "# Exemple pour un package :\n\"symfony/framework-bundle\": \"^6.4 || ^7.0\"",
            docUrl: 'https://symfony.com/doc/current/setup/upgrade_major.html',
            businessImpact: 'Sans contrainte compatible, la migration vers Symfony 7 est impossible. '
                . 'Le projet reste bloque sur une version qui finira par ne plus recevoir '
                . 'de correctifs de securite.',
            estimatedFixMinutes: 30,
        ));
    }

    /**
     * Verifie si une contrainte Composer autorise une version majeure donnee.
     *
     * Analyse simplifiee : recherche le numero de version majeure dans la contrainte.
     * Couvre les cas courants : ^7.0, ~7.0, >=7.0, 7.*, || 7.x.
     */
    private function constraintAllowsVersion(string $constraint, string $majorVersion): bool
    {
        // Si la contrainte contient directement le numero de version majeure.
        if (preg_match('/[\^~>=]*' . preg_quote($majorVersion, '/') . '[\.\d]*/', $constraint)) {
            return true;
        }

        // Contrainte * ou >= sans borne superieure.
        if ($constraint === '*') {
            return true;
        }

        return false;
    }

    /**
     * Verifie si une contrainte Composer satisfait au minimum une version donnee.
     *
     * Analyse simplifiee pour les cas courants (^X.Y, >=X.Y).
     */
    private function constraintSatisfiesMinimum(string $constraint, string $minVersion): bool
    {
        // Extraire le numero de version de la contrainte.
        if (preg_match('/(\d+\.\d+)/', $constraint, $matches)) {
            return version_compare($matches[1], $minVersion, '>=');
        }

        return false;
    }
}
