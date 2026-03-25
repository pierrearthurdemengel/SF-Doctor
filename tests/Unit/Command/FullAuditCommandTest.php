<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Command\FullAuditCommand;
use PierreArthur\SfDoctor\Config\NullParameterResolver;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Report\ConsoleReporter;
use PierreArthur\SfDoctor\Report\ReporterInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class FullAuditCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_full_audit_' . uniqid();
        mkdir($this->tempDir, 0777, true);

        // Create a minimal composer.json so ProjectContextDetector works
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'require' => ['symfony/framework-bundle' => '^6.4'],
        ]));
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    // --- Test 1 : Projet propre => SUCCESS ---

    public function testCleanProjectReturnsSuccess(): void
    {
        $analyzer = $this->createAnalyzer([]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Audit complet', $tester->getDisplay());
    }

    // --- Test 2 : CRITICAL => FAILURE ---

    public function testCriticalIssueReturnsFailure(): void
    {
        $analyzer = $this->createAnalyzer([
            new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Faille critique detectee',
                detail: 'Detail.',
                suggestion: 'Corriger.',
                estimatedFixMinutes: 30,
            ),
        ]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    // --- Test 3 : Verdict "sain" pour score >= 80, 0 CRITICAL ---

    public function testHealthyVerdictForCleanProject(): void
    {
        $analyzer = $this->createAnalyzer([]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('sain', $display);
    }

    // --- Test 4 : Verdict "dette technique" pour CRITICAL ---

    public function testDebtVerdictForCriticalIssues(): void
    {
        $issues = [];
        for ($i = 0; $i < 2; $i++) {
            $issues[] = new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: "Issue critique $i",
                detail: 'Detail.',
                suggestion: 'Fix.',
                estimatedFixMinutes: 30,
            );
        }

        $analyzer = $this->createAnalyzer($issues);
        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('VERDICT', $display);
    }

    // --- Test 5 : Affichage dette technique ---

    public function testDebtSummaryIsDisplayed(): void
    {
        $analyzer = $this->createAnalyzer([
            new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Issue de test',
                detail: 'Detail.',
                suggestion: 'Fix.',
                estimatedFixMinutes: 120,
            ),
        ]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Dette technique', $display);
        $this->assertStringContainsString('heures', $display);
    }

    // --- Test 6 : Option --tjm ---

    public function testTjmOption(): void
    {
        $analyzer = $this->createAnalyzer([
            new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Issue de test',
                detail: 'Detail.',
                suggestion: 'Fix.',
                estimatedFixMinutes: 420, // 7h = 1 day
            ),
        ]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute(['--tjm' => '700']);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('700', $display);
    }

    // --- Test 7 : Top CRITICAL affiches ---

    public function testTopCriticalsAreDisplayed(): void
    {
        $issues = [];
        for ($i = 0; $i < 3; $i++) {
            $issues[] = new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: "Faille critique numero $i",
                detail: 'Detail.',
                suggestion: 'Fix.',
                estimatedFixMinutes: ($i + 1) * 15,
            );
        }

        $analyzer = $this->createAnalyzer($issues);
        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('CRITICAL', $display);
        $this->assertStringContainsString('priorite', $display);
    }

    // --- Test 8 : Nombre d'analyzers affiches ---

    public function testAnalyzerCountIsDisplayed(): void
    {
        $analyzer1 = $this->createAnalyzer([]);
        $analyzer2 = $this->createAnalyzer([]);

        $tester = $this->createCommandTester([$analyzer1, $analyzer2]);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('2', $display);
        $this->assertStringContainsString('analyzers', $display);
    }

    // --- Test 9 : Format inconnu => FAILURE ---

    public function testUnknownFormatReturnsFailure(): void
    {
        $analyzer = $this->createAnalyzer([]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute(['--format' => 'xml']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('inconnu', $tester->getDisplay());
    }

    // --- Test 10 : Verdict "Refonte" pour score tres bas ---

    public function testRefactoringVerdictForManyIssues(): void
    {
        // Spread CRITICALs across many weighted modules to drop global score below 50
        $modules = [
            Module::SECURITY, Module::ARCHITECTURE, Module::PERFORMANCE,
            Module::DOCTRINE, Module::DEPLOYMENT, Module::TESTS,
        ];
        $analyzers = [];
        foreach ($modules as $module) {
            $issues = [];
            for ($i = 0; $i < 11; $i++) {
                $issues[] = new Issue(
                    severity: Severity::CRITICAL,
                    module: $module,
                    analyzer: 'TestAnalyzer',
                    message: "Faille critique {$module->value} $i",
                    detail: 'Detail.',
                    suggestion: 'Fix.',
                    estimatedFixMinutes: 30,
                );
            }
            $analyzer = $this->createMock(AnalyzerInterface::class);
            $analyzer->method('getName')->willReturn('Test Analyzer ' . $module->value);
            $analyzer->method('getModule')->willReturn($module);
            $analyzer->method('supports')->willReturn(true);
            $analyzer->method('analyze')->willReturnCallback(
                function (AuditReport $report) use ($issues): void {
                    foreach ($issues as $issue) {
                        $report->addIssue($issue);
                    }
                }
            );
            $analyzers[] = $analyzer;
        }

        $tester = $this->createCommandTester($analyzers);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Refonte', $display);
    }

    // ===============================================================
    // HELPERS
    // ===============================================================

    /**
     * @param list<Issue> $issues
     */
    private function createAnalyzer(array $issues): AnalyzerInterface
    {
        $analyzer = $this->createMock(AnalyzerInterface::class);
        $analyzer->method('getName')->willReturn('Test Analyzer');
        $analyzer->method('getModule')->willReturn(Module::SECURITY);
        $analyzer->method('supports')->willReturn(true);
        $analyzer->method('analyze')->willReturnCallback(
            function (AuditReport $report) use ($issues): void {
                foreach ($issues as $issue) {
                    $report->addIssue($issue);
                }
            }
        );

        return $analyzer;
    }

    /**
     * @param list<AnalyzerInterface> $analyzers
     */
    private function createCommandTester(array $analyzers): CommandTester
    {
        $reporters = [new ConsoleReporter()];

        $command = new FullAuditCommand(
            $analyzers,
            $reporters,
            $this->tempDir,
            new NullParameterResolver(),
        );

        $application = new Application('SF Doctor', 'test');
        $application->add($command);

        return new CommandTester($application->find('sf-doctor:full-audit'));
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
