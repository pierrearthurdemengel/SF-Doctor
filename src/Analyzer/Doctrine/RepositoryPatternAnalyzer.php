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
 * Detecte les appels a createQueryBuilder() et createQuery() en dehors
 * des repositories Doctrine. La logique de requetes doit etre centralisee
 * dans les repositories pour la maintenabilite et la testabilite.
 */
class RepositoryPatternAnalyzer implements AnalyzerInterface
{
    // Repertoires autorises pour les requetes Doctrine.
    private const ALLOWED_DIRS = ['Repository', 'Command', 'DataFixtures'];

    public function __construct(private readonly string $projectPath)
    {
    }

    public function analyze(AuditReport $report): void
    {
        $srcDir = $this->projectPath . '/src';
        if (!is_dir($srcDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $realPath = $file->getRealPath();
            if ($realPath === false) {
                continue;
            }

            // Ignorer les repertoires autorises.
            $isAllowed = false;
            foreach (self::ALLOWED_DIRS as $dir) {
                if (str_contains($realPath, DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR)
                    || str_contains($realPath, '/' . $dir . '/')) {
                    $isAllowed = true;
                    break;
                }
            }
            if ($isAllowed) {
                continue;
            }

            $content = file_get_contents($realPath);
            if ($content === false) {
                continue;
            }

            // Chemin relatif depuis la racine du projet.
            $relativePath = str_replace(
                [$this->projectPath . DIRECTORY_SEPARATOR, $this->projectPath . '/'],
                '',
                $realPath,
            );

            // Detecte createQueryBuilder() ou createQuery() hors repositories.
            if (preg_match('/->createQueryBuilder\s*\(|->createQuery\s*\(/', $content)) {
                $report->addIssue(new Issue(
                    severity: Severity::CRITICAL,
                    module: Module::DOCTRINE,
                    analyzer: $this->getName(),
                    message: sprintf('Requete Doctrine hors repository dans %s', $relativePath),
                    detail: 'createQueryBuilder() ou createQuery() utilise en dehors d\'un repository. La logique de requete doit etre centralisee dans les repositories pour la maintenabilite.',
                    suggestion: 'Deplacer la requete dans le repository de l\'entite correspondante.',
                    file: $relativePath,
                    businessImpact: 'Code duplique, difficilement testable et maintenable. Les changements de schema cassent plusieurs fichiers au lieu d\'un seul.',
                    fixCode: "// Deplacer dans src/Repository/EntityRepository.php\npublic function findByCustomCriteria(): array\n{\n    return \$this->createQueryBuilder('e')\n        ->where(...)\n        ->getQuery()\n        ->getResult();\n}",
                    docUrl: 'https://symfony.com/doc/current/doctrine.html#querying-for-objects-the-repository',
                    estimatedFixMinutes: 20,
                ));
            }
        }
    }

    public function getName(): string
    {
        return 'Repository Pattern Analyzer';
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
