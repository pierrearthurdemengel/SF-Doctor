<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Doctrine;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Doctrine\LazyGhostObjectAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class LazyGhostObjectAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_lazy_ghost_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::DOCTRINE]);
    }

    private function createAnalyzer(?array $doctrineConfig): LazyGhostObjectAnalyzer
    {
        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn($doctrineConfig);

        return new LazyGhostObjectAnalyzer($configReader, $this->tempDir);
    }

    private function writeComposerLock(string $doctrineVersion): void
    {
        $lock = [
            'packages' => [
                [
                    'name' => 'doctrine/orm',
                    'version' => $doctrineVersion,
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/composer.lock', json_encode($lock));
    }

    // --- Test 1 : Pas de config Doctrine => aucune issue ---

    public function testNoDoctrineConfigDoesNothing(): void
    {
        $analyzer = $this->createAnalyzer(null);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 2 : lazy_ghost desactive => aucune issue ---

    public function testLazyGhostDisabledDoesNothing(): void
    {
        $analyzer = $this->createAnalyzer([
            'doctrine' => [
                'orm' => [
                    'enable_lazy_ghost_objects' => false,
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 3 : lazy_ghost actif + Doctrine ORM 2.17 => WARNING ---

    public function testLazyGhostWithOrm217CreatesWarning(): void
    {
        $this->writeComposerLock('2.17.1');

        $analyzer = $this->createAnalyzer([
            'doctrine' => [
                'orm' => [
                    'enable_lazy_ghost_objects' => true,
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('lazy_ghost', $warnings[0]->getMessage());
        $this->assertStringContainsString('2.17.1', $warnings[0]->getMessage());
    }

    // --- Test 4 : lazy_ghost actif + Doctrine ORM 2.15 => WARNING ---

    public function testLazyGhostWithOrm215CreatesWarning(): void
    {
        $this->writeComposerLock('2.15.0');

        $analyzer = $this->createAnalyzer([
            'doctrine' => [
                'orm' => [
                    'enable_lazy_ghost_objects' => true,
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
    }

    // --- Test 5 : lazy_ghost actif + Doctrine ORM 3.x => aucune issue ---

    public function testLazyGhostWithOrm3DoesNothing(): void
    {
        $this->writeComposerLock('3.0.0');

        $analyzer = $this->createAnalyzer([
            'doctrine' => [
                'orm' => [
                    'enable_lazy_ghost_objects' => true,
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 6 : lazy_ghost actif sans composer.lock => SUGGESTION ---

    public function testLazyGhostWithoutComposerLockCreatesSuggestion(): void
    {
        // Pas de writeComposerLock => pas de composer.lock
        $analyzer = $this->createAnalyzer([
            'doctrine' => [
                'orm' => [
                    'enable_lazy_ghost_objects' => true,
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(1, $suggestions);
        $this->assertStringContainsString('version', $suggestions[0]->getMessage());
    }

    // --- Test 7 : Enrichment fields ---

    public function testEnrichmentFields(): void
    {
        $this->writeComposerLock('2.16.0');

        $analyzer = $this->createAnalyzer([
            'doctrine' => [
                'orm' => [
                    'enable_lazy_ghost_objects' => true,
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $issue = $report->getIssues()[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
    }

    // --- Test 8 : getName et getModule ---

    public function testGetNameAndGetModule(): void
    {
        $analyzer = $this->createAnalyzer(null);

        $this->assertSame('Lazy Ghost Object Analyzer', $analyzer->getName());
        $this->assertSame(Module::DOCTRINE, $analyzer->getModule());
    }

    // --- Test 9 : supports retourne true si Doctrine ORM present ---

    public function testSupportsReturnsTrueWithDoctrine(): void
    {
        $analyzer = $this->createAnalyzer(null);
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

    // --- Test 10 : supports retourne false sans Doctrine ORM ---

    public function testSupportsReturnsFalseWithoutDoctrine(): void
    {
        $analyzer = $this->createAnalyzer(null);
        $context = new ProjectContext(
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
        $this->assertFalse($analyzer->supports($context));
    }

    // --- Helpers ---

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
