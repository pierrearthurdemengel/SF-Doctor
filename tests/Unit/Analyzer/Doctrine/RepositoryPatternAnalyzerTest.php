<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Doctrine;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Doctrine\RepositoryPatternAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste la detection des appels createQueryBuilder()/createQuery()
 * en dehors des repositories Doctrine. La logique de requetes
 * doit etre centralisee dans les repositories.
 */
final class RepositoryPatternAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
        // On cree src/ avec plusieurs sous-dossiers pour simuler un projet reel.
        mkdir($this->tempDir . '/src/Service', 0777, true);
        mkdir($this->tempDir . '/src/Repository', 0777, true);
        mkdir($this->tempDir . '/src/Command', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    // --- Cas sans probleme ---

    /**
     * Si le dossier src/ n'existe pas, l'analyzer ne fait rien.
     */
    public function testNoSrcDirDoesNothing(): void
    {
        $analyzer = new RepositoryPatternAnalyzer($this->tempDir . '/nonexistent');
        $report = new AuditReport('/fake/project', [Module::DOCTRINE]);

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * createQueryBuilder() dans src/Repository/ est le bon endroit,
     * l'analyzer ne doit pas signaler de probleme.
     */
    public function testQueryBuilderInRepositoryDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Repository;\n\n"
            . "class ProductRepository\n"
            . "{\n"
            . "    public function findActive(): array\n"
            . "    {\n"
            . "        return \$this->createQueryBuilder('p')\n"
            . "            ->where('p.active = true')\n"
            . "            ->getQuery()\n"
            . "            ->getResult();\n"
            . "    }\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Repository/ProductRepository.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * createQueryBuilder() dans src/Command/ est un repertoire autorise
     * (ALLOWED_DIRS contient 'Command'), donc pas de signalement.
     */
    public function testQueryBuilderInCommandDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Command;\n\n"
            . "class ImportCommand\n"
            . "{\n"
            . "    public function execute(): void\n"
            . "    {\n"
            . "        \$qb = \$this->em->createQueryBuilder('p');\n"
            . "    }\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Command/ImportCommand.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    // --- Cas avec probleme ---

    /**
     * createQueryBuilder() dans src/Service/ viole le pattern repository.
     * L'analyzer doit signaler un CRITICAL.
     */
    public function testQueryBuilderInServiceCreatesCritical(): void
    {
        $content = "<?php\nnamespace App\\Service;\n\n"
            . "class ProductService\n"
            . "{\n"
            . "    public function findExpired(): array\n"
            . "    {\n"
            . "        return \$this->em->createQueryBuilder('p')\n"
            . "            ->where('p.expiresAt < :now')\n"
            . "            ->getQuery()\n"
            . "            ->getResult();\n"
            . "    }\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Service/ProductService.php', $content);

        $report = $this->runAnalyzer();

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertSame(Module::DOCTRINE, $criticals[0]->getModule());
        $this->assertStringContainsString('hors repository', $criticals[0]->getMessage());
        $this->assertStringContainsString('ProductService.php', $criticals[0]->getFile() ?? '');
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue CRITICAL contient les champs d'enrichissement.
     */
    public function testEnrichmentFields(): void
    {
        $content = "<?php\nnamespace App\\Service;\n\n"
            . "class ReportService\n"
            . "{\n"
            . "    public function generate(): array\n"
            . "    {\n"
            . "        return \$this->em->createQueryBuilder('r')\n"
            . "            ->getQuery()\n"
            . "            ->getResult();\n"
            . "    }\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Service/ReportService.php', $content);

        $report = $this->runAnalyzer();

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('Repository', $issue->getFixCode() ?? '');
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('symfony.com', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(20, $issue->getEstimatedFixMinutes());
        $this->assertNotNull($issue->getFile());
    }

    // --- Helpers ---

    private function runAnalyzer(): AuditReport
    {
        $analyzer = new RepositoryPatternAnalyzer($this->tempDir);
        $report = new AuditReport('/fake/project', [Module::DOCTRINE]);
        $analyzer->analyze($report);

        return $report;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
