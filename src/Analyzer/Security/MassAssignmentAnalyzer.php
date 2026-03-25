<?php

// src/Analyzer/Security/MassAssignmentAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Security;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les risques de mass assignment dans les formulaires Symfony.
 *
 * Deux niveaux d'analyse :
 * 1. Utilisation de $request->request->all() combinee a ->submit() (injection de champs)
 * 2. FormType avec 'allow_extra_fields' => true (champs non declares acceptes)
 */
final class MassAssignmentAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $formDir = $this->projectPath . '/src/Form';

        if (!is_dir($formDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($formDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());

            if ($content === false) {
                continue;
            }

            $realPath = str_replace('\\', '/', $file->getRealPath());
            $normalizedDir = str_replace('\\', '/', $formDir);
            $relativePath = 'src/Form/' . ltrim(
                str_replace($normalizedDir, '', $realPath),
                '/',
            );

            $this->checkMassAssignment($report, $content, $relativePath, $file->getFilename());
            $this->checkAllowExtraFields($report, $content, $relativePath, $file->getFilename());
        }
    }

    public function getName(): string
    {
        return 'Mass Assignment Analyzer';
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function supports(ProjectContext $context): bool
    {
        return is_dir($context->getProjectPath() . '/src/Form');
    }

    /**
     * Detecte l'utilisation de $request->request->all() avec ->submit() dans le meme fichier.
     * Ce pattern injecte tous les champs de la requete dans le formulaire,
     * y compris ceux non declares dans le FormType.
     */
    private function checkMassAssignment(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        $hasRequestAll = str_contains($content, '$request->request->all()');
        $hasSubmit = (bool) preg_match('/->submit\s*\(/', $content);

        if (!$hasRequestAll || !$hasSubmit) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: "Mass assignment detecte dans {$filename}",
            detail: "Le fichier utilise \$request->request->all() avec ->submit(). "
                . "Tous les champs de la requete HTTP sont injectes dans le formulaire, "
                . "y compris des champs non declares dans le FormType. Un attaquant peut "
                . "modifier des proprietes non prevues de l'entite (role, statut, prix...).",
            suggestion: "Utiliser \$form->handleRequest(\$request) au lieu de ->submit(\$request->request->all()). "
                . "handleRequest() ne traite que les champs declares dans le FormType.",
            file: $relativePath,
            fixCode: "// Remplacer dans {$filename} :\n// Avant :\n\$form->submit(\$request->request->all());\n\n// Apres :\n\$form->handleRequest(\$request);",
            docUrl: 'https://symfony.com/doc/current/forms.html#processing-forms',
            businessImpact: 'Un attaquant peut injecter des champs supplementaires dans la requete HTTP '
                . 'pour modifier des proprietes non exposees dans le formulaire '
                . '(ex: role admin, prix, statut de commande).',
            estimatedFixMinutes: 10,
        ));
    }

    /**
     * Detecte les FormType qui acceptent des champs supplementaires non declares.
     * Cela affaiblit la validation du formulaire et elargit la surface d'attaque.
     */
    private function checkAllowExtraFields(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        if (!preg_match('/[\'"]allow_extra_fields[\'"]\s*=>\s*true/', $content)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: "allow_extra_fields active dans {$filename}",
            detail: "Le FormType '{$filename}' accepte des champs non declares via "
                . "'allow_extra_fields' => true. Les donnees supplementaires sont ignorees "
                . "par defaut, mais ce reglage affaiblit la validation et peut masquer "
                . "des tentatives d'injection.",
            suggestion: "Supprimer 'allow_extra_fields' => true ou le remplacer par false. "
                . "Si des champs dynamiques sont necessaires, les declarer explicitement "
                . "dans le FormType via un EventListener.",
            file: $relativePath,
            fixCode: "// Dans {$filename}, supprimer ou corriger :\n\$resolver->setDefaults([\n    'allow_extra_fields' => false,\n]);",
            docUrl: 'https://symfony.com/doc/current/form/dynamic_form_modification.html',
            businessImpact: 'Les champs supplementaires non declares sont silencieusement acceptes. '
                . 'Cela peut masquer des tentatives d\'injection de donnees dans le formulaire.',
            estimatedFixMinutes: 10,
        ));
    }
}
