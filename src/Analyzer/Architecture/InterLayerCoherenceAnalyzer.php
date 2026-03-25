<?php

// src/Analyzer/Architecture/InterLayerCoherenceAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Architecture;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Verifie la coherence entre les couches du projet Symfony.
 *
 * Deux verifications :
 * 1. Entite avec #[ApiResource] sans Voter dans src/Security/ (CRITICAL)
 *    Une API exposee sans controle d'acces fin est une faille majeure.
 * 2. FormType mappant une entite sans #[Assert\*] sur les champs requis (WARNING)
 *    Un formulaire sans validation cote entite repose uniquement sur la validation HTML.
 */
final class InterLayerCoherenceAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $entityDir = $this->projectPath . '/src/Entity';

        if (!is_dir($entityDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($entityDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());

            if ($content === false) {
                continue;
            }

            $relativePath = 'src/Entity/' . ltrim(
                str_replace($entityDir, '', $file->getRealPath()),
                '/\\',
            );

            $this->checkApiResourceWithoutVoter($report, $content, $relativePath, $file->getFilename());
            $this->checkFormTypeWithoutValidation($report, $content, $relativePath, $file->getFilename());
        }
    }

    public function getName(): string
    {
        return 'Inter-Layer Coherence Analyzer';
    }

    public function getModule(): Module
    {
        return Module::ARCHITECTURE;
    }

    public function supports(ProjectContext $context): bool
    {
        return true;
    }

    /**
     * Detecte les entites exposees via #[ApiResource] sans Voter associe
     * dans src/Security/.
     *
     * Une entite exposee via API sans Voter signifie que le controle d'acces
     * repose uniquement sur les roles globaux (access_control dans security.yaml),
     * sans verification fine de la propriete des ressources.
     */
    private function checkApiResourceWithoutVoter(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // L'entite doit etre exposee via ApiResource.
        if (!str_contains($content, '#[ApiResource') && !str_contains($content, '@ApiResource')) {
            return;
        }

        // Extraire le nom de la classe de l'entite.
        $entityName = str_replace('.php', '', $filename);

        // Chercher un Voter correspondant dans src/Security/.
        $securityDir = $this->projectPath . '/src/Security';
        $hasVoter = false;

        if (is_dir($securityDir)) {
            $securityIterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($securityDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($securityIterator as $secFile) {
                if (!$secFile instanceof \SplFileInfo || $secFile->getExtension() !== 'php') {
                    continue;
                }

                $secContent = file_get_contents($secFile->getRealPath());

                if ($secContent === false) {
                    continue;
                }

                // Verifier si le Voter reference l'entite (par nom de classe ou FQCN).
                if (str_contains($secContent, $entityName) && str_contains($secContent, 'Voter')) {
                    $hasVoter = true;
                    break;
                }
            }
        }

        if ($hasVoter) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::ARCHITECTURE,
            analyzer: $this->getName(),
            message: "Entite API {$entityName} sans Voter de securite",
            detail: "L'entite '{$entityName}' est exposee via #[ApiResource] mais aucun Voter "
                . "dans src/Security/ ne reference cette entite. Le controle d'acces repose "
                . "uniquement sur les roles globaux, sans verification fine de la propriete "
                . "des ressources (ex: un utilisateur peut acceder aux donnees d'un autre).",
            suggestion: "Creer un Voter dedie dans src/Security/Voter/{$entityName}Voter.php "
                . "pour controler l'acces aux ressources de cette entite.",
            file: $relativePath,
            fixCode: "// Creer src/Security/Voter/{$entityName}Voter.php :\n"
                . "namespace App\\Security\\Voter;\n\n"
                . "use App\\Entity\\{$entityName};\n"
                . "use Symfony\\Component\\Security\\Core\\Authentication\\Token\\TokenInterface;\n"
                . "use Symfony\\Component\\Security\\Core\\Authorization\\Voter\\Voter;\n\n"
                . "class {$entityName}Voter extends Voter\n"
                . "{\n"
                . "    protected function supports(string \$attribute, mixed \$subject): bool\n"
                . "    {\n"
                . "        return \$subject instanceof {$entityName};\n"
                . "    }\n\n"
                . "    protected function voteOnAttribute(string \$attribute, mixed \$subject, TokenInterface \$token): bool\n"
                . "    {\n"
                . "        // Implementer la logique d'autorisation\n"
                . "        return false;\n"
                . "    }\n"
                . "}",
            docUrl: 'https://symfony.com/doc/current/security/voters.html',
            businessImpact: 'Sans Voter, l\'API expose les ressources a tous les utilisateurs authentifies. '
                . 'Un utilisateur peut lire, modifier ou supprimer les donnees d\'un autre '
                . 'utilisateur (vulnerabilite IDOR).',
            estimatedFixMinutes: 30,
        ));
    }

    /**
     * Detecte les FormType mappant une entite qui n'utilise aucune contrainte
     * de validation (#[Assert\*]).
     *
     * Une entite sans validation cote serveur repose uniquement sur la validation
     * HTML du formulaire, facilement contournable.
     */
    private function checkFormTypeWithoutValidation(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // Verifier si l'entite est utilisee dans un FormType.
        $entityName = str_replace('.php', '', $filename);
        $formDir = $this->projectPath . '/src/Form';

        if (!is_dir($formDir)) {
            return;
        }

        $isMappedInForm = false;
        $formIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($formDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($formIterator as $formFile) {
            if (!$formFile instanceof \SplFileInfo || $formFile->getExtension() !== 'php') {
                continue;
            }

            $formContent = file_get_contents($formFile->getRealPath());

            if ($formContent === false) {
                continue;
            }

            // Le FormType reference l'entite via data_class.
            if (str_contains($formContent, $entityName . '::class') || str_contains($formContent, "'{$entityName}'")) {
                $isMappedInForm = true;
                break;
            }
        }

        if (!$isMappedInForm) {
            return;
        }

        // Verifier si l'entite a des contraintes de validation.
        $hasAssertions = str_contains($content, '#[Assert\\') || str_contains($content, '@Assert\\');

        if ($hasAssertions) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::ARCHITECTURE,
            analyzer: $this->getName(),
            message: "Entite {$entityName} mappee dans un FormType sans validation",
            detail: "L'entite '{$entityName}' est utilisee comme data_class dans un FormType "
                . "mais ne contient aucune contrainte de validation (#[Assert\\*]). "
                . "La validation repose uniquement sur le navigateur (champs required, maxlength), "
                . "facilement contournable via les outils de developpement ou un client HTTP.",
            suggestion: "Ajouter des contraintes de validation sur les proprietes de l'entite "
                . "avec les attributs #[Assert\\NotBlank], #[Assert\\Length], etc.",
            file: $relativePath,
            fixCode: "// Ajouter des contraintes dans {$filename} :\n"
                . "use Symfony\\Component\\Validator\\Constraints as Assert;\n\n"
                . "#[ORM\\Column(length: 255)]\n"
                . "#[Assert\\NotBlank]\n"
                . "#[Assert\\Length(max: 255)]\n"
                . "private string \$name;",
            docUrl: 'https://symfony.com/doc/current/validation.html',
            businessImpact: 'Sans validation cote serveur, un attaquant peut envoyer des donnees '
                . 'invalides ou malveillantes directement via un client HTTP, '
                . 'contournant la validation du navigateur.',
            estimatedFixMinutes: 20,
        ));
    }
}
