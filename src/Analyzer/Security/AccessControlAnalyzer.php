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
    public function __construct(
        private readonly ConfigReaderInterface $configReader,
        private readonly ParameterResolverInterface $parameterResolver,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $security = $this->configReader->read('config/packages/security.yaml');

        if ($security === null) {
            return;
        }

        $security = $this->parameterResolver->resolveArray($security);

        $accessControl = $security['security']['access_control'] ?? [];

        if (empty($accessControl)) {
            return;
        }

        foreach ($accessControl as $index => $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $this->checkDeprecatedRoles($report, $rule, $index);
        }

        $this->checkCatchAllOrder($report, $accessControl);
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

    /**
     * @param array<mixed> $rule
     */
    private function checkDeprecatedRoles(AuditReport $report, array $rule, int $index): void
    {
        if (!isset($rule['roles'])) {
            return;
        }

        $roles = is_array($rule['roles']) ? $rule['roles'] : [$rule['roles']];
        $path = $rule['path'] ?? '(non défini)';

        foreach ($roles as $role) {
            if (!is_string($role)) {
                continue;
            }

            if ($role === 'IS_AUTHENTICATED_ANONYMOUSLY') {
                $report->addIssue(new Issue(
                    severity: Severity::WARNING,
                    module: Module::SECURITY,
                    analyzer: $this->getName(),
                    message: "Rôle déprécié 'IS_AUTHENTICATED_ANONYMOUSLY' (règle #{$index}, path: {$path})",
                    detail: "Ce rôle est déprécié depuis Symfony 5.4 et sera supprimé dans une future version.",
                    suggestion: "Remplacer par PUBLIC_ACCESS.",
                    file: 'config/packages/security.yaml',
                    fixCode: "# Remplacer dans security.yaml :\n- { path: {$path}, roles: PUBLIC_ACCESS }",
                    docUrl: 'https://symfony.com/doc/current/security/access_control.html#access-control-public-access',
                    businessImpact: 'Aucun impact immédiat, mais ce rôle sera supprimé dans une future version de Symfony. '
                        . 'La mise à jour vers Symfony 7+ cassera cette configuration.',
                    estimatedFixMinutes: 5,
                ));
            }

            if ($role === 'ROLE_ANONYMOUS') {
                $report->addIssue(new Issue(
                    severity: Severity::WARNING,
                    module: Module::SECURITY,
                    analyzer: $this->getName(),
                    message: "Rôle supprimé 'ROLE_ANONYMOUS' (règle #{$index}, path: {$path})",
                    detail: "Ce rôle n'existe plus depuis Symfony 6.0.",
                    suggestion: "Remplacer par PUBLIC_ACCESS ou IS_AUTHENTICATED.",
                    file: 'config/packages/security.yaml',
                    fixCode: "# Remplacer dans security.yaml :\n- { path: {$path}, roles: PUBLIC_ACCESS }",
                    docUrl: 'https://symfony.com/doc/current/security/access_control.html#access-control-public-access',
                    businessImpact: 'Cette règle ne fonctionne plus depuis Symfony 6.0. '
                        . 'Le comportement de sécurité de ce chemin est indéfini.',
                    estimatedFixMinutes: 5,
                ));
            }
        }
    }

    /**
     * @param list<mixed> $accessControl
     */
    private function checkCatchAllOrder(AuditReport $report, array $accessControl): void
    {
        $totalRules = count($accessControl);

        if ($totalRules < 2) {
            return;
        }

        foreach ($accessControl as $index => $rule) {
            if (!is_array($rule) || !isset($rule['path'])) {
                continue;
            }

            $path = $rule['path'];
            $isCatchAll = ($path === '^/' || $path === '^.*' || $path === '/');

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
                    fixCode: "# Déplacer la règle '{$path}' en dernière position :\nsecurity:\n    access_control:\n        # ... règles spécifiques d'abord ...\n        - { path: {$path}, roles: ROLE_USER }",
                    docUrl: 'https://symfony.com/doc/current/security/access_control.html#matching-order',
                    businessImpact: 'Les règles de sécurité placées après la règle attrape-tout ne sont jamais évaluées. '
                        . 'Des routes censées être restreintes à ROLE_ADMIN peuvent être accessibles à tout utilisateur connecté.',
                    estimatedFixMinutes: 10,
                ));
            }
        }
    }

    /**
     * @param list<mixed> $accessControl
     */
    private function checkSensitivePaths(AuditReport $report, array $accessControl): void
    {
        if ($this->hasUnresolvedParameters($accessControl)) {
            return;
        }

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
                    fixCode: "security:\n    access_control:\n        - { path: ^{$path}, roles: ROLE_ADMIN }",
                    docUrl: 'https://symfony.com/doc/current/security/access_control.html',
                    businessImpact: "Si le chemin {$path} existe et n'est pas protégé, "
                        . "n'importe quel utilisateur peut y accéder sans restriction.",
                    estimatedFixMinutes: 10,
                ));
            }
        }
    }

    /**
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
     * @param list<mixed> $accessControl
     */
    private function hasUnresolvedParameters(array $accessControl): bool
    {
        foreach ($accessControl as $rule) {
            if (!is_array($rule) || !isset($rule['path'])) {
                continue;
            }

            if (preg_match('/%[^%]+%/', (string) $rule['path'])) {
                return true;
            }
        }

        return false;
    }
}