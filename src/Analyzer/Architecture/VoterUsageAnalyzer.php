<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Architecture;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les verifications de roles manuelles dans les controllers.
 *
 * Symfony fournit le systeme de Voters pour centraliser la logique d'autorisation.
 * Les verifications manuelles avec in_array() ou hasRole() contournent ce systeme
 * et dispersent la logique de securite dans tout le code.
 */
final class VoterUsageAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $controllerDir = $this->projectPath . '/src/Controller';

        if (!is_dir($controllerDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($controllerDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->isDir()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());

            if ($content === false) {
                continue;
            }

            $realControllerDir = realpath($controllerDir);
            if ($realControllerDir === false) {
                continue;
            }

            $relativePath = 'src/Controller/' . ltrim(
                str_replace('\\', '/', substr($file->getRealPath(), strlen($realControllerDir))),
                '/',
            );

            $this->checkManualRoleCheck($report, $content, $file->getFilename(), $relativePath);
            $this->checkHardcodedRoleGrant($report, $content, $file->getFilename(), $relativePath);
        }
    }

    public function getModule(): Module
    {
        return Module::ARCHITECTURE;
    }

    public function getName(): string
    {
        return 'Voter Usage Analyzer';
    }

    public function supports(ProjectContext $context): bool
    {
        $controllerDir = $context->getProjectPath() . '/src/Controller';

        return is_dir($controllerDir);
    }

    /**
     * Detecte les verifications manuelles de roles avec in_array() ou hasRole().
     * Ces patterns contournent le systeme de Voters de Symfony.
     */
    private function checkManualRoleCheck(
        AuditReport $report,
        string $content,
        string $filename,
        string $relativePath,
    ): void {
        $hasInArrayRole = preg_match('/in_array\s*\(.*ROLE_/s', $content);
        $hasHasRole = str_contains($content, '->hasRole(');

        if (!$hasInArrayRole && !$hasHasRole) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::ARCHITECTURE,
            analyzer: $this->getName(),
            message: "Verification manuelle de role dans {$filename}",
            detail: "Le fichier '{$filename}' verifie les roles manuellement avec in_array() ou hasRole(). "
                . "Ce pattern contourne le systeme de Voters de Symfony et disperse "
                . "la logique d'autorisation dans les controllers.",
            suggestion: "Creer un Voter dedie et utiliser \$this->isGranted() ou "
                . "l'attribut #[IsGranted] pour centraliser la logique d'autorisation.",
            file: $relativePath,
            fixCode: "// Avant (verification manuelle) :\nif (in_array('ROLE_ADMIN', \$user->getRoles())) { ... }\n\n// Apres (Voter Symfony) :\n// 1. Creer src/Security/Voter/AdminVoter.php\n// 2. Utiliser dans le controller :\n#[IsGranted('ADMIN_ACCESS')]\npublic function adminDashboard(): Response\n{\n    // ...\n}",
            docUrl: 'https://symfony.com/doc/current/security/voters.html',
            businessImpact: 'La logique d\'autorisation dispersee dans les controllers est difficile '
                . 'a auditer. Lors d\'un changement de politique d\'acces, il faut retrouver '
                . 'et modifier chaque verification manuellement.',
            estimatedFixMinutes: 30,
        ));
    }

    /**
     * Detecte l'utilisation de denyAccessUnlessGranted avec des roles en dur.
     * Les roles devraient etre centralises dans des constantes.
     */
    private function checkHardcodedRoleGrant(
        AuditReport $report,
        string $content,
        string $filename,
        string $relativePath,
    ): void {
        if (!preg_match('/denyAccessUnlessGranted\s*\(\s*[\'"]ROLE_/', $content)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::SUGGESTION,
            module: Module::ARCHITECTURE,
            analyzer: $this->getName(),
            message: "Role en dur dans denyAccessUnlessGranted() dans {$filename}",
            detail: "Le fichier '{$filename}' utilise denyAccessUnlessGranted() avec une chaine "
                . "de role codee en dur. Si le nom du role change, il faut le modifier partout.",
            suggestion: "Centraliser les noms de roles dans des constantes ou un enum PHP. "
                . "Utiliser ces constantes dans denyAccessUnlessGranted() et dans security.yaml.",
            file: $relativePath,
            fixCode: "// Creer un enum pour les roles :\nenum Role: string\n{\n    case ADMIN = 'ROLE_ADMIN';\n    case USER = 'ROLE_USER';\n    case MANAGER = 'ROLE_MANAGER';\n}\n\n// Utiliser dans le controller :\n\$this->denyAccessUnlessGranted(Role::ADMIN->value);",
            docUrl: 'https://symfony.com/doc/current/security.html#roles',
            businessImpact: 'Les roles en dur sont une source d\'erreurs typographiques silencieuses. '
                . 'Un role mal orthographie (ROLE_ADMNI au lieu de ROLE_ADMIN) '
                . 'ne provoque aucune erreur mais ouvre une faille de securite.',
            estimatedFixMinutes: 15,
        ));
    }
}
