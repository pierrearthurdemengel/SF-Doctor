<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Migration;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Migration\BundleDependencyAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Tests unitaires pour BundleDependencyAnalyzer.
 *
 * Verifie la detection des bundles abandonnes (FOSUserBundle, SonataUserBundle)
 * et la compatibilite des contraintes Symfony avec la version 7.x.
 */
final class BundleDependencyAnalyzerTest extends TestCase
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
    // Test 1 : composer.json absent - supports() retourne false
    // =============================================

    public function testSupportsReturnsFalseWithoutComposerJson(): void
    {
        $analyzer = new BundleDependencyAnalyzer($this->tempDir);

        $this->assertFalse($analyzer->supports($this->makeContext()));
    }

    // =============================================
    // Test 2 : composer.json present - supports() retourne true
    // =============================================

    public function testSupportsReturnsTrueWithComposerJson(): void
    {
        file_put_contents($this->tempDir . '/composer.json', '{}');
        $analyzer = new BundleDependencyAnalyzer($this->tempDir);

        $this->assertTrue($analyzer->supports($this->makeContext()));
    }

    // =============================================
    // Test 3 : FOSUserBundle detecte comme abandonne
    // =============================================

    public function testFosUserBundleCreatesCritical(): void
    {
        $composer = [
            'require' => [
                'friendsofsymfony/user-bundle' => '^2.0',
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new BundleDependencyAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('friendsofsymfony/user-bundle', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 4 : SonataUserBundle < 5.0 detecte comme abandonne
    // =============================================

    public function testSonataUserBundleOldVersionCreatesCritical(): void
    {
        $composer = [
            'require' => [
                'sonata-project/user-bundle' => '^4.0',
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new BundleDependencyAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('sonata-project/user-bundle', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 5 : SonataUserBundle >= 5.0 est accepte
    // =============================================

    public function testSonataUserBundleV5DoesNothing(): void
    {
        $composer = [
            'require' => [
                'sonata-project/user-bundle' => '^5.0',
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new BundleDependencyAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(0, $criticals);
    }

    // =============================================
    // Test 6 : Packages Symfony incompatibles avec 7.x
    // =============================================

    public function testSymfonyPackagesWithoutV7CreateWarning(): void
    {
        $composer = [
            'require' => [
                'symfony/framework-bundle' => '^5.4',
                'symfony/console' => '^5.4',
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new BundleDependencyAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Symfony 7.x', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 7 : Packages Symfony compatibles 7.x - aucun warning
    // =============================================

    public function testSymfonyPackagesWithV7DoesNothing(): void
    {
        $composer = [
            'require' => [
                'symfony/framework-bundle' => '^6.4 || ^7.0',
                'symfony/console' => '^7.0',
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new BundleDependencyAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(0, $warnings);
    }

    // =============================================
    // Test 8 : Les packages utilitaires (flex, runtime) sont ignores
    // =============================================

    public function testSymfonyFlexIsIgnored(): void
    {
        $composer = [
            'require' => [
                'symfony/flex' => '^2.0',
                'symfony/runtime' => '^6.0',
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new BundleDependencyAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(0, $warnings);
    }

    // =============================================
    // Test 9 : Aucune dependance dans composer.json
    // =============================================

    public function testNoRequireSectionDoesNothing(): void
    {
        file_put_contents($this->tempDir . '/composer.json', json_encode(['name' => 'test/project']));

        $analyzer = new BundleDependencyAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 10 : Verification des metadonnees de l'analyzer
    // =============================================

    public function testGetNameReturnsExpectedName(): void
    {
        $analyzer = new BundleDependencyAnalyzer($this->tempDir);
        $this->assertSame('Bundle Dependency Analyzer', $analyzer->getName());
    }

    public function testGetModuleReturnsMigration(): void
    {
        $analyzer = new BundleDependencyAnalyzer($this->tempDir);
        $this->assertSame(Module::MIGRATION, $analyzer->getModule());
    }

    // =============================================
    // Test 11 : Champs d'enrichissement sur un bundle abandonne
    // =============================================

    public function testAbandonedBundleIssueHasEnrichmentFields(): void
    {
        $composer = [
            'require' => [
                'friendsofsymfony/user-bundle' => '^2.0',
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new BundleDependencyAnalyzer($this->tempDir);
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
        $this->assertStringContainsString('security.html', $issue->getDocUrl() ?? '');
        $this->assertSame('composer.json', $issue->getFile());
    }

    // =============================================
    // Test 12 : Champs d'enrichissement sur packages incompatibles
    // =============================================

    public function testIncompatiblePackagesIssueHasEnrichmentFields(): void
    {
        $composer = [
            'require' => [
                'symfony/framework-bundle' => '^5.4',
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer));

        $analyzer = new BundleDependencyAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertSame(30, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('upgrade_major', $issue->getDocUrl() ?? '');
    }
}
