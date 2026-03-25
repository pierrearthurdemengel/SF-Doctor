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
 * Detecte les relations Doctrine avec fetch: EAGER sur les collections.
 * EAGER sur OneToMany/ManyToMany charge toute la collection en memoire
 * a chaque acces a l'entite parente.
 */
class EagerLoadingAnalyzer implements AnalyzerInterface
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
            $lines = explode("\n", $content);

            foreach ($lines as $lineNumber => $line) {
                // Detecte fetch: "EAGER" ou fetch: EAGER dans les annotations/attributs.
                if (preg_match('/fetch\s*[:=]\s*["\']?EAGER["\']?/i', $line)
                    && preg_match('/(OneToMany|ManyToMany)/i', $this->getContextLines($lines, $lineNumber))) {
                    $report->addIssue(new Issue(
                        severity: Severity::CRITICAL,
                        module: Module::DOCTRINE,
                        analyzer: $this->getName(),
                        message: sprintf('Relation avec fetch EAGER detectee dans %s', $relativePath),
                        detail: 'fetch: EAGER sur une collection OneToMany ou ManyToMany charge toutes les entites liees a chaque acces. Sur une collection volumineuse, cela sature la memoire.',
                        suggestion: 'Utiliser fetch: LAZY (defaut) et charger explicitement via un JOIN FETCH dans le repository.',
                        file: $relativePath,
                        line: $lineNumber + 1,
                        businessImpact: 'Degradation des performances proportionnelle au volume de donnees. Risque de timeout sur les pages listant des entites.',
                        fixCode: "fetch: 'LAZY'",
                        docUrl: 'https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/annotations-reference.html#onetomany',
                        estimatedFixMinutes: 10,
                    ));
                }
            }
        }
    }

    public function getName(): string
    {
        return 'Eager Loading Analyzer';
    }

    public function getModule(): Module
    {
        return Module::DOCTRINE;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasDoctrineOrm();
    }

    /**
     * Retourne les lignes environnantes pour le contexte d'une annotation.
     *
     * @param list<string> $lines
     */
    private function getContextLines(array $lines, int $currentLine): string
    {
        $start = max(0, $currentLine - 5);
        $end = min(count($lines) - 1, $currentLine + 2);

        return implode("\n", array_slice($lines, $start, $end - $start + 1));
    }
}
