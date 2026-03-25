<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Deployment;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Deployment\AssetsAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Tests unitaires pour AssetsAnalyzer.
 *
 * Verifie la detection des problemes lies aux assets front-end :
 * dossier build absent/vide, manifest.json manquant, node_modules absent.
 */
final class AssetsAnalyzerTest extends TestCase
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
        return new AuditReport($this->tempDir, [Module::DEPLOYMENT]);
    }

    // =============================================
    // Test 1 : Pas de dossier public/build/ - WARNING
    // =============================================

    public function testNoBuildDirCreatesWarning(): void
    {
        $analyzer = new AssetsAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        // Au moins un warning pour le dossier build absent.
        $found = false;
        foreach ($warnings as $w) {
            if (str_contains($w->getMessage(), 'public/build/') && str_contains($w->getMessage(), 'absent')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Devrait signaler le dossier public/build/ absent');
    }

    // =============================================
    // Test 2 : Dossier public/build/ vide - WARNING
    // =============================================

    public function testEmptyBuildDirCreatesWarning(): void
    {
        mkdir($this->tempDir . '/public/build', 0777, true);

        $analyzer = new AssetsAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $found = false;
        foreach ($warnings as $w) {
            if (str_contains($w->getMessage(), 'vide')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Devrait signaler le dossier public/build/ vide');
    }

    // =============================================
    // Test 3 : Dossier public/build/ avec fichiers et manifest - pas de warning
    // =============================================

    public function testBuildDirWithFilesDoesNothing(): void
    {
        mkdir($this->tempDir . '/public/build', 0777, true);
        file_put_contents($this->tempDir . '/public/build/app.js', '// compiled');
        file_put_contents($this->tempDir . '/public/build/manifest.json', '{}');

        $analyzer = new AssetsAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(0, $warnings);
    }

    // =============================================
    // Test 4 : manifest.json manquant dans public/build/ - WARNING
    // =============================================

    public function testMissingManifestCreatesWarning(): void
    {
        mkdir($this->tempDir . '/public/build', 0777, true);
        // Ajouter un fichier pour que le dossier ne soit pas vide.
        file_put_contents($this->tempDir . '/public/build/app.js', '// compiled');

        $analyzer = new AssetsAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $found = false;
        foreach ($warnings as $w) {
            if (str_contains($w->getMessage(), 'manifest.json')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Devrait signaler l\'absence de manifest.json');
    }

    // =============================================
    // Test 5 : package.json sans node_modules/ - SUGGESTION
    // =============================================

    public function testPackageJsonWithoutNodeModulesCreatesSuggestion(): void
    {
        file_put_contents($this->tempDir . '/package.json', '{"dependencies": {}}');
        // Creer public/build avec manifest pour eviter les autres warnings.
        mkdir($this->tempDir . '/public/build', 0777, true);
        file_put_contents($this->tempDir . '/public/build/app.js', '// compiled');
        file_put_contents($this->tempDir . '/public/build/manifest.json', '{}');

        $analyzer = new AssetsAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(1, $suggestions);
        $this->assertStringContainsString('node_modules', $suggestions[0]->getMessage());
    }

    // =============================================
    // Test 6 : Champs d'enrichissement sur issue build absent
    // =============================================

    public function testEnrichmentFields(): void
    {
        $analyzer = new AssetsAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertGreaterThan(0, count($warnings));

        // Verifier le premier warning (build absent).
        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertSame(10, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('encore', $issue->getDocUrl() ?? '');
    }

    // =============================================
    // Test 7 : Verification des metadonnees
    // =============================================

    public function testGetNameReturnsExpectedName(): void
    {
        $analyzer = new AssetsAnalyzer($this->tempDir);
        $this->assertSame('Assets Analyzer', $analyzer->getName());
    }

    public function testGetModuleReturnsDeployment(): void
    {
        $analyzer = new AssetsAnalyzer($this->tempDir);
        $this->assertSame(Module::DEPLOYMENT, $analyzer->getModule());
    }

    // =============================================
    // Test 8 : supports() necessite package.json ou webpack.config.js
    // =============================================

    public function testSupportsReturnsTrueWithPackageJson(): void
    {
        file_put_contents($this->tempDir . '/package.json', '{}');
        $analyzer = new AssetsAnalyzer($this->tempDir);

        $context = new ProjectContext(
            projectPath: $this->tempDir,
            hasDoctrineOrm: false, hasMessenger: false, hasApiPlatform: false,
            hasTwig: false, hasSecurityBundle: false, hasWebProfilerBundle: false,
            hasMailer: false, hasNelmioCors: false, hasNelmioSecurity: false,
            hasJwtAuth: false, symfonyVersion: null,
        );

        $this->assertTrue($analyzer->supports($context));
    }

    public function testSupportsReturnsFalseWithoutPackageJsonOrWebpack(): void
    {
        $analyzer = new AssetsAnalyzer($this->tempDir);

        $context = new ProjectContext(
            projectPath: $this->tempDir,
            hasDoctrineOrm: false, hasMessenger: false, hasApiPlatform: false,
            hasTwig: false, hasSecurityBundle: false, hasWebProfilerBundle: false,
            hasMailer: false, hasNelmioCors: false, hasNelmioSecurity: false,
            hasJwtAuth: false, symfonyVersion: null,
        );

        $this->assertFalse($analyzer->supports($context));
    }

    // =============================================
    // Test 9 : package.json present avec node_modules - pas de suggestion
    // =============================================

    public function testPackageJsonWithNodeModulesDoesNothing(): void
    {
        file_put_contents($this->tempDir . '/package.json', '{"dependencies": {}}');
        mkdir($this->tempDir . '/node_modules', 0777, true);
        // Creer public/build avec manifest pour eviter les warnings.
        mkdir($this->tempDir . '/public/build', 0777, true);
        file_put_contents($this->tempDir . '/public/build/app.js', '// compiled');
        file_put_contents($this->tempDir . '/public/build/manifest.json', '{}');

        $analyzer = new AssetsAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(0, $suggestions);
    }
}
