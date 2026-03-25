<?php

// src/Analyzer/Security/ExposedDebugEndpointsAnalyzer.php

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
 * Verifie que les endpoints de debug (profiler, WDT) sont proteges par access_control.
 *
 * Quand web_profiler_bundle est installe, les routes /_profiler et /_wdt exposent
 * des informations sensibles (requetes SQL, variables d'environnement, sessions).
 * En production, ces routes doivent etre bloquees par access_control ou desactivees.
 */
final class ExposedDebugEndpointsAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly ConfigReaderInterface $configReader,
    ) {
    }

    public function analyze(AuditReport $report): void
    {
        $security = $this->configReader->read('config/packages/security.yaml');

        if ($security === null) {
            return;
        }

        $accessControl = $security['security']['access_control'] ?? [];

        $this->checkProfilerProtection($report, $accessControl);
        $this->checkWdtProtection($report, $accessControl);
    }

    public function getName(): string
    {
        return 'Exposed Debug Endpoints Analyzer';
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
     * Verifie qu'une regle access_control couvre le chemin /_profiler.
     * Le profiler expose des informations tres sensibles : requetes SQL,
     * variables d'environnement, donnees de session, informations serveur.
     *
     * @param list<mixed> $accessControl
     */
    private function checkProfilerProtection(AuditReport $report, array $accessControl): void
    {
        if ($this->isPathCovered('/_profiler', $accessControl)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: 'Aucune regle access_control ne couvre /_profiler',
            detail: "Le web_profiler_bundle est installe mais aucune regle access_control "
                . "ne restreint l'acces a /_profiler. En production, ce chemin expose "
                . "des informations extremement sensibles : requetes SQL completes, "
                . "variables d'environnement, donnees de session, configuration du serveur.",
            suggestion: "Ajouter une regle access_control pour bloquer l'acces a /_profiler "
                . "en production, ou s'assurer que le bundle est desactive en prod "
                . "(when@dev dans la configuration).",
            file: 'config/packages/security.yaml',
            fixCode: "security:\n    access_control:\n"
                . "        # Bloquer le profiler en production\n"
                . "        - { path: ^/_profiler, roles: ROLE_ADMIN }",
            docUrl: 'https://symfony.com/doc/current/profiler.html#enabling-the-profiler-conditionally',
            businessImpact: "Un attaquant peut consulter le profiler pour voir les requetes SQL, "
                . "les variables d'environnement (dont les secrets), "
                . "les donnees de session des utilisateurs et la configuration interne du serveur.",
            estimatedFixMinutes: 5,
        ));
    }

    /**
     * Verifie qu'une regle access_control couvre le chemin /_wdt.
     * La Web Debug Toolbar expose un resume des informations du profiler
     * directement dans la page.
     *
     * @param list<mixed> $accessControl
     */
    private function checkWdtProtection(AuditReport $report, array $accessControl): void
    {
        if ($this->isPathCovered('/_wdt', $accessControl)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: 'Aucune regle access_control ne couvre /_wdt',
            detail: "Le web_profiler_bundle est installe mais aucune regle access_control "
                . "ne restreint l'acces a /_wdt (Web Debug Toolbar). "
                . "Ce chemin expose des informations de debug (temps de reponse, "
                . "nombre de requetes SQL, memoire utilisee) et donne acces au profiler complet.",
            suggestion: "Ajouter une regle access_control pour bloquer l'acces a /_wdt "
                . "en production, ou s'assurer que le bundle est desactive en prod.",
            file: 'config/packages/security.yaml',
            fixCode: "security:\n    access_control:\n"
                . "        # Bloquer la Web Debug Toolbar en production\n"
                . "        - { path: ^/_wdt, roles: ROLE_ADMIN }",
            docUrl: 'https://symfony.com/doc/current/profiler.html',
            businessImpact: "La Web Debug Toolbar expose des metriques internes de l'application "
                . "(performances, requetes SQL, memoire) et fournit un lien direct vers le profiler. "
                . "Un attaquant peut utiliser ces informations pour identifier des faiblesses.",
            estimatedFixMinutes: 5,
        ));
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
}
