<?php

namespace PierreArthur\SfDoctor\Analyzer\Security;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Config\ParameterResolverInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class FirewallAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly ConfigReaderInterface $configReader,
        private readonly ParameterResolverInterface $parameterResolver,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $security = $this->configReader->read('config/packages/security.yaml');

        if ($security === null) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'Fichier security.yaml introuvable',
                detail: 'Aucune configuration de sécurité détectée dans le projet.',
                suggestion: 'Exécuter : composer require symfony/security-bundle',
                fixCode: 'composer require symfony/security-bundle',
                docUrl: 'https://symfony.com/doc/current/security.html',
                businessImpact: 'Le projet n\'a aucune couche de sécurité configurée. '
                    . 'Toutes les routes sont accessibles sans authentification.',
                estimatedFixMinutes: 15,
            ));

            return;
        }

        $security = $this->parameterResolver->resolveArray($security);

        $firewalls = $security['security']['firewalls'] ?? [];
        $accessControl = $security['security']['access_control'] ?? [];

        foreach ($firewalls as $name => $config) {
            if ($name === 'dev') {
                continue;
            }

            if (!is_array($config)) {
                continue;
            }

            $this->checkAuthenticator($report, $name, $config);
            $this->checkAccessControl($report, $name, $accessControl);
            $this->checkLazyMode($report, $name, $config);
        }
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function getName(): string
    {
        return 'Firewall Analyzer';
    }

    public function supports(): bool
    {
        return class_exists(\Symfony\Bundle\SecurityBundle\SecurityBundle::class);
    }

    /**
     * @param array<mixed> $config
     */
    private function checkAuthenticator(AuditReport $report, string $name, array $config): void
    {
        if (isset($config['security']) && $config['security'] === false) {
            return;
        }

        $authenticators = [
            'custom_authenticator',
            'form_login',
            'http_basic',
            'json_login',
            'access_token',
            'login_link',
        ];

        foreach ($authenticators as $authenticator) {
            if (isset($config[$authenticator])) {
                return;
            }
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: "Firewall '{$name}' n'a aucun mécanisme d'authentification",
            detail: "Le firewall est actif mais aucun authenticator n'est configuré. "
                . "Les utilisateurs ne pourront pas s'authentifier via ce firewall.",
            suggestion: "Ajouter form_login, json_login, access_token ou un custom_authenticator "
                . "dans la section firewalls.{$name} de security.yaml.",
            file: 'config/packages/security.yaml',
            fixCode: "security:\n    firewalls:\n        {$name}:\n            form_login:\n                login_path: /login\n                check_path: /login",
            docUrl: 'https://symfony.com/doc/current/security.html#form-login',
            businessImpact: 'Les utilisateurs ne peuvent pas se connecter via ce firewall. '
                . 'Les routes protégées sont inaccessibles ou non sécurisées.',
            estimatedFixMinutes: 20,
        ));
    }

    /**
     * @param array<mixed> $accessControl
     */
    private function checkAccessControl(AuditReport $report, string $name, array $accessControl): void
    {
        if ($name !== 'main') {
            return;
        }

        if (empty($accessControl)) {
            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: "Firewall 'main' n'a aucune règle access_control",
                detail: "Tout utilisateur authentifié peut accéder à toutes les routes "
                    . "sous ce firewall. C'est rarement le comportement voulu.",
                suggestion: "Ajouter des règles access_control dans security.yaml. "
                    . "Ex: - { path: ^/admin, roles: ROLE_ADMIN }",
                file: 'config/packages/security.yaml',
                fixCode: "security:\n    access_control:\n        - { path: ^/admin, roles: ROLE_ADMIN }\n        - { path: ^/profile, roles: ROLE_USER }",
                docUrl: 'https://symfony.com/doc/current/security/access_control.html',
                businessImpact: 'Un utilisateur authentifié peut accéder à toutes les routes '
                    . 'du firewall, y compris /admin et les données sensibles.',
                estimatedFixMinutes: 30,
            ));
        }
    }

    /**
     * @param array<mixed> $config
     */
    private function checkLazyMode(AuditReport $report, string $name, array $config): void
    {
        if (isset($config['lazy']) && $config['lazy'] === true) {
            $report->addIssue(new Issue(
                severity: Severity::OK,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: "Firewall '{$name}' utilise le mode lazy (bonne pratique)",
                detail: "Le token de sécurité n'est chargé que quand c'est nécessaire. "
                    . "Cela améliore les performances sur les pages publiques.",
                suggestion: '',
            ));
        }
    }
}