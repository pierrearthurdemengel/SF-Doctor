<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Configuration;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Configuration\DebugModeAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Severity;

class DebugModeAnalyzerTest extends TestCase
{
    private string $projectPath;
    private DebugModeAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/sf-doctor-test-' . uniqid();
        mkdir($this->projectPath);
        $this->analyzer = new DebugModeAnalyzer($this->projectPath);
    }

    protected function tearDown(): void
    {
        // Supprime les fichiers crees pendant le test.
        foreach (['.env', '.env.prod'] as $file) {
            $path = $this->projectPath . '/' . $file;
            if (file_exists($path)) {
                unlink($path);
            }
        }

        rmdir($this->projectPath);
    }

    // --- supports() ---

    public function testSupportsReturnsTrueWhenEnvFileExists(): void
    {
        file_put_contents($this->projectPath . '/.env', 'APP_ENV=prod');

        $this->assertTrue($this->analyzer->supports());
    }

    public function testSupportsReturnsTrueWhenEnvProdFileExists(): void
    {
        file_put_contents($this->projectPath . '/.env.prod', 'APP_ENV=prod');

        $this->assertTrue($this->analyzer->supports());
    }

    public function testSupportsReturnsFalseWhenNoEnvFileExists(): void
    {
        $this->assertFalse($this->analyzer->supports());
    }

    // --- .env.prod prioritaire sur .env ---

    public function testEnvProdTakesPriorityOverEnv(): void
    {
        // .env contient APP_ENV=dev (dangereux)
        // .env.prod contient APP_ENV=prod (correct)
        // L'analyzer doit lire .env.prod et ne signaler aucun probleme.
        file_put_contents($this->projectPath . '/.env', 'APP_ENV=dev');
        file_put_contents($this->projectPath . '/.env.prod', 'APP_ENV=prod');

        $report = new AuditReport($this->projectPath, []);

        $this->analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- checkAppEnv ---

    public function testNoIssueWhenAppEnvIsProd(): void
    {
        file_put_contents($this->projectPath . '/.env', "APP_ENV=prod\nAPP_DEBUG=false");

        $report = new AuditReport($this->projectPath, []);

        $this->analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    public function testCriticalWhenAppEnvIsDev(): void
    {
        file_put_contents($this->projectPath . '/.env', 'APP_ENV=dev');

        $report = new AuditReport($this->projectPath, []);

        $this->analyzer->analyze($report);

        $issues = $report->getIssues();
        $this->assertCount(1, $issues);
        $this->assertSame(Severity::CRITICAL, $issues[0]->getSeverity());
        $this->assertStringContainsString('APP_ENV', $issues[0]->getMessage());
        $this->assertStringContainsString('dev', $issues[0]->getMessage());
    }

    public function testWarningWhenAppEnvIsNotDefined(): void
    {
        file_put_contents($this->projectPath . '/.env', 'APP_DEBUG=false');

        $report = new AuditReport($this->projectPath, []);

        $this->analyzer->analyze($report);

        $issues = $report->getIssues();
        $this->assertCount(1, $issues);
        $this->assertSame(Severity::WARNING, $issues[0]->getSeverity());
    }

    // --- checkAppDebug ---

    public function testCriticalWhenAppDebugIsTrue(): void
    {
        file_put_contents($this->projectPath . '/.env', "APP_ENV=prod\nAPP_DEBUG=true");

        $report = new AuditReport($this->projectPath, []);

        $this->analyzer->analyze($report);

        $issues = $report->getIssues();
        $this->assertCount(1, $issues);
        $this->assertSame(Severity::CRITICAL, $issues[0]->getSeverity());
        $this->assertStringContainsString('APP_DEBUG', $issues[0]->getMessage());
    }

    public function testCriticalWhenAppDebugIsOne(): void
    {
        file_put_contents($this->projectPath . '/.env', "APP_ENV=prod\nAPP_DEBUG=1");

        $report = new AuditReport($this->projectPath, []);

        $this->analyzer->analyze($report);

        $issues = $report->getIssues();
        $this->assertCount(1, $issues);
        $this->assertSame(Severity::CRITICAL, $issues[0]->getSeverity());
    }

    public function testNoIssueWhenAppDebugIsFalse(): void
    {
        file_put_contents($this->projectPath . '/.env', "APP_ENV=prod\nAPP_DEBUG=false");

        $report = new AuditReport($this->projectPath, []);

        $this->analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- parsing ---

    public function testIgnoresCommentLines(): void
    {
        file_put_contents($this->projectPath . '/.env', "# This is a comment\nAPP_ENV=prod");

        $report = new AuditReport($this->projectPath, []);

        $this->analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    public function testHandlesQuotedValues(): void
    {
        file_put_contents($this->projectPath . '/.env', 'APP_ENV="prod"');

        $report = new AuditReport($this->projectPath, []);

        $this->analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Enrichissement des champs ---

    public function testAppEnvAbsentIssueHasEnrichmentFields(): void
    {
        file_put_contents($this->projectPath . '/.env', 'APP_DEBUG=false');

        $report = new AuditReport($this->projectPath, []);
        $this->analyzer->analyze($report);

        $issues = $report->getIssues();
        $this->assertCount(1, $issues);

        $issue = $issues[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(5, $issue->getEstimatedFixMinutes());
    }

    public function testAppEnvWrongValueIssueHasEnrichmentFields(): void
    {
        file_put_contents($this->projectPath . '/.env', 'APP_ENV=dev');

        $report = new AuditReport($this->projectPath, []);
        $this->analyzer->analyze($report);

        $issues = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $issues);

        $issue = $issues[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(5, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('environments', $issue->getDocUrl() ?? '');
    }

    public function testAppDebugTrueIssueHasEnrichmentFields(): void
    {
        file_put_contents($this->projectPath . '/.env', "APP_ENV=prod\nAPP_DEBUG=true");

        $report = new AuditReport($this->projectPath, []);
        $this->analyzer->analyze($report);

        $issues = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $issues);

        $issue = $issues[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(5, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('debug-mode', $issue->getDocUrl() ?? '');
    }

}