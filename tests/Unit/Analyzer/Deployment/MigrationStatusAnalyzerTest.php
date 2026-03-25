<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Deployment;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Deployment\MigrationStatusAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class MigrationStatusAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_migration_status_' . uniqid();
        mkdir($this->tempDir . '/migrations', 0777, true);
        // Create a minimal composer.json with doctrine-migrations-bundle
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'require' => [
                'doctrine/doctrine-migrations-bundle' => '^3.0',
            ],
        ]));
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::DEPLOYMENT]);
    }

    // --- Test 1 : Pas de dossier migrations => OK ---

    public function testNoMigrationsDirDoesNothing(): void
    {
        $this->deleteDirectory($this->tempDir . '/migrations');
        $analyzer = new MigrationStatusAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 2 : Migrations anciennes => WARNING ---

    public function testOldMigrationsCreatesWarning(): void
    {
        // Create an old migration file (timestamp from 2024)
        file_put_contents(
            $this->tempDir . '/migrations/Version20240101120000.php',
            '<?php declare(strict_types=1); class Version20240101120000 {}'
        );

        $analyzer = new MigrationStatusAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertGreaterThanOrEqual(1, count($warnings));
    }

    // --- Test 3 : Pas de composer.json => OK ---

    public function testNoComposerJsonDoesNothing(): void
    {
        unlink($this->tempDir . '/composer.json');
        file_put_contents(
            $this->tempDir . '/migrations/Version20240101120000.php',
            '<?php class Version20240101120000 {}'
        );

        $analyzer = new MigrationStatusAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 4 : Pas de doctrine-migrations-bundle => OK ---

    public function testNoDoctrineMigrationsBundleDoesNothing(): void
    {
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'require' => ['symfony/console' => '^6.0'],
        ]));
        file_put_contents(
            $this->tempDir . '/migrations/Version20240101120000.php',
            '<?php class Version20240101120000 {}'
        );

        $analyzer = new MigrationStatusAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 5 : getName et getModule ---

    public function testGetNameAndGetModule(): void
    {
        $analyzer = new MigrationStatusAnalyzer($this->tempDir);

        $this->assertSame('Migration Status Analyzer', $analyzer->getName());
        $this->assertSame(Module::DEPLOYMENT, $analyzer->getModule());
    }

    // --- Test 6 : supports retourne true si Doctrine ORM present ---

    public function testSupportsWithDoctrine(): void
    {
        $analyzer = new MigrationStatusAnalyzer($this->tempDir);
        $context = new ProjectContext(
            projectPath: '/fake',
            hasDoctrineOrm: true,
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

    // --- Test 7 : Enrichment fields ---

    public function testEnrichmentFields(): void
    {
        file_put_contents(
            $this->tempDir . '/migrations/Version20240101120000.php',
            '<?php class Version20240101120000 {}'
        );

        $analyzer = new MigrationStatusAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertGreaterThanOrEqual(1, count($report->getIssues()));
        $issue = $report->getIssues()[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
    }

    // --- Test 8 : Dossier migrations vide => OK ---

    public function testEmptyMigrationsDirDoesNothing(): void
    {
        $analyzer = new MigrationStatusAnalyzer($this->tempDir);
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
