<?php

namespace PierreArthur\SfDoctor\Analyzer\Security;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Config\ParameterResolverInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class AccessControlAnalyzer implements AnalyzerInterface
{
    // Même injection que le FirewallAnalyzer :
    // on dépend du contrat, pas de l'implémentation.
    public function __construct(
        private readonly ConfigReaderInterface $configReader,
        private readonly ParameterResolverInterface $parameterResolver,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $security = $this->configReader->read('config/packages/security.yaml');

        // Pas de fichier security.yaml → rien à analyser.
        // Le FirewallAnalyzer a déjà émis un WARNING pour ça.
        // On ne duplique pas l'alerte.
        if ($security === null) {
            return;
        }

        // Resoudre les parametres Symfony (%param%) avant l'analyse.
        $security = $this->parameterResolver->resolveArray($security);

        $accessControl = $security['security']['access_control'] ?? [];

        // Si access_control est vide, le FirewallAnalyzer s'en occupe déjà.
        // On analyse la QUALITÉ des règles quand elles existent.
        if (empty($accessControl)) {
            return;
        }

        // On inspecte chaque règle, une par une.
        foreach ($accessControl as $index => $rule) {
            if (!is_array($rule)) {
                continue;
            }

            // Check 1 : Règle sans rôle requis
            $this->checkMissingRoles($report, $rule, $index);

            // Check 2 : Utilisation de rôles dépréciés
            $this->checkDeprecatedRoles($report, $rule, $index);
        }

        // Check 3 : Règle "attrape-tout" mal placée (pas en dernière position)
        $this->checkCatchAllOrder($report, $accessControl);

        // Check 4 : Les chemins sensibles sont-ils protégés ?
        $this->checkSensitivePaths($report, $accessControl);
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function getName(): string
    {
        return 'Access Control Analyzer';
    }

    public function supports(): bool
    {
        return class_exists(\Symfony\Bundle\SecurityBundle\SecurityBundle::class);
    }

    // --- Checks privés ---

    /**
     * Vérifie qu'une règle access_control a bien un rôle requis.
     *
     * Une règle sans "roles" laisse passer tout le monde.
     * C'est rarement intentionnel.
     *
     * @param array<mixed> $rule
     */
    private function checkMissingRoles(AuditReport $report, array $rule, int $index): void
    {
        // "roles" peut être une string ou un tableau dans security.yaml.
        // S'il est absent, la règle ne demande aucun rôle → tout le monde passe.
        if (!isset($rule['roles'])) {
            $path = $rule['path'] ?? '(non défini)';

            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: "Règle access_control #{$index} sans rôle requis (path: {$path})",
                detail: "Cette règle n'exige aucun rôle. Tout utilisateur, "
                    . "même non authentifié, peut accéder à ce chemin.",
                suggestion: "Ajouter un rôle : roles: ROLE_USER ou roles: IS_AUTHENTICATED_FULLY",
                file: 'config/packages/security.yaml',
            ));
            return;
        }

