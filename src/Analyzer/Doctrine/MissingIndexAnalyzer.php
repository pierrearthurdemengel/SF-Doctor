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
 * Detecte les champs utilises dans des requetes (findBy, orderBy, where)
 * qui n'ont pas d'index Doctrine declare.
 */
class MissingIndexAnalyzer implements AnalyzerInterface
{
    public function __construct(private readonly string $projectPath)
    {
    }

    public function analyze(AuditReport $report): void
    {
        $repoDir = $this->projectPath . '/src/Repository';
        if (!is_dir($repoDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($repoDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                continue;
            }

            $relativePath = 'src/Repository/' . $file->getFilename();

            // Detecte les champs utilises dans findBy(), findOneBy(), orderBy().
            $fieldsUsed = [];

            // Pattern findBy(['field' => ...])
            if (preg_match_all('/findBy\s*\(\s*\[\s*[\'"](\w+)[\'"]/i', $content, $matches)) {
                $fieldsUsed = array_merge($fieldsUsed, $matches[1]);
            }

            // Pattern findOneBy(['field' => ...])
            if (preg_match_all('/findOneBy\s*\(\s*\[\s*[\'"](\w+)[\'"]/i', $content, $matches)) {
                $fieldsUsed = array_merge($fieldsUsed, $matches[1]);
            }

            // Pattern ->orderBy('alias.field')
            if (preg_match_all('/orderBy\s*\(\s*[\'"][a-z]+\.(\w+)[\'"]/i', $content, $matches)) {
                $fieldsUsed = array_merge($fieldsUsed, $matches[1]);
            }

            // Pattern ->where('alias.field = ...')
            if (preg_match_all('/where\s*\(\s*[\'"][a-z]+\.(\w+)\s*(=|>|<|LIKE|IN)/i', $content, $matches)) {
                $fieldsUsed = array_merge($fieldsUsed, $matches[1]);
            }

            $fieldsUsed = array_unique($fieldsUsed);
            // Exclure les champs standards (id est toujours indexe).
            $fieldsUsed = array_filter($fieldsUsed, fn (string $f): bool => $f !== 'id');

            if (count($fieldsUsed) > 0) {
                $report->addIssue(new Issue(
                    severity: Severity::WARNING,
                    module: Module::DOCTRINE,
                    analyzer: $this->getName(),
                    message: sprintf('Champs utilises dans des requetes sans index verifie dans %s : %s', $relativePath, implode(', ', $fieldsUsed)),
                    detail: 'Les champs utilises dans findBy(), orderBy() ou where() beneficient d\'un index pour eviter un full table scan.',
                    suggestion: 'Ajouter #[ORM\\Index(columns: ["field"])] sur l\'entite correspondante ou verifier que l\'index existe.',
                    file: $relativePath,
                    businessImpact: 'Les requetes sans index deviennent lentes proportionnellement au volume de donnees. Degradation progressive des performances.',
                    fixCode: "#[ORM\\Index(columns: ['" . ($fieldsUsed[0] ?? 'field') . "'])]",
                    docUrl: 'https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/attributes-reference.html#index',
                    estimatedFixMinutes: 15,
                ));
            }
        }
    }

    public function getName(): string
    {
        return 'Missing Index Analyzer';
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
