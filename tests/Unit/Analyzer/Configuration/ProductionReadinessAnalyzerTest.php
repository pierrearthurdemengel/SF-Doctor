<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Configuration;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Configuration\ProductionReadinessAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Tests pour le ProductionReadinessAnalyzer.
 */
class ProductionReadinessAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    public function testComposerLockPresentDoesNothing(): void
    {
        file_put_contents($this->tempDir . '/composer.lock', '{}');
        file_put_contents($this->tempDir . '/config/preload.php', '<?php');
        mkdir($this->tempDir . '/config', 0777, true);
        file_put_contents($this->tempDir . '/config/preload.php', '<?php');

        $analyzer = new ProductionReadinessAnalyzer($this->tempDir);
        $report = new AuditReport($this->tempDir, [Module::SECURITY]);
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(0, $warnings);
    }

    public function testMissingComposerLockCreatesWarning(): void
    {
        // Pas de composer.lock
        mkdir($this->tempDir . '/config', 0777, true);
        file_put_contents($this->tempDir . '/config/preload.php', '<?php');

        $analyzer = new ProductionReadinessAnalyzer($this->tempDir);
        $report = new AuditReport($this->tempDir, [Module::SECURITY]);
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('composer.lock', $warnings[0]->getMessage());
    }

    public function testMissingPreloadCreatesSuggestion(): void
    {
        file_put_contents($this->tempDir . '/composer.lock', '{}');
        // Pas de config/preload.php

        $analyzer = new ProductionReadinessAnalyzer($this->tempDir);
        $report = new AuditReport($this->tempDir, [Module::SECURITY]);
        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(1, $suggestions);
        $this->assertStringContainsString('preload', $suggestions[0]->getMessage());
    }

    public function testEnrichmentFields(): void
    {
        // Pas de composer.lock -> warning avec champs enrichis.
        mkdir($this->tempDir . '/config', 0777, true);
        file_put_contents($this->tempDir . '/config/preload.php', '<?php');

        $analyzer = new ProductionReadinessAnalyzer($this->tempDir);
        $report = new AuditReport($this->tempDir, [Module::SECURITY]);
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
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
