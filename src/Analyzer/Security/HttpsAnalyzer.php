<?php

// src/Analyzer/Security/HttpsAnalyzer.php

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
 * Verifie que le projet force l'utilisation de HTTPS.
 *
 * Detecte les regles access_control sans requires_channel: https et
 * les sessions dont le cookie n'est pas marque secure.
 */
final class HttpsAnalyzer implements AnalyzerInterface
{
    public function __construct(private readonly ConfigReaderInterface $configReader)
    {
    }

    public function analyze(AuditReport $report): void
    {
        $this->checkAccessControlHttps($report);
        $this->checkSessionCookieSecure($report);
    }

    public function getName(): string
    {
        return 'HTTPS Analyzer';
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasSecurityBundle();
    }

    /**
     * Verifie que les regles access_control forcent le canal HTTPS.
     * Sans requires_channel: https, les requetes HTTP ne sont pas redirigees
     * automatiquement vers HTTPS, laissant le trafic en clair.
     */
    private function checkAccessControlHttps(AuditReport $report): void
    {
        $security = $this->configReader->read('config/packages/security.yaml');

        if ($security === null) {
            return;
        }

        $accessControl = $security['security']['access_control'] ?? [];

        if (empty($accessControl) || !is_array($accessControl)) {
            return;
        }

        // Verifie si au moins une regle a requires_channel: https.
        $hasHttpsChannel = false;
        $rulesWithoutHttps = [];

        foreach ($accessControl as $index => $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $channel = $rule['requires_channel'] ?? null;

            if ($channel === 'https') {
                $hasHttpsChannel = true;
            } else {
                $path = $rule['path'] ?? '(non défini)';
                $rulesWithoutHttps[] = "#{$index} (path: {$path})";
            }
        }

        // Si aucune regle ne force HTTPS, signaler le probleme.
        if (!$hasHttpsChannel && !empty($rulesWithoutHttps)) {
            $rulesList = implode(', ', array_slice($rulesWithoutHttps, 0, 5));
            $total = count($rulesWithoutHttps);

            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: "Aucune règle access_control ne force HTTPS ({$total} règle(s) concernée(s))",
                detail: "Les règles access_control suivantes n'ont pas requires_channel: https : {$rulesList}. "
                    . "Sans cette directive, Symfony ne redirige pas automatiquement le trafic HTTP vers HTTPS. "
                    . "Les données transitent potentiellement en clair sur le réseau.",
                suggestion: "Ajouter 'requires_channel: https' sur toutes les règles access_control "
                    . "en production. Alternativement, configurer la redirection HTTPS au niveau du serveur web (nginx, Apache).",
                file: 'config/packages/security.yaml',
                businessImpact: "Les données utilisateurs (identifiants, sessions, données personnelles) "
                    . "transitent en clair sur le réseau et peuvent etre interceptées "
                    . "par un attaquant (attaque man-in-the-middle).",
                fixCode: "security:\n    access_control:\n        - { path: ^/, requires_channel: https }",
                docUrl: 'https://symfony.com/doc/current/security/access_control.html#forcing-https',
                estimatedFixMinutes: 10,
            ));
        }
    }

    /**
     * Verifie que le cookie de session est marque secure.
     * Sans cookie_secure: true, le cookie de session est transmis en HTTP clair.
     */
    private function checkSessionCookieSecure(AuditReport $report): void
    {
        $framework = $this->configReader->read('config/packages/framework.yaml');

        if ($framework === null) {
            return;
        }

        $cookieSecure = $framework['framework']['session']['cookie_secure'] ?? null;

        // cookie_secure peut valoir true, false, 'auto' ou etre absent.
        // 'auto' est acceptable car Symfony detecte HTTPS automatiquement.
        if ($cookieSecure === true || $cookieSecure === 'auto') {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: 'Cookie de session non sécurisé : cookie_secure absent ou désactivé',
            detail: "La directive framework.session.cookie_secure n'est pas définie à true (ni à 'auto'). "
                . "Le cookie de session est donc transmis en HTTP clair, "
                . "ce qui permet à un attaquant de le capturer sur le réseau.",
            suggestion: "Ajouter 'cookie_secure: auto' dans config/packages/framework.yaml. "
                . "La valeur 'auto' laisse Symfony détecter automatiquement si la connexion est HTTPS.",
            file: 'config/packages/framework.yaml',
            businessImpact: "Le cookie de session peut etre intercepté sur une connexion non chiffrée. "
                . "Un attaquant peut alors usurper l'identité d'un utilisateur connecté.",
            fixCode: "framework:\n    session:\n        cookie_secure: auto",
            docUrl: 'https://symfony.com/doc/current/reference/configuration/framework.html#cookie-secure',
            estimatedFixMinutes: 5,
        ));
    }
}
