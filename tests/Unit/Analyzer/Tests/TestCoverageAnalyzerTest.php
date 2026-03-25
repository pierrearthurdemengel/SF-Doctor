<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Tests;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Tests\TestCoverageAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Tests unitaires pour TestCoverageAnalyzer.
 *
 * Verifie la detection de l'absence de phpunit.xml, l'absence du
 * repertoire tests/ et le nombre insuffisant de fichiers de test.
 */
final class TestCoverageAnalyzerTest extends TestCase
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
        return new AuditReport($this->tempDir, [Module::TESTS]);
    }

    // =============================================
    // Test 1 : Pas de phpunit.xml ni phpunit.xml.dist - WARNING
    // =============================================

    public function testNoPhpunitConfigCreatesWarning(): void
    {
        // Creer le dossier tests/ pour eviter le CRITICAL du test 3.
        mkdir($this->tempDir . '/tests', 0777, true);
        // Ajouter assez de fichiers de test pour eviter le warning "peu de tests".
        for ($i = 1; $i <= 3; $i++) {
            file_put_contents($this->tempDir . "/tests/Feature{$i}Test.php", "<?php\nclass Feature{$i}Test {}");
        }

        $analyzer = new TestCoverageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('phpunit.xml', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 2 : phpunit.xml present - pas de warning pour la config
    // =============================================

    public function testPhpunitXmlExistsDoesNothing(): void
    {
        file_put_contents($this->tempDir . '/phpunit.xml', '<phpunit/>');
        mkdir($this->tempDir . '/tests', 0777, true);
        // Ajouter assez de fichiers de test.
        for ($i = 1; $i <= 3; $i++) {
            file_put_contents($this->tempDir . "/tests/Feature{$i}Test.php", "<?php\nclass Feature{$i}Test {}");
        }

        $analyzer = new TestCoverageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        // Aucun warning pour phpunit.xml (il existe).
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(0, $warnings);
    }

    // =============================================
    // Test 3 : Pas de dossier tests/ - CRITICAL
    // =============================================

    public function testNoTestsDirCreatesCritical(): void
    {
        $analyzer = new TestCoverageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('tests/', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 4 : Moins de 3 fichiers de test - WARNING
    // =============================================

    public function testFewTestFilesCreatesWarning(): void
    {
        file_put_contents($this->tempDir . '/phpunit.xml', '<phpunit/>');
        mkdir($this->tempDir . '/tests', 0777, true);
        // Seulement 2 fichiers de test (en dessous du seuil de 3).
        file_put_contents($this->tempDir . '/tests/OneTest.php', "<?php\nclass OneTest {}");
        file_put_contents($this->tempDir . '/tests/TwoTest.php', "<?php\nclass TwoTest {}");

        $analyzer = new TestCoverageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('2 fichier', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 5 : Assez de fichiers de test (>= 3) - pas de warning
    // =============================================

    public function testEnoughTestFilesDoesNothing(): void
    {
        file_put_contents($this->tempDir . '/phpunit.xml', '<phpunit/>');
        mkdir($this->tempDir . '/tests', 0777, true);
        for ($i = 1; $i <= 5; $i++) {
            file_put_contents($this->tempDir . "/tests/Feature{$i}Test.php", "<?php\nclass Feature{$i}Test {}");
        }

        $analyzer = new TestCoverageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        // Aucune issue (phpunit.xml present, assez de tests).
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 6 : Champs d'enrichissement sur CRITICAL (pas de tests/)
    // =============================================

    public function testEnrichmentFields(): void
    {
        $analyzer = new TestCoverageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertSame(120, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('testing.html', $issue->getDocUrl() ?? '');
    }

    // =============================================
    // Test 7 : phpunit.xml.dist est aussi accepte
    // =============================================

    public function testPhpunitXmlDistIsAccepted(): void
    {
        file_put_contents($this->tempDir . '/phpunit.xml.dist', '<phpunit/>');
        mkdir($this->tempDir . '/tests', 0777, true);
        for ($i = 1; $i <= 3; $i++) {
            file_put_contents($this->tempDir . "/tests/Feature{$i}Test.php", "<?php\nclass Feature{$i}Test {}");
        }

        $analyzer = new TestCoverageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 8 : Verification des metadonnees
    // =============================================

    public function testGetNameReturnsExpectedName(): void
    {
        $analyzer = new TestCoverageAnalyzer($this->tempDir);
        $this->assertSame('Test Coverage Analyzer', $analyzer->getName());
    }

    public function testGetModuleReturnsTests(): void
    {
        $analyzer = new TestCoverageAnalyzer($this->tempDir);
        $this->assertSame(Module::TESTS, $analyzer->getModule());
    }

    public function testSupportsAlwaysReturnsTrue(): void
    {
        $analyzer = new TestCoverageAnalyzer($this->tempDir);
        $context = new ProjectContext(
            projectPath: $this->tempDir,
            hasDoctrineOrm: false, hasMessenger: false, hasApiPlatform: false,
            hasTwig: false, hasSecurityBundle: false, hasWebProfilerBundle: false,
            hasMailer: false, hasNelmioCors: false, hasNelmioSecurity: false,
            hasJwtAuth: false, symfonyVersion: null,
        );
        $this->assertTrue($analyzer->supports($context));
    }

    // =============================================
    // Test 9 : Fichiers dans des sous-repertoires sont comptes
    // =============================================

    public function testTestFilesInSubdirectoriesAreCounted(): void
    {
        file_put_contents($this->tempDir . '/phpunit.xml', '<phpunit/>');
        mkdir($this->tempDir . '/tests/Unit', 0777, true);
        mkdir($this->tempDir . '/tests/Functional', 0777, true);
        file_put_contents($this->tempDir . '/tests/Unit/ServiceTest.php', "<?php\nclass ServiceTest {}");
        file_put_contents($this->tempDir . '/tests/Unit/RepositoryTest.php', "<?php\nclass RepositoryTest {}");
        file_put_contents($this->tempDir . '/tests/Functional/HomeTest.php', "<?php\nclass HomeTest {}");

        $analyzer = new TestCoverageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        // 3 fichiers de test dans les sous-repertoires = pas de warning.
        $this->assertCount(0, $report->getIssues());
    }
}
