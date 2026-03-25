<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Diff\BaselineStorage;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class BaselineStorageTest extends TestCase
{
    private string $tempDir;
    private BaselineStorage $storage;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_baseline_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->storage = new BaselineStorage();
    }

    protected function tearDown(): void
    {
        // Nettoyage des fichiers temporaires.
        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $report = $this->createReport([
            $this->makeIssue(Severity::CRITICAL, Module::SECURITY, 'Pas de access_control'),
            $this->makeIssue(Severity::WARNING, Module::ARCHITECTURE, 'QueryBuilder dans controller'),
        ]);

        $path = $this->tempDir . '/baseline.json';
        $this->storage->save($path, $report);

        $this->assertFileExists($path);

        $loaded = $this->storage->load($path);

        $this->assertNotNull($loaded);
        $this->assertSame('/fake/path', $loaded->getProjectPath());
        $this->assertCount(2, $loaded->getIssues());
        $this->assertSame('Pas de access_control', $loaded->getIssues()[0]->getMessage());
        $this->assertSame(Severity::CRITICAL, $loaded->getIssues()[0]->getSeverity());
        $this->assertSame(Module::SECURITY, $loaded->getIssues()[0]->getModule());
    }

    public function testLoadReturnsNullForMissingFile(): void
    {
        $loaded = $this->storage->load($this->tempDir . '/inexistant.json');

        $this->assertNull($loaded);
    }

    public function testLoadReturnsNullForInvalidJson(): void
    {
        $path = $this->tempDir . '/invalid.json';
        file_put_contents($path, 'ceci nest pas du json');

        $loaded = $this->storage->load($path);

        $this->assertNull($loaded);
    }

    public function testSaveCreatesParentDirectories(): void
    {
        $path = $this->tempDir . '/sub/dir/baseline.json';
        $report = $this->createReport([]);

        $this->storage->save($path, $report);

        $this->assertFileExists($path);

        // Nettoyage des sous-repertoires.
        unlink($path);
        rmdir($this->tempDir . '/sub/dir');
        rmdir($this->tempDir . '/sub');
    }

    public function testSavedJsonContainsExpectedStructure(): void
    {
        $report = $this->createReport([
            $this->makeIssue(Severity::WARNING, Module::SECURITY, 'Test message', 'security.yaml', 42),
        ]);

        $path = $this->tempDir . '/baseline.json';
        $this->storage->save($path, $report);

        $data = json_decode(file_get_contents($path), true);

        $this->assertArrayHasKey('project_path', $data);
        $this->assertArrayHasKey('modules', $data);
        $this->assertArrayHasKey('score', $data);
        $this->assertArrayHasKey('issues', $data);
        $this->assertArrayHasKey('saved_at', $data);
        $this->assertCount(1, $data['issues']);
        $this->assertSame('warning', $data['issues'][0]['severity']);
        $this->assertSame('security.yaml', $data['issues'][0]['file']);
        $this->assertSame(42, $data['issues'][0]['line']);
    }

    public function testScoreIsPreservedAfterRoundTrip(): void
    {
        $report = $this->createReport([
            $this->makeIssue(Severity::CRITICAL, Module::SECURITY, 'Issue critique'),
            $this->makeIssue(Severity::WARNING, Module::SECURITY, 'Issue warning'),
        ]);

        $path = $this->tempDir . '/baseline.json';
        $this->storage->save($path, $report);
        $loaded = $this->storage->load($path);

        // Score = 100 - 10 (CRITICAL) - 3 (WARNING) = 87
        $this->assertSame(87, $loaded->getScore());
    }

    public function testModulesArePreservedAfterRoundTrip(): void
    {
        $report = new AuditReport('/fake/path', [Module::SECURITY]);
        $path = $this->tempDir . '/baseline.json';

        $this->storage->save($path, $report);
        $loaded = $this->storage->load($path);

        $this->assertCount(1, $loaded->getModules());
        $this->assertSame(Module::SECURITY, $loaded->getModules()[0]);
    }

    /**
     * @param Issue[] $issues
     */
    private function createReport(array $issues): AuditReport
    {
        $report = new AuditReport('/fake/path', [Module::SECURITY, Module::ARCHITECTURE, Module::PERFORMANCE]);

        foreach ($issues as $issue) {
            $report->addIssue($issue);
        }

        return $report;
    }

    private function makeIssue(
        Severity $severity,
        Module $module,
        string $message,
        ?string $file = null,
        ?int $line = null,
    ): Issue {
        return new Issue(
            severity: $severity,
            module: $module,
            analyzer: 'TestAnalyzer',
            message: $message,
            detail: 'Detail de test',
            suggestion: 'Suggestion de test',
            file: $file,
            line: $line,
        );
    }
}
