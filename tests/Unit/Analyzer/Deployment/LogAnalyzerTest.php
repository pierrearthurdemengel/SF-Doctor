<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Deployment;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Deployment\LogAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class LogAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_log_' . uniqid();
        mkdir($this->tempDir . '/var/log', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::DEPLOYMENT]);
    }

    // --- Test 1 : Pas de dossier var/log => OK ---

    public function testNoLogDirDoesNothing(): void
    {
        $analyzer = new LogAnalyzer($this->tempDir . '/nonexistent');
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 2 : Erreurs recurrentes dans prod.log => CRITICAL ---

    public function testRecurrentErrorsCreatesCritical(): void
    {
        $lines = [];
        for ($i = 0; $i < 15; $i++) {
            $lines[] = '[2026-03-01] app.CRITICAL: Uncaught PHP Exception: Table "orders" not found [context]';
        }
        file_put_contents($this->tempDir . '/var/log/prod.log', implode("\n", $lines));

        $analyzer = new LogAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('recurrente', $criticals[0]->getMessage());
    }

    // --- Test 3 : Peu d'erreurs => OK ---

    public function testFewErrorsNoIssue(): void
    {
        $lines = [];
        for ($i = 0; $i < 3; $i++) {
            $lines[] = '[2026-03-01] app.ERROR: Something went wrong [context]';
        }
        file_put_contents($this->tempDir . '/var/log/prod.log', implode("\n", $lines));

        $analyzer = new LogAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(0, $criticals);
    }

    // --- Test 4 : Deprecations dans dev.log => WARNING ---

    public function testDeprecationsCreatesWarning(): void
    {
        $lines = [];
        for ($i = 0; $i < 150; $i++) {
            $lines[] = '[2026-03-01] php.DEPRECATION: User Deprecated: Method getSomething is deprecated [context]';
        }
        file_put_contents($this->tempDir . '/var/log/dev.log', implode("\n", $lines));

        $analyzer = new LogAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('deprecation', $warnings[0]->getMessage());
    }

    // --- Test 5 : Peu de deprecations => OK ---

    public function testFewDeprecationsNoIssue(): void
    {
        $lines = [];
        for ($i = 0; $i < 10; $i++) {
            $lines[] = '[2026-03-01] php.DEPRECATION: User Deprecated: old method [context]';
        }
        file_put_contents($this->tempDir . '/var/log/dev.log', implode("\n", $lines));

        $analyzer = new LogAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(0, $warnings);
    }

    // --- Test 6 : getName et getModule ---

    public function testGetNameAndGetModule(): void
    {
        $analyzer = new LogAnalyzer($this->tempDir);

        $this->assertSame('Log Analyzer', $analyzer->getName());
        $this->assertSame(Module::DEPLOYMENT, $analyzer->getModule());
    }

    // --- Test 7 : supports retourne toujours true ---

    public function testSupportsAlwaysTrue(): void
    {
        $analyzer = new LogAnalyzer($this->tempDir);
        $context = new \PierreArthur\SfDoctor\Context\ProjectContext(
            projectPath: '/fake',
            hasDoctrineOrm: false,
            hasMessenger: false,
            hasApiPlatform: false,
            hasTwig: false,
            hasSecurityBundle: false,
            hasWebProfilerBundle: false,
            hasMailer: false,
            hasNelmioCors: false,
            hasNelmioSecurity: false,
            hasJwtAuth: false,
            symfonyVersion: null,
        );
        $this->assertTrue($analyzer->supports($context));
    }

    // --- Test 8 : Enrichment fields ---

    public function testEnrichmentFields(): void
    {
        $lines = [];
        for ($i = 0; $i < 15; $i++) {
            $lines[] = '[2026-03-01] app.ERROR: Connection refused [context]';
        }
        file_put_contents($this->tempDir . '/var/log/prod.log', implode("\n", $lines));

        $analyzer = new LogAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $issue = $report->getIssues()[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
    }

    // --- Test 9 : Prod log vide => OK ---

    public function testEmptyProdLogNoIssue(): void
    {
        file_put_contents($this->tempDir . '/var/log/prod.log', '');

        $analyzer = new LogAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
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
