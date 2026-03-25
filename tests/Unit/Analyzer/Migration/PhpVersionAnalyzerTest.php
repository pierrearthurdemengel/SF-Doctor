<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Migration;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Migration\PhpVersionAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Tests unitaires pour PhpVersionAnalyzer.
 *
 * Verifie la detection des contraintes PHP incompatibles avec
 * Symfony 7 (>= 8.2) et Symfony 8.
 */
final class PhpVersionAnalyzerTest extends TestCase
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

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport($this->tempDir, [Module::MIGRATION]);
    }

    private function makeContext(): ProjectContext
    {
        return new ProjectContext(
            projectPath: $this->tempDir,
            hasDoctrineOrm: false, hasMessenger: false, hasApiPlatform: false,
            hasTwig: false, hasSecurityBundle: false, hasWebProfilerBundle: false,
            hasMailer: false, hasNelmioCors: false, hasNelmioSecurity: false,
            hasJwtAuth: false, symfonyVersion: null,
        );
    }

    // =============================================
    // Test 1 : supports() retourne false sans composer.json
    // =============================================

    public function testSupportsReturnsFalseWithoutComposerJson(): void
    {
        $analyzer = new PhpVersionAnalyzer($this->tempDir);

        $this->assertFalse($analyzer->supports($this->makeContext()));
    }

    // =============================================
    // Test 2 : supports() retourne true avec composer.json
    // =============================================

    public function testSupportsReturnsTrueWithComposerJson(): void
    {
        file_put_contents($this->tempDir . '/composer.json', '{}');
        $analyzer = new PhpVersionAnalyzer($this->tempDir);

        $this->assertTrue($analyzer->supports($this->makeContext()));
    }

    // =============================================
    // Test 3 : PHP >= 8.2 - aucune issue
    // =============================================

    public function testPhp82DoesNothing(): void
    {
        $composer = ['require' => ['php' => '>=8.2']];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new PhpVersionAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 4 : PHP ^7.4 - CRITICAL (ne supporte pas 8.2)
    // =============================================

    public function testPhp74CreatesCritical(): void
    {
        $composer = ['require' => ['php' => '^7.4']];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new PhpVersionAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('7.4', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 5 : PHP ^8.0 - WARNING (version minimale trop basse)
    // =============================================

    public function testPhp80CreatesWarning(): void
    {
        $composer = ['require' => ['php' => '^8.0']];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new PhpVersionAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        // ^8.0 autorise 8.2 (meme majeure), donc pas de CRITICAL.
        // Mais WARNING car la version minimale est inferieure a 8.2.
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('8.0', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 6 : PHP ^8.1 - WARNING
    // =============================================

    public function testPhp81CreatesWarning(): void
    {
        $composer = ['require' => ['php' => '^8.1']];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new PhpVersionAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
    }

    // =============================================
    // Test 7 : PHP ^8.2 - aucune issue
    // =============================================

    public function testPhp82CaretDoesNothing(): void
    {
        $composer = ['require' => ['php' => '^8.2']];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new PhpVersionAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 8 : Pas de contrainte PHP - aucune issue
    // =============================================

    public function testNoPhpConstraintDoesNothing(): void
    {
        $composer = ['require' => ['symfony/framework-bundle' => '^7.0']];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new PhpVersionAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 9 : Verification des metadonnees
    // =============================================

    public function testGetNameReturnsExpectedName(): void
    {
        $analyzer = new PhpVersionAnalyzer($this->tempDir);
        $this->assertSame('PHP Version Analyzer', $analyzer->getName());
    }

    public function testGetModuleReturnsMigration(): void
    {
        $analyzer = new PhpVersionAnalyzer($this->tempDir);
        $this->assertSame(Module::MIGRATION, $analyzer->getModule());
    }

    // =============================================
    // Test 10 : Champs d'enrichissement sur CRITICAL (PHP trop vieux)
    // =============================================

    public function testPhpTooOldIssueHasEnrichmentFields(): void
    {
        $composer = ['require' => ['php' => '^7.4']];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new PhpVersionAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertSame(60, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('releases', $issue->getDocUrl() ?? '');
        $this->assertSame('composer.json', $issue->getFile());
    }

    // =============================================
    // Test 11 : Champs d'enrichissement sur WARNING (^8.0 / ^8.1)
    // =============================================

    public function testPhpMinVersionWarningHasEnrichmentFields(): void
    {
        $composer = ['require' => ['php' => '^8.1']];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new PhpVersionAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertSame(15, $issue->getEstimatedFixMinutes());
    }
}
