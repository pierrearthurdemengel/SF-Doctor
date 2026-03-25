<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Doctrine;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les configurations cascade risquees dans les entites Doctrine.
 * cascade: ["all"] ou cascade: ["remove"] sans orphanRemoval sont des sources
 * frequentes de suppression non intentionnelle de donnees.
 */
class CascadeRiskAnalyzer implements AnalyzerInterface
{
    public function __construct(private readonly string $projectPath)
    {
    }

    public function analyze(AuditReport $report): void
    {
        $entityDir = $this->projectPath . '/src/Entity';
        if (!is_dir($entityDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($entityDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                continue;
            }

            $relativePath = 'src/Entity/' . $file->getFilename();

            // Check 1 : cascade: ["all"]
            if (preg_match('/cascade\s*[:=]\s*\[?\s*["\']all["\']/i', $content)) {
                $report->addIssue(new Issue(
                    severity: Severity::WARNING,
                    module: Module::DOCTRINE,
                    analyzer: $this->getName(),
                    message: sprintf('cascade: "all" detecte dans %s', $relativePath),
                    detail: 'cascade: "all" inclut persist, remove, merge, detach et refresh. La cascade remove peut supprimer des entites liees de facon non intentionnelle.',
                    suggestion: 'Specifier explicitement les operations necessaires : cascade: ["persist"] ou cascade: ["persist", "remove"].',
                    file: $relativePath,
                    businessImpact: 'Suppression accidentelle de donnees en cascade lors de la suppression d\'une entite parente.',
                    fixCode: "cascade: ['persist']",
                    docUrl: 'https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/working-with-associations.html#transitive-persistence-cascade-operations',
                    estimatedFixMinutes: 15,
                ));
            }

            // Check 2 : cascade: ["remove"] sans orphanRemoval.
            if (preg_match('/cascade\s*[:=]\s*\[?[^]]*["\']remove["\']/i', $content)
                && !preg_match('/orphanRemoval\s*[:=]\s*true/i', $content)) {
                $report->addIssue(new Issue(
                    severity: Severity::SUGGESTION,
                    module: Module::DOCTRINE,
                    analyzer: $this->getName(),
                    message: sprintf('cascade: "remove" sans orphanRemoval dans %s', $relativePath),
                    detail: 'cascade: "remove" supprime les entites liees quand le parent est supprime. Mais sans orphanRemoval, les entites detachees de la collection restent en base.',
                    suggestion: 'Ajouter orphanRemoval: true si les entites enfants ne doivent pas exister sans parent.',
                    file: $relativePath,
                    businessImpact: 'Donnees orphelines en base de donnees qui ne sont jamais nettoyees.',
                    fixCode: "orphanRemoval: true",
                    docUrl: 'https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/working-with-associations.html#orphan-removal',
                    estimatedFixMinutes: 10,
                ));
            }
        }
    }

    public function getName(): string
    {
        return 'Cascade Risk Analyzer';
    }

    public function getModule(): Module
    {
        return Module::DOCTRINE;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasDoctrineOrm();
    }
}
