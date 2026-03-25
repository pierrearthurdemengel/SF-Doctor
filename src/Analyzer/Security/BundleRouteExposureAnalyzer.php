<?php

// src/Analyzer/Security/BundleRouteExposureAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Security;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les routes exposees par des bundles tiers sans protection firewall.
 *
 * Verifie que les bundles d'administration (EasyAdmin, SonataAdmin) et
 * la documentation API Platform sont proteges par des regles access_control
 * dans security.yaml. Sans protection, ces routes exposent des interfaces
 * d'administration ou de la documentation sensible a tout visiteur.
 */
final class BundleRouteExposureAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
        private readonly ConfigReaderInterface $configReader,
    ) {
    }

    public function analyze(AuditReport $report): void
    {
        $composerData = $this->readComposerJson();

        if ($composerData === null) {
            return;
        }

        $security = $this->configReader->read('config/packages/security.yaml');
        $accessControl = $security['security']['access_control'] ?? [];

        $installedPackages = $this->getInstalledPackages($composerData);

        $this->checkEasyAdmin($report, $installedPackages, $accessControl);
        $this->checkSonataAdmin($report, $installedPackages, $accessControl);
        $this->checkApiPlatformDocs($report, $installedPackages, $accessControl);
    }

    public function getName(): string
    {
        return 'Bundle Route Exposure Analyzer';
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function supports(ProjectContext $context): bool
    {
        return true;
    }

    /**
     * Lit et parse le fichier composer.json du projet audite.
     *
     * @return array<mixed>|null
     */
    private function readComposerJson(): ?array
    {
        $composerPath = $this->projectPath . '/composer.json';

        if (!file_exists($composerPath)) {
            return null;
        }

        $content = file_get_contents($composerPath);

        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Extrait la liste des packages installes depuis require et require-dev.
     *
     * @param array<mixed> $composerData
     * @return list<string>
     */
    private function getInstalledPackages(array $composerData): array
    {
        $require = $composerData['require'] ?? [];
        $requireDev = $composerData['require-dev'] ?? [];

        $packages = array_merge(
            is_array($require) ? array_keys($require) : [],
            is_array($requireDev) ? array_keys($requireDev) : [],
        );

        return array_values($packages);
    }

    /**
     * Verifie si un package est present dans la liste des packages installes.
     *
     * @param list<string> $installedPackages
     */
    private function hasPackage(array $installedPackages, string $packageName): bool
    {
        return in_array($packageName, $installedPackages, true);
    }

    /**
     * Verifie si un chemin est couvert par au moins une regle access_control.
     * Teste le chemin contre les patterns regex definis dans les regles.
     *
     * @param list<mixed> $accessControl
     */
    private function isPathCovered(string $path, array $accessControl): bool
    {
        foreach ($accessControl as $rule) {
            if (!is_array($rule) || !isset($rule['path'])) {
                continue;
            }

            $pattern = $rule['path'];

            if (@preg_match('#' . $pattern . '#', $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifie que EasyAdminBundle est protege par une regle access_control sur /admin.
     *
     * EasyAdmin expose une interface d'administration complete permettant de creer,
     * modifier et supprimer des entites. Sans protection, n'importe quel visiteur
     * peut acceder au back-office et manipuler les donnees.
     *
     * @param list<string> $installedPackages
     * @param list<mixed>  $accessControl
     */
    private function checkEasyAdmin(AuditReport $report, array $installedPackages, array $accessControl): void
    {
        if (!$this->hasPackage($installedPackages, 'easycorp/easyadmin-bundle')) {
            return;
        }

        if ($this->isPathCovered('/admin', $accessControl)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: 'EasyAdminBundle installe sans protection access_control sur /admin',
            detail: "Le package easycorp/easyadmin-bundle est installe mais aucune regle access_control "
                . "ne couvre le chemin /admin dans security.yaml. EasyAdmin expose une interface "
                . "d'administration complete permettant de creer, modifier et supprimer des entites. "
                . "Sans protection, n'importe quel visiteur peut acceder au back-office.",
            suggestion: "Ajouter une regle access_control dans security.yaml pour restreindre "
                . "l'acces a /admin aux utilisateurs avec le role ROLE_ADMIN.",
            file: 'config/packages/security.yaml',
            fixCode: "security:\n    access_control:\n"
                . "        - { path: ^/admin, roles: ROLE_ADMIN }",
            docUrl: 'https://symfony.com/bundles/EasyAdminBundle/current/security.html',
            businessImpact: "N'importe quel visiteur peut acceder a l'interface d'administration, "
                . "consulter, modifier ou supprimer toutes les donnees de l'application. "
                . "Cela represente un risque critique de fuite et de corruption de donnees.",
            estimatedFixMinutes: 10,
        ));
    }

    /**
     * Verifie que SonataAdminBundle est protege par une regle access_control sur /admin.
     *
     * SonataAdmin expose une interface d'administration complete avec gestion CRUD,
     * tableaux de bord et exports. Sans protection, le back-office est accessible
     * a tout visiteur.
     *
     * @param list<string> $installedPackages
     * @param list<mixed>  $accessControl
     */
    private function checkSonataAdmin(AuditReport $report, array $installedPackages, array $accessControl): void
    {
        if (!$this->hasPackage($installedPackages, 'sonata-project/admin-bundle')) {
            return;
        }

        if ($this->isPathCovered('/admin', $accessControl)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: 'SonataAdminBundle installe sans protection access_control sur /admin',
            detail: "Le package sonata-project/admin-bundle est installe mais aucune regle access_control "
                . "ne couvre le chemin /admin dans security.yaml. SonataAdmin expose une interface "
                . "d'administration complete avec gestion CRUD, tableaux de bord et exports. "
                . "Sans protection, le back-office est accessible a tout visiteur.",
            suggestion: "Ajouter une regle access_control dans security.yaml pour restreindre "
                . "l'acces a /admin aux utilisateurs avec le role ROLE_ADMIN.",
            file: 'config/packages/security.yaml',
            fixCode: "security:\n    access_control:\n"
                . "        - { path: ^/admin, roles: ROLE_ADMIN }",
            docUrl: 'https://docs.sonata-project.org/projects/SonataAdminBundle/en/4.x/reference/security/',
            businessImpact: "N'importe quel visiteur peut acceder a l'interface d'administration Sonata, "
                . "consulter les tableaux de bord, exporter des donnees et effectuer des operations CRUD "
                . "sur toutes les entites. Cela represente un risque critique de fuite et de corruption de donnees.",
            estimatedFixMinutes: 10,
        ));
    }

    /**
     * Verifie que la documentation API Platform /api/docs est protegee.
     *
     * API Platform expose par defaut une documentation interactive Swagger/OpenAPI
     * sur /api/docs. Cette documentation revele la structure complete de l'API
     * (endpoints, parametres, schemas de donnees) ce qui facilite la reconnaissance
     * par un attaquant.
     *
     * @param list<string> $installedPackages
     * @param list<mixed>  $accessControl
     */
    private function checkApiPlatformDocs(AuditReport $report, array $installedPackages, array $accessControl): void
    {
        if (!$this->hasPackage($installedPackages, 'api-platform/core')) {
            return;
        }

        if ($this->isPathCovered('/api/docs', $accessControl)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: 'API Platform /api/docs accessible sans restriction access_control',
            detail: "Le package api-platform/core est installe mais aucune regle access_control "
                . "ne couvre le chemin /api/docs dans security.yaml. API Platform expose par defaut "
                . "une documentation interactive Swagger/OpenAPI qui revele la structure complete "
                . "de l'API : endpoints, parametres, schemas de donnees et relations entre entites.",
            suggestion: "Ajouter une regle access_control pour restreindre l'acces a /api/docs "
                . "en production, ou desactiver la documentation Swagger dans la configuration "
                . "API Platform pour l'environnement de production.",
            file: 'config/packages/security.yaml',
            fixCode: "security:\n    access_control:\n"
                . "        - { path: ^/api/docs, roles: ROLE_ADMIN }",
            docUrl: 'https://api-platform.com/docs/core/swagger/',
            businessImpact: "La documentation interactive de l'API est accessible publiquement. "
                . "Un attaquant peut decouvrir tous les endpoints, comprendre la structure des donnees "
                . "et identifier des cibles potentielles sans authentification.",
            estimatedFixMinutes: 10,
        ));
    }
}
