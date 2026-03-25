<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Doctrine;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Doctrine\MissingIndexAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste la detection des champs utilises dans des requetes (findBy, orderBy)
 * sans index Doctrine declare. L'analyzer scanne src/Repository/.
 */
final class MissingIndexAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
        mkdir($this->tempDir . '/src/Repository', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    // --- Cas sans probleme ---

    /**
     * Si le dossier src/Repository n'existe pas, l'analyzer ne fait rien.
     */
    public function testNoRepoDirDoesNothing(): void
    {
        $analyzer = new MissingIndexAnalyzer($this->tempDir . '/nonexistent');
        $report = new AuditReport('/fake/project', [Module::DOCTRINE]);

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * findBy(['id' => ...]) est exclu car id est toujours indexe (cle primaire).
     */
    public function testIdFieldIsExcluded(): void
    {
        $content = "<?php\nnamespace App\\Repository;\n\n"
            . "use Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository;\n\n"
            . "class UserRepository extends ServiceEntityRepository\n"
            . "{\n"
            . "    public function findActiveUser(int \$userId): ?User\n"
            . "    {\n"
            . "        return \$this->findBy(['id' => \$userId]);\n"
            . "    }\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Repository/UserRepository.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    // --- Cas avec probleme ---

    /**
     * findBy(['status' => ...]) utilise le champ status qui n'est pas id,
     * l'analyzer doit signaler un WARNING pour index potentiellement manquant.
     */
    public function testFindByFieldCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Repository;\n\n"
            . "use Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository;\n\n"
            . "class OrderRepository extends ServiceEntityRepository\n"
            . "{\n"
            . "    public function findPending(): array\n"
            . "    {\n"
            . "        return \$this->findBy(['status' => 'pending']);\n"
            . "    }\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Repository/OrderRepository.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertSame(Module::DOCTRINE, $warnings[0]->getModule());
        $this->assertStringContainsString('status', $warnings[0]->getMessage());
        $this->assertStringContainsString('OrderRepository.php', $warnings[0]->getFile() ?? '');
    }

    /**
     * orderBy('o.createdAt') utilise le champ createdAt dans un tri,
     * l'analyzer doit signaler un WARNING.
     */
    public function testOrderByFieldCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Repository;\n\n"
            . "use Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository;\n\n"
            . "class ProductRepository extends ServiceEntityRepository\n"
            . "{\n"
            . "    public function findLatest(): array\n"
            . "    {\n"
            . "        return \$this->createQueryBuilder('p')\n"
            . "            ->orderBy('p.createdAt', 'DESC')\n"
            . "            ->getQuery()\n"
            . "            ->getResult();\n"
            . "    }\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Repository/ProductRepository.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('createdAt', $warnings[0]->getMessage());
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue WARNING contient les champs d'enrichissement.
     */
    public function testEnrichmentFields(): void
    {
        $content = "<?php\nnamespace App\\Repository;\n\n"
            . "use Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository;\n\n"
            . "class CustomerRepository extends ServiceEntityRepository\n"
            . "{\n"
            . "    public function findByEmail(string \$email): array\n"
            . "    {\n"
            . "        return \$this->findBy(['email' => \$email]);\n"
            . "    }\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Repository/CustomerRepository.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(1, $report->getIssues());
        $issue = $report->getIssues()[0];

        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('Index', $issue->getFixCode() ?? '');
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('doctrine-project.org', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(15, $issue->getEstimatedFixMinutes());
        $this->assertNotNull($issue->getFile());
    }

    // --- Helpers ---

    private function runAnalyzer(): AuditReport
    {
        $analyzer = new MissingIndexAnalyzer($this->tempDir);
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