        // "roles" existe mais est vide (ex: roles: [] ou roles: '')
        $roles = $rule['roles'];
        if ((is_array($roles) && empty($roles)) || (is_string($roles) && trim($roles) === '')) {
            $path = $rule['path'] ?? '(non défini)';

            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: "Règle access_control #{$index} avec rôle vide (path: {$path})",
                detail: "Le champ 'roles' est présent mais vide. C'est probablement un oubli.",
                suggestion: "Spécifier un rôle : roles: ROLE_USER",
                file: 'config/packages/security.yaml',
            ));
        }
    }

    /**
     * Détecte les rôles dépréciés dans les règles access_control.
     *
     * IS_AUTHENTICATED_ANONYMOUSLY a été remplacé par PUBLIC_ACCESS en Symfony 6.
     * Les projets migrés oublient souvent de mettre à jour cette valeur.
     *
     * @param array<mixed> $rule
     */
    private function checkDeprecatedRoles(AuditReport $report, array $rule, int $index): void
    {
        if (!isset($rule['roles'])) {
            return;
        }

        // Normaliser en tableau : "roles" peut être une string ou un array.
        // En YAML, "roles: ROLE_ADMIN" donne une string,
        // "roles: [ROLE_ADMIN, ROLE_USER]" donne un array.
        $roles = is_array($rule['roles']) ? $rule['roles'] : [$rule['roles']];
        $path = $rule['path'] ?? '(non défini)';

        foreach ($roles as $role) {
            if (!is_string($role)) {
                continue;
            }

            // IS_AUTHENTICATED_ANONYMOUSLY est déprécié depuis Symfony 5.4.
            // Remplacé par PUBLIC_ACCESS.
            if ($role === 'IS_AUTHENTICATED_ANONYMOUSLY') {
                $report->addIssue(new Issue(
                    severity: Severity::WARNING,
                    module: Module::SECURITY,
                    analyzer: $this->getName(),
                    message: "Rôle déprécié 'IS_AUTHENTICATED_ANONYMOUSLY' (règle #{$index}, path: {$path})",
                    detail: "Ce rôle est déprécié depuis Symfony 5.4 et sera supprimé dans une future version.",
                    suggestion: "Remplacer par PUBLIC_ACCESS.",
                    file: 'config/packages/security.yaml',
                ));
            }

            // ROLE_ANONYMOUS n'existe plus depuis Symfony 6.0
            if ($role === 'ROLE_ANONYMOUS') {
                $report->addIssue(new Issue(
                    severity: Severity::WARNING,
                    module: Module::SECURITY,
                    analyzer: $this->getName(),
                    message: "Rôle supprimé 'ROLE_ANONYMOUS' (règle #{$index}, path: {$path})",
                    detail: "Ce rôle n'existe plus depuis Symfony 6.0.",
                    suggestion: "Remplacer par PUBLIC_ACCESS ou IS_AUTHENTICATED.",
                    file: 'config/packages/security.yaml',
                ));
            }
        }
    }

    /**
     * Vérifie qu'une règle "attrape-tout" (^/) n'est pas placée avant des règles plus spécifiques.
     *
     * En Symfony, les règles access_control sont évaluées DANS L'ORDRE.
     * La première qui matche l'URL gagne. Si une règle ^/ (qui matche tout)
     * est en position 0, les règles suivantes ne seront JAMAIS atteintes.
     *
     * @param list<mixed> $accessControl
     */
    private function checkCatchAllOrder(AuditReport $report, array $accessControl): void
    {
        $totalRules = count($accessControl);

        // Moins de 2 règles → pas de problème d'ordre possible.
        if ($totalRules < 2) {
            return;
        }

        foreach ($accessControl as $index => $rule) {
            if (!is_array($rule) || !isset($rule['path'])) {
                continue;
            }

            $path = $rule['path'];

            // Détecter les patterns "attrape-tout" : ^/ ou ^.* ou aucun path.
            // ^/ matche littéralement TOUTES les URLs (elles commencent toutes par /).
            $isCatchAll = ($path === '^/' || $path === '^.*' || $path === '/');

            // Si c'est un attrape-tout ET qu'il n'est PAS en dernière position
            // les règles qui suivent sont mortes (jamais atteintes).
            if ($isCatchAll && $index < $totalRules - 1) {
                $report->addIssue(new Issue(
                    severity: Severity::CRITICAL,
                    module: Module::SECURITY,
                    analyzer: $this->getName(),
                    message: "Règle access_control attrape-tout en position #{$index} sur {$totalRules}",
                    detail: "La règle avec path '{$path}' matche toutes les URLs. "
                        . "Placée en position #{$index}, elle rend les "
                        . ($totalRules - $index - 1) . " règle(s) suivante(s) inaccessible(s). "
                        . "Les règles access_control sont évaluées dans l'ordre : "
                        . "la première qui matche gagne.",
                    suggestion: "Déplacer cette règle en DERNIÈRE position, "
                        . "après toutes les règles spécifiques.",
                    file: 'config/packages/security.yaml',
                ));
            }
        }
    }

    /**
     * Vérifie que les chemins sensibles courants sont couverts par une règle access_control.
     *
     * On cherche si /admin et /api sont protégés. Ce sont les cibles
     * les plus courantes d'accès non autorisé.
     *
     * @param list<mixed> $accessControl
     */
    private function checkSensitivePaths(AuditReport $report, array $accessControl): void
    {
        // Liste des chemins sensibles courants dans un projet Symfony.
        // Pour chaque chemin, on vérifie qu'au moins une règle access_control
        // le couvre (son pattern matche le chemin).
        $sensitivePaths = [
            '/admin' => 'la section administration',
            '/api' => "les endpoints d'API",
        ];

        foreach ($sensitivePaths as $path => $description) {
            if (!$this->isPathCovered($path, $accessControl)) {
                $report->addIssue(new Issue(
                    severity: Severity::SUGGESTION,
                    module: Module::SECURITY,
                    analyzer: $this->getName(),
                    message: "Aucune règle access_control ne couvre '{$path}'",
                    detail: "Le chemin '{$path}' ({$description}) n'est couvert "
                        . "par aucune règle access_control. Si ce chemin existe "
                        . "dans votre projet, il est potentiellement accessible "
                        . "sans restriction de rôle.",
                    suggestion: "Ajouter une règle : - { path: ^{$path}, roles: ROLE_ADMIN }. "
                        . "Ignorez cette suggestion si ce chemin n'existe pas dans votre projet.",
                    file: 'config/packages/security.yaml',
                ));
            }
        }
    }

    /**
     * Vérifie si un chemin est couvert par au moins une règle access_control.
     *
     * On regarde si le pattern regex de la règle matche le chemin donné.
     * Les patterns access_control sont des regex (ex: ^/admin).
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

            // Les patterns access_control sont des regex.
            // On les teste avec preg_match.
            // Le "#" est le délimiteur (au lieu du classique "/")
            // pour éviter d'échapper les "/" dans les paths.
            //
            // preg_match retourne 1 si le pattern matche, 0 sinon,
            // et false en cas d'erreur (pattern invalide).
            // Le @ supprime les warnings en cas de regex invalide
            // (on ne veut pas crasher sur une config mal écrite par l'utilisateur).
            if (@preg_match('#' . $pattern . '#', $path) === 1) {
                return true;
            }
        }

        return false;
    }
}