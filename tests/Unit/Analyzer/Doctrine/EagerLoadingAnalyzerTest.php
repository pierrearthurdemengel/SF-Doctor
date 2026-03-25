<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Doctrine;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Doctrine\EagerLoadingAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste la detection des relations Doctrine avec fetch: EAGER sur les collections.
 * L'analyzer scanne src/Entity/ et signale les OneToMany/ManyToMany en EAGER.
 */
final class EagerLoadingAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
        mkdir($this->tempDir . '/src/Entity', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    // --- Cas sans probleme ---

    /**
     * Si le dossier src/Entity n'existe pas, l'analyzer ne fait rien.
     */
    public function testNoEntityDirDoesNothing(): void
    {
        $analyzer = new EagerLoadingAnalyzer($this->tempDir . '/nonexistent');
        $report = new AuditReport('/fake/project', [Module::DOCTRINE]);

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * fetch: LAZY (le defaut Doctrine) ne declenche aucune issue.
     */
    public function testLazyLoadingDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ORM\\Entity]\n"
            . "class Product {\n"
            . "    #[ORM\\OneToMany(targetEntity: Item::class, fetch: 'LAZY')]\n"
            . "    private Collection \$items;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * EAGER sur ManyToOne est acceptable (une seule entite chargee, pas une collection).
     */
    public function testEagerOnManyToOneDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ORM\\Entity]\n"
            . "class OrderItem {\n"
            . "    #[ORM\\ManyToOne(targetEntity: Product::class, fetch: 'EAGER')]\n"
            . "    private Product \$product;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/OrderItem.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    // --- Cas avec probleme ---

    /**
     * fetch: EAGER sur OneToMany charge toute la collection en memoire,
     * l'analyzer doit signaler un CRITICAL.
     */
    public function testEagerOnOneToManyCreatesCritical(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ORM\\Entity]\n"
            . "class Product {\n"
            . "    #[ORM\\OneToMany(targetEntity: Item::class, fetch: 'EAGER')]\n"
            . "    private Collection \$items;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        $issues = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $issues);
        $this->assertSame(Module::DOCTRINE, $issues[0]->getModule());
        $this->assertStringContainsString('EAGER', $issues[0]->getMessage());
        $this->assertStringContainsString('Product.php', $issues[0]->getFile() ?? '');
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue CRITICAL contient les champs d'enrichissement
     * (fixCode, docUrl, businessImpact, estimatedFixMinutes).
     */
    public function testEnrichmentFields(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ORM\\Entity]\n"
            . "class Category {\n"
            . "    #[ORM\\OneToMany(targetEntity: Product::class, fetch: 'EAGER')]\n"
            . "    private Collection \$products;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Category.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(1, $report->getIssues());
        $issue = $report->getIssues()[0];

        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('LAZY', $issue->getFixCode() ?? '');
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('doctrine-project.org', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(10, $issue->getEstimatedFixMinutes());
        $this->assertNotNull($issue->getFile());
        $this->assertNotNull($issue->getLine());
    }

    // --- Helpers ---

    private function runAnalyzer(): AuditReport
    {
        $analyzer = new EagerLoadingAnalyzer($this->tempDir);
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
