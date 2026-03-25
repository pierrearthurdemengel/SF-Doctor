<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Doctrine;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Doctrine\CascadeRiskAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste la detection des configurations cascade risquees dans les entites Doctrine.
 * cascade: ["all"] et cascade: ["remove"] sans orphanRemoval sont signales.
 */
final class CascadeRiskAnalyzerTest extends TestCase
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
        $analyzer = new CascadeRiskAnalyzer($this->tempDir . '/nonexistent');
        $report = new AuditReport('/fake/project', [Module::DOCTRINE]);

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * cascade: ["persist"] est la bonne pratique, pas de probleme.
     */
    public function testCascadePersistDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ORM\\Entity]\n"
            . "class Order {\n"
            . "    #[ORM\\OneToMany(targetEntity: OrderItem::class, cascade: ['persist'])]\n"
            . "    private Collection \$items;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Order.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * cascade: ["remove"] avec orphanRemoval: true est correct,
     * l'analyzer ne doit pas signaler de probleme.
     */
    public function testCascadeRemoveWithOrphanRemovalDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ORM\\Entity]\n"
            . "class Order {\n"
            . "    #[ORM\\OneToMany(targetEntity: OrderItem::class, cascade: ['remove'], orphanRemoval: true)]\n"
            . "    private Collection \$items;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Order.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    // --- Cas avec probleme ---

    /**
     * cascade: ["all"] inclut persist, remove, merge, detach et refresh.
     * L'analyzer doit signaler un WARNING.
     */
    public function testCascadeAllCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ORM\\Entity]\n"
            . "class User {\n"
            . "    #[ORM\\OneToMany(targetEntity: Article::class, cascade: ['all'])]\n"
            . "    private Collection \$articles;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/User.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertSame(Module::DOCTRINE, $warnings[0]->getModule());
        $this->assertStringContainsString('all', $warnings[0]->getMessage());
        $this->assertStringContainsString('User.php', $warnings[0]->getFile() ?? '');
    }

    /**
     * cascade: ["remove"] sans orphanRemoval laisse des entites orphelines en base.
     * L'analyzer doit signaler une SUGGESTION.
     */
    public function testCascadeRemoveWithoutOrphanCreatesIssue(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ORM\\Entity]\n"
            . "class Invoice {\n"
            . "    #[ORM\\OneToMany(targetEntity: InvoiceLine::class, cascade: ['remove'])]\n"
            . "    private Collection \$lines;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Invoice.php', $content);

        $report = $this->runAnalyzer();

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(1, $suggestions);
        $this->assertStringContainsString('orphanRemoval', $suggestions[0]->getMessage());
        $this->assertStringContainsString('Invoice.php', $suggestions[0]->getFile() ?? '');
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue WARNING (cascade all) contient les champs d'enrichissement.
     */
    public function testEnrichmentFields(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ORM\\Entity]\n"
            . "class Blog {\n"
            . "    #[ORM\\OneToMany(targetEntity: Comment::class, cascade: ['all'])]\n"
            . "    private Collection \$comments;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Blog.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('persist', $issue->getFixCode() ?? '');
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('doctrine-project.org', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(15, $issue->getEstimatedFixMinutes());
        $this->assertNotNull($issue->getFile());
    }

    // --- Helpers ---

    private function runAnalyzer(): AuditReport
    {
        $analyzer = new CascadeRiskAnalyzer($this->tempDir);
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
