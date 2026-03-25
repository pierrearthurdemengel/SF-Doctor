<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Tests;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Tests\TestFixtureAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class TestFixtureAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_fixture_' . uniqid();
        mkdir($this->tempDir . '/src/DataFixtures', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::TESTS]);
    }

    // --- Test 1 : Mot de passe en clair => CRITICAL ---

    public function testPlainPasswordCreatesCritical(): void
    {
        file_put_contents(
            $this->tempDir . '/src/DataFixtures/UserFixtures.php',
            '<?php
            class UserFixtures {
                public function load() {
                    $user->setPassword(\'password\');
                }
            }'
        );

        $analyzer = new TestFixtureAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('password', $criticals[0]->getMessage());
    }

    // --- Test 2 : Password hashe => OK ---

    public function testHashedPasswordNoIssue(): void
    {
        file_put_contents(
            $this->tempDir . '/src/DataFixtures/UserFixtures.php',
            '<?php
            class UserFixtures {
                public function load() {
                    $user->setPassword($this->hasher->hashPassword($user, \'password\'));
                }
            }'
        );

        $analyzer = new TestFixtureAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(0, $criticals);
    }

    // --- Test 3 : Email de production => WARNING ---

    public function testProductionEmailCreatesWarning(): void
    {
        file_put_contents(
            $this->tempDir . '/src/DataFixtures/UserFixtures.php',
            '<?php
            class UserFixtures {
                public function load() {
                    $user->setEmail(\'john@company.com\');
                }
            }'
        );

        $analyzer = new TestFixtureAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('john@company.com', $warnings[0]->getMessage());
    }

    // --- Test 4 : Email @example.com => OK ---

    public function testExampleEmailNoIssue(): void
    {
        file_put_contents(
            $this->tempDir . '/src/DataFixtures/UserFixtures.php',
            '<?php
            class UserFixtures {
                public function load() {
                    $user->setEmail(\'test@example.com\');
                }
            }'
        );

        $analyzer = new TestFixtureAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(0, $warnings);
    }

    // --- Test 5 : Pas de dossier fixtures => OK ---

    public function testNoFixtureDirDoesNothing(): void
    {
        $analyzer = new TestFixtureAnalyzer($this->tempDir . '/nonexistent');
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 6 : getName et getModule ---

    public function testGetNameAndGetModule(): void
    {
        $analyzer = new TestFixtureAnalyzer($this->tempDir);

        $this->assertSame('Test Fixture Analyzer', $analyzer->getName());
        $this->assertSame(Module::TESTS, $analyzer->getModule());
    }

    // --- Test 7 : Enrichment fields ---

    public function testEnrichmentFields(): void
    {
        file_put_contents(
            $this->tempDir . '/src/DataFixtures/AdminFixtures.php',
            '<?php
            class AdminFixtures {
                public function load() {
                    $admin->setPassword(\'admin\');
                }
            }'
        );

        $analyzer = new TestFixtureAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $issue = $report->getIssues()[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
    }

    // --- Test 8 : Multiple trivial passwords ---

    public function testMultipleTrivialPasswords(): void
    {
        file_put_contents(
            $this->tempDir . '/src/DataFixtures/Fixture1.php',
            '<?php class F1 { public function load() { $u->setPassword(\'123456\'); } }'
        );

        $analyzer = new TestFixtureAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('123456', $criticals[0]->getMessage());
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
