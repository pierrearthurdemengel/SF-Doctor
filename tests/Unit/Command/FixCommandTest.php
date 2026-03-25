<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Command\FixCommand;
use PierreArthur\SfDoctor\Config\NullParameterResolver;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class FixCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_fix_' . uniqid();
        mkdir($this->tempDir, 0777, true);

        // Minimal composer.json so ProjectContextDetector works
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode(['require' => ['php' => '>=8.1']]),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    // ---------------------------------------------------------------
    // 1. No fixable issues -> SUCCESS + "Aucune correction"
    // ---------------------------------------------------------------
    public function testNoFixableIssuesReturnsSuccessWithMessage(): void
    {
        // Analyzer that returns an issue WITHOUT fixCode/file
        $analyzer = $this->createAnalyzer(Module::SECURITY, [
            new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Issue without fix',
                detail: 'No fixCode provided.',
                suggestion: 'Fix manually.',
            ),
        ]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Aucune correction automatique disponible', $tester->getDisplay());
    }

    // ---------------------------------------------------------------
    // 2. --dry-run shows fixes but doesn't create fix-plan.json
    // ---------------------------------------------------------------
    public function testDryRunShowsFixesWithoutCreatingPlan(): void
    {
        $analyzer = $this->createAnalyzerWithFixableIssue(Severity::CRITICAL);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();

        // Shows the fix info
        $this->assertStringContainsString('Fix 1/1', $display);
        $this->assertStringContainsString('dry-run', $display);
        $this->assertStringContainsString('Corrections acceptees', $display);

        // Fix plan is NOT created in dry-run mode
        $this->assertFileDoesNotExist($this->tempDir . '/.sf-doctor/fix-plan.json');
    }

    // ---------------------------------------------------------------
    // 3. --auto accepts all fixes, creates fix-plan.json
    // ---------------------------------------------------------------
    public function testAutoAcceptsAllFixesAndCreatesPlan(): void
    {
        $analyzer = $this->createAnalyzerWithFixableIssue(Severity::CRITICAL);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute(['--auto' => true]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();

        $this->assertStringContainsString('Accepte (mode auto)', $display);
        $this->assertStringContainsString('1 corrections dans le plan', $display);

        // Fix plan file is created
        $planPath = $this->tempDir . '/.sf-doctor/fix-plan.json';
        $this->assertFileExists($planPath);

        // Plan is valid JSON
        $plan = json_decode(file_get_contents($planPath), true);
        $this->assertIsArray($plan);
        $this->assertArrayHasKey('fixes', $plan);
        $this->assertCount(1, $plan['fixes']);
        $this->assertSame('critical', $plan['fixes'][0]['severity']);
    }

    // ---------------------------------------------------------------
    // 4. Interactive: user confirms "oui" -> accepted
    // ---------------------------------------------------------------
    public function testInteractiveUserConfirmsOui(): void
    {
        $analyzer = $this->createAnalyzerWithFixableIssue(Severity::WARNING);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->setInputs(['oui']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();

        $this->assertStringContainsString('Accepte', $display);
        $this->assertStringContainsString('Corrections acceptees : ', $display);

        // Plan is created
        $this->assertFileExists($this->tempDir . '/.sf-doctor/fix-plan.json');
    }

    // ---------------------------------------------------------------
    // 5. Interactive: user says "non" -> skipped
    // ---------------------------------------------------------------
    public function testInteractiveUserSaysNon(): void
    {
        $analyzer = $this->createAnalyzerWithFixableIssue(Severity::WARNING);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->setInputs(['non']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();

        $this->assertStringContainsString('Ignore', $display);
        $this->assertStringContainsString('Corrections ignorees', $display);

        // No plan created since nothing was accepted
        $this->assertFileDoesNotExist($this->tempDir . '/.sf-doctor/fix-plan.json');
    }

    // ---------------------------------------------------------------
    // 6. Interactive: user says "quitter" -> stops
    // ---------------------------------------------------------------
    public function testInteractiveUserSaysQuitter(): void
    {
        // Two fixable issues
        $analyzer = $this->createAnalyzer(Module::SECURITY, [
            new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'First fixable issue',
                detail: 'Detail.',
                suggestion: 'Fix it.',
                file: 'config/packages/security.yaml',
                fixCode: 'access_control: []',
            ),
            new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Second fixable issue',
                detail: 'Detail.',
                suggestion: 'Fix it too.',
                file: 'config/packages/framework.yaml',
                fixCode: 'csrf_protection: true',
            ),
        ]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->setInputs(['quitter']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();

        $this->assertStringContainsString('Arret demande', $display);
        // Second issue should NOT be displayed as a fix section
        $this->assertStringNotContainsString('Fix 2/2', $display);
    }

    // ---------------------------------------------------------------
    // 7. Only CRITICAL/WARNING with fixCode+file are proposed
    // ---------------------------------------------------------------
    public function testOnlyCriticalAndWarningWithFixCodeAndFileAreProposed(): void
    {
        $analyzer = $this->createAnalyzer(Module::SECURITY, [
            // CRITICAL with fixCode+file -> proposed
            new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Fixable critical',
                detail: 'Detail.',
                suggestion: 'Fix.',
                file: 'config/security.yaml',
                fixCode: 'fix_code_here',
            ),
            // WARNING without fixCode -> NOT proposed
            new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Warning without fixCode',
                detail: 'Detail.',
                suggestion: 'Manual fix.',
                file: 'config/security.yaml',
            ),
            // SUGGESTION with fixCode+file -> NOT proposed (wrong severity)
            new Issue(
                severity: Severity::SUGGESTION,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Suggestion with fixCode',
                detail: 'Detail.',
                suggestion: 'Optional.',
                file: 'config/security.yaml',
                fixCode: 'some_fix',
            ),
            // CRITICAL without file -> NOT proposed
            new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Critical without file',
                detail: 'Detail.',
                suggestion: 'Fix.',
                fixCode: 'fix_code',
            ),
            // OK with fixCode+file -> NOT proposed
            new Issue(
                severity: Severity::OK,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'OK with fixCode',
                detail: 'Detail.',
                suggestion: 'Nothing.',
                file: 'config/security.yaml',
                fixCode: 'ok_fix',
            ),
        ]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute(['--dry-run' => true]);

        $display = $tester->getDisplay();

        // Only 1 fixable issue
        $this->assertStringContainsString('1 corrections disponibles', $display);
        $this->assertStringContainsString('Fixable critical', $display);

        // Others should NOT appear in fix sections
        $this->assertStringNotContainsString('Warning without fixCode', $display);
        $this->assertStringNotContainsString('Suggestion with fixCode', $display);
        $this->assertStringNotContainsString('Critical without file', $display);
        $this->assertStringNotContainsString('OK with fixCode', $display);
    }

    // ---------------------------------------------------------------
    // 8. CRITICAL issues appear before WARNING
    // ---------------------------------------------------------------
    public function testCriticalIssuesAppearBeforeWarning(): void
    {
        $analyzer = $this->createAnalyzer(Module::SECURITY, [
            // WARNING first in insertion order
            new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Warning issue first',
                detail: 'Detail.',
                suggestion: 'Fix.',
                file: 'config/security.yaml',
                fixCode: 'warning_fix',
            ),
            // CRITICAL second in insertion order
            new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Critical issue second',
                detail: 'Detail.',
                suggestion: 'Fix.',
                file: 'config/security.yaml',
                fixCode: 'critical_fix',
            ),
        ]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute(['--dry-run' => true]);

        $display = $tester->getDisplay();

        // CRITICAL should appear as Fix 1, WARNING as Fix 2
        $criticalPos = strpos($display, 'CRITICAL');
        $warningPos = strpos($display, 'WARNING');

        $this->assertNotFalse($criticalPos);
        $this->assertNotFalse($warningPos);
        $this->assertLessThan($warningPos, $criticalPos, 'CRITICAL should appear before WARNING');
    }

    // ---------------------------------------------------------------
    // 9. Summary shows correct counts
    // ---------------------------------------------------------------
    public function testSummaryShowsCorrectCounts(): void
    {
        $analyzer = $this->createAnalyzer(Module::SECURITY, [
            new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Issue to accept',
                detail: 'Detail.',
                suggestion: 'Fix.',
                file: 'config/security.yaml',
                fixCode: 'fix_1',
            ),
            new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Issue to skip',
                detail: 'Detail.',
                suggestion: 'Fix.',
                file: 'config/framework.yaml',
                fixCode: 'fix_2',
            ),
        ]);

        $tester = $this->createCommandTester([$analyzer]);
        // Accept first, skip second
        $tester->setInputs(['oui', 'non']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();

        // 1 accepted, 1 skipped
        $this->assertMatchesRegularExpression('/Corrections acceptees\s*:\s*\S*1/', $display);
        $this->assertMatchesRegularExpression('/Corrections ignorees\s*:\s*\S*1/', $display);
    }

    // ---------------------------------------------------------------
    // 10. Fix plan JSON is valid and contains expected structure
    // ---------------------------------------------------------------
    public function testFixPlanJsonIsValid(): void
    {
        $analyzer = $this->createAnalyzer(Module::SECURITY, [
            new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: 'SecurityAnalyzer',
                message: 'Missing access control',
                detail: 'No access_control rules defined.',
                suggestion: 'Add access_control rules.',
                file: 'config/packages/security.yaml',
                fixCode: "access_control:\n    - { path: ^/admin, roles: ROLE_ADMIN }",
                estimatedFixMinutes: 5,
            ),
        ]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute(['--auto' => true]);

        $planPath = $this->tempDir . '/.sf-doctor/fix-plan.json';
        $this->assertFileExists($planPath);

        $content = file_get_contents($planPath);
        $plan = json_decode($content, true);

        $this->assertIsArray($plan);
        $this->assertArrayHasKey('generated_at', $plan);
        $this->assertArrayHasKey('project_path', $plan);
        $this->assertArrayHasKey('fixes', $plan);

        $this->assertSame($this->tempDir, $plan['project_path']);
        $this->assertCount(1, $plan['fixes']);

        $fix = $plan['fixes'][0];
        $this->assertSame('critical', $fix['severity']);
        $this->assertSame('security', $fix['module']);
        $this->assertSame('SecurityAnalyzer', $fix['analyzer']);
        $this->assertSame('Missing access control', $fix['message']);
        $this->assertSame('config/packages/security.yaml', $fix['file']);
        $this->assertSame("access_control:\n    - { path: ^/admin, roles: ROLE_ADMIN }", $fix['fixCode']);
        $this->assertSame(5, $fix['estimatedFixMinutes']);
    }

    // ---------------------------------------------------------------
    // 11. Analyzer with supports() = false is skipped
    // ---------------------------------------------------------------
    public function testAnalyzerWithoutSupportIsSkipped(): void
    {
        $unsupported = $this->createMock(AnalyzerInterface::class);
        $unsupported->method('getModule')->willReturn(Module::SECURITY);
        $unsupported->method('getName')->willReturn('Unsupported Analyzer');
        $unsupported->method('supports')->willReturn(false);
        $unsupported->expects($this->never())->method('analyze');

        $tester = $this->createCommandTester([$unsupported]);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Aucune correction automatique disponible', $tester->getDisplay());
    }

    // ---------------------------------------------------------------
    // 12. --auto with multiple issues accepts all
    // ---------------------------------------------------------------
    public function testAutoWithMultipleIssuesAcceptsAll(): void
    {
        $analyzer = $this->createAnalyzer(Module::SECURITY, [
            new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'First issue',
                detail: 'Detail.',
                suggestion: 'Fix.',
                file: 'config/security.yaml',
                fixCode: 'fix_1',
            ),
            new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Second issue',
                detail: 'Detail.',
                suggestion: 'Fix.',
                file: 'config/framework.yaml',
                fixCode: 'fix_2',
            ),
        ]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute(['--auto' => true]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();

        $this->assertStringContainsString('2 corrections dans le plan', $display);

        $planPath = $this->tempDir . '/.sf-doctor/fix-plan.json';
        $plan = json_decode(file_get_contents($planPath), true);
        $this->assertCount(2, $plan['fixes']);
    }

    // ---------------------------------------------------------------
    // 13. Display shows module, file, analyzer, suggestion, fixCode
    // ---------------------------------------------------------------
    public function testDisplayShowsAllIssueDetails(): void
    {
        $analyzer = $this->createAnalyzer(Module::ARCHITECTURE, [
            new Issue(
                severity: Severity::WARNING,
                module: Module::ARCHITECTURE,
                analyzer: 'HeavyConstructorAnalyzer',
                message: 'Constructeur trop lourd',
                detail: 'Detail.',
                suggestion: 'Utiliser un service lazy.',
                file: 'src/Service/MyService.php',
                fixCode: 'private readonly LazyService $service',
            ),
        ]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute(['--dry-run' => true]);

        $display = $tester->getDisplay();

        $this->assertStringContainsString('architecture', $display);
        $this->assertStringContainsString('src/Service/MyService.php', $display);
        $this->assertStringContainsString('HeavyConstructorAnalyzer', $display);
        $this->assertStringContainsString('Utiliser un service lazy', $display);
        $this->assertStringContainsString('private readonly LazyService $service', $display);
    }

    // ---------------------------------------------------------------
    // 14. Plan path is shown when fixes are accepted
    // ---------------------------------------------------------------
    public function testPlanPathIsShownWhenFixesAccepted(): void
    {
        $analyzer = $this->createAnalyzerWithFixableIssue(Severity::CRITICAL);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute(['--auto' => true]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('.sf-doctor/fix-plan.json', $display);
        $this->assertStringContainsString('Plan de correction sauvegarde', $display);
    }

    // ===============================================================
    // HELPERS
    // ===============================================================

    /**
     * Creates a mock analyzer that adds the given issues to the report.
     *
     * @param list<Issue> $issues Issues to inject during analyze()
     */
    private function createAnalyzer(Module $module, array $issues): AnalyzerInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $analyzer = $this->createMock(AnalyzerInterface::class);
        $analyzer->method('getModule')->willReturn($module);
        $analyzer->method('getName')->willReturn($module->value . ' Test Analyzer');
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
     * Creates an analyzer with a single fixable issue (has fixCode + file).
     */
    private function createAnalyzerWithFixableIssue(Severity $severity): AnalyzerInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        return $this->createAnalyzer(Module::SECURITY, [
            new Issue(
                severity: $severity,
                module: Module::SECURITY,
                analyzer: 'TestAnalyzer',
                message: 'Fixable issue',
                detail: 'This issue has a fix.',
                suggestion: 'Apply the fix.',
                file: 'config/packages/security.yaml',
                fixCode: "access_control:\n    - { path: ^/admin, roles: ROLE_ADMIN }",
                estimatedFixMinutes: 5,
            ),
        ]);
    }

    /**
     * @param list<AnalyzerInterface> $analyzers
     */
    private function createCommandTester(array $analyzers): CommandTester
    {
        $command = new FixCommand(
            $analyzers,
            $this->tempDir,
            new NullParameterResolver(),
        );

        $application = new Application('SF Doctor', 'test');
        $application->add($command);

        return new CommandTester($application->find('sf-doctor:fix'));
    }

    /**
     * Recursively removes a directory and all its contents.
     */
    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . '/' . $item;
            if (is_dir($fullPath)) {
                $this->removeDirectory($fullPath);
            } else {
                unlink($fullPath);
            }
        }

        rmdir($path);
    }
}
