<?php

namespace PierreArthur\SfDoctor\Analyzer\Security;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Config\ParameterResolverInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

// "final" : pas de raison d'hériter de cet analyzer.
// "implements AnalyzerInterface" : on signe le contrat.
// PHP vérifie qu'on a bien les 4 méthodes : analyze, getModule, getName, supports.
final class FirewallAnalyzer implements AnalyzerInterface
{
    // On injecte ConfigReaderInterface
    // L'analyzer ne sait pas (et s'en fiche) comment la config est lue.
    // Il sait juste qu'il peut appeler read() et exists().
    // C'est le Dependency Inversion Principle en action.
    public function __construct(
        private readonly ConfigReaderInterface $configReader,
        private readonly ParameterResolverInterface $parameterResolver,
    ) {}

    public function analyze(AuditReport $report): void
    {
        // --- Étape 1 : Lire le fichier security.yaml ---
        $security = $this->configReader->read('config/packages/security.yaml');

        // Si le fichier n'existe pas, c'est une info importante.
        // Le projet n'a peut-être pas le SecurityBundle installé.
        if ($security === null) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'Fichier security.yaml introuvable',
                detail: 'Aucune configuration de sécurité détectée dans le projet.',
                suggestion: 'Exécuter : composer require symfony/security-bundle',
            ));

            // On s'arrête ici : sans config, rien d'autre à analyser.
            return;
        }

        // Resoudre les parametres Symfony (%param%) avant l'analyse.
        // En mode bundle, les %param% sont remplaces par leurs valeurs reelles.
        // En mode standalone, la config est retournee sans modification.
        $security = $this->parameterResolver->resolveArray($security);

        $firewalls = $security['security']['firewalls'] ?? [];

        // --- Étape 2 : Extraire les firewalls et access_control ---

        // "null coalescing" retourne la valeur de gauche
        // si elle existe et n'est pas null, sinon la valeur de droite.
        // Si $security['security']['firewalls'] n'existe pas → tableau vide.
        // Programmation défensive : on ne crashe jamais,
        // même si le YAML a une structure inattendue.
        $firewalls = $security['security']['firewalls'] ?? [];
        $accessControl = $security['security']['access_control'] ?? [];

        // --- Étape 3 : Inspecter chaque firewall ---
        foreach ($firewalls as $name => $config) {

            // Le firewall "dev" est un firewall technique de Symfony.
            // Il désactive la sécurité sur les routes du profiler et de la toolbar.
            // C'est NORMAL qu'il soit ouvert. On le saute.
            if ($name === 'dev') {
                continue;
            }

            // Si la config n'est pas un tableau (cas bizarre mais possible),
            // on le saute pour éviter un crash.
            if (!is_array($config)) {
                continue;
            }

            // --- Check 1 : Firewall sans mécanisme d'authentification ---
            // Un firewall actif (security !== false) devrait avoir au moins
            // UN moyen d'authentifier les utilisateurs.
            $this->checkAuthenticator($report, $name, $config);

            // --- Check 2 : Firewall principal sans access_control ---
            // Le firewall "main" est le firewall par défaut de Symfony.
            // Sans access_control, tout le monde peut accéder à tout.
            $this->checkAccessControl($report, $name, $accessControl);

            // --- Check 3 : Mode lazy (bonne pratique) ---
            // lazy: true = le token de sécurité n'est chargé que quand c'est nécessaire.
            // C'est une optimisation de performance recommandée par Symfony.
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
        // On vérifie que le SecurityBundle est installé.
        // class_exists() demande à l'autoloader de Composer :
        // "est-ce que tu connais cette classe ?"
        // Si symfony/security-bundle n'est pas dans composer.json → false.
        return class_exists(\Symfony\Bundle\SecurityBundle\SecurityBundle::class);
    }

    // --- Méthodes privées : chaque check est isolé ---

    /**
     * Vérifie qu'un firewall a au moins un mécanisme d'authentification.
     *
     * @param array<mixed> $config
     */
    private function checkAuthenticator(AuditReport $report, string $name, array $config): void
    {
        // Si le firewall a "security: false", il est volontairement désactivé.
        // Ce n'est pas un problème c'est un choix explicite du développeur.
        // Ex: un firewall pour les routes publiques d'une API.
        if (isset($config['security']) && $config['security'] === false) {
            return;
        }

        // Liste des mécanismes d'authentification reconnus par Symfony.
        // Si au moins un est présent, le firewall est protégé.
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
                // On a trouvé un authenticator → ce firewall est OK pour ce check.
                return;
            }
        }

        // Aucun authenticator trouvé → problème.
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
        ));
    }

    /**
     * Vérifie que le firewall principal a des règles access_control.
     *
     * @param array<mixed> $accessControl
     */
    private function checkAccessControl(AuditReport $report, string $name, array $accessControl): void
    {
        // Ce check ne concerne que le firewall "main".
        // Les autres firewalls peuvent légitimement ne pas avoir d'access_control
        // (ex: un firewall API qui utilise uniquement des Voters).
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
            ));
        }
    }

    /**
     * Vérifie si le firewall utilise le mode lazy (bonne pratique).
     *
     * @param array<mixed> $config
     */
    private function checkLazyMode(AuditReport $report, string $name, array $config): void
    {
        if (isset($config['lazy']) && $config['lazy'] === true) {
            // C'est un constat POSITIF. On note que c'est bien.
            // Les issues OK apparaîtront dans le rapport comme des "checks passés".
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