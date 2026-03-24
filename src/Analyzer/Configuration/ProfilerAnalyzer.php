<?php

// src/Analyzer/Configuration/ProfilerAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Configuration;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Context\ProjectContext;

/**
 * Verifie que le profiler Symfony n'est pas activé en dehors de l'environnement dev.
 */
class ProfilerAnalyzer implements AnalyzerInterface
{
    public function __construct(private readonly ConfigReaderInterface $configReader)
    {
    }

    public function analyze(AuditReport $report): void
    {
        $this->checkWebProfilerConfig($report);
        $this->checkFrameworkProfilerConfig($report);
    }

    public function getName(): string
    {
        return 'Profiler Analyzer';
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasWebProfilerBundle();
    }

    /**
     * Detecte la presence d'un web_profiler.yaml hors du dossier dev/.
     * Un tel fichier active le profiler pour tous les environnements.
     */
    private function checkWebProfilerConfig(AuditReport $report): void
    {
        $config = $this->configReader->read('config/packages/web_profiler.yaml');

        if ($config === null) {
            return;
        }

        $toolbarEnabled = $config['web_profiler']['toolbar'] ?? null;
        $interceptRedirects = $config['web_profiler']['intercept_redirects'] ?? null;

        if ($toolbarEnabled === true) {
            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'Le profiler (toolbar) est activé globalement via config/packages/web_profiler.yaml',
                detail: 'Ce fichier est chargé pour tous les environnements, y compris prod. La barre de debug expose les requêtes SQL, les sessions et les services du container.',
                suggestion: 'Déplacer ce fichier dans config/packages/dev/web_profiler.yaml ou ajouter la condition "when@dev:".',
                file: 'config/packages/web_profiler.yaml',
                businessImpact: 'Le profiler expose les requêtes SQL, les tokens de session et la configuration interne à tout visiteur.',
                fixCode: "# config/packages/dev/web_profiler.yaml\nweb_profiler:\n    toolbar: true\n    intercept_redirects: false",
                docUrl: 'https://symfony.com/doc/current/profiler.html',
                estimatedFixMinutes: 5,
            ));
            return;
        }

        // toolbar absent mais intercept_redirects actif : suspect aussi.
        if ($interceptRedirects === true) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'web_profiler.intercept_redirects est activé globalement',
                detail: 'Ce paramètre intercepte toutes les redirections HTTP pour les inspecter. Actif en prod, il bloque les redirections normales de l\'application.',
                suggestion: 'Déplacer ce fichier dans config/packages/dev/web_profiler.yaml.',
                file: 'config/packages/web_profiler.yaml',
                businessImpact: 'Les redirections sont bloquées en production, causant des erreurs pour les utilisateurs.',
                fixCode: "# config/packages/dev/web_profiler.yaml\nweb_profiler:\n    toolbar: true\n    intercept_redirects: false",
                docUrl: 'https://symfony.com/doc/current/profiler.html',
                estimatedFixMinutes: 5,
            ));
        }
    }

    /**
     * Detecte framework.profiler.enabled: true hors du dossier dev/.
     */
    private function checkFrameworkProfilerConfig(AuditReport $report): void
    {
        $config = $this->configReader->read('config/packages/framework.yaml');

        if ($config === null) {
            return;
        }

        $profilerEnabled = $config['framework']['profiler']['enabled'] ?? null;
        $collectEnabled = $config['framework']['profiler']['collect'] ?? null;

        if ($profilerEnabled === true || $collectEnabled === true) {
            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'framework.profiler est activé globalement via config/packages/framework.yaml',
                detail: 'Le profiler collecte toutes les requêtes, les logs et les données de performance. Activé en prod, il consomme de la mémoire et expose des données internes.',
                suggestion: 'Déplacer la config profiler dans config/packages/dev/framework.yaml ou utiliser "when@dev:".',
                file: 'config/packages/framework.yaml',
                businessImpact: 'Dégradation des performances et exposition de données internes en production.',
                fixCode: "# config/packages/dev/framework.yaml\nframework:\n    profiler:\n        enabled: true\n        collect: true",
                docUrl: 'https://symfony.com/doc/current/profiler.html',
                estimatedFixMinutes: 10,
            ));
        }
    }
}