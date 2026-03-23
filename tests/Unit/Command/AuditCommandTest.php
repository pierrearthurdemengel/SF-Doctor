<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use Symfony\Component\Console\Application;
use PierreArthur\SfDoctor\Model\AuditReport;
use Symfony\Component\Console\Command\Command;
use PierreArthur\SfDoctor\Command\AuditCommand;
use PierreArthur\SfDoctor\Report\ReporterInterface;
use Symfony\Component\Console\Tester\CommandTester;
use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\NullParameterResolver;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


final class AuditCommandTest extends TestCase
{
    private const PROJECT_PATH = '/tmp/fake-project';

    // ---------------------------------------------------------------
    // 1. Commande sans issues → SUCCESS
    // ---------------------------------------------------------------
    public function testAuditWithNoIssuesReturnsSuccess(): void
    {
        // Analyzer qui ne remonte rien (projet propre)
        $analyzer = $this->createAnalyzer(Module::SECURITY, []);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Audit', $tester->getDisplay());
    }

    // ---------------------------------------------------------------
    // 2. Un CRITICAL → FAILURE (exit code 1)
    // ---------------------------------------------------------------
    public function testAuditWithCriticalIssueReturnsFailure(): void
    {
        $criticalIssue = new Issue(
            severity: Severity::CRITICAL,
            module: Module::SECURITY,
            analyzer: 'TestAnalyzer',
            message: 'Firewall has no access control',
            detail: 'Critical security issue detected.',
            suggestion: 'Add access_control rules.',
        );

        $analyzer = $this->createAnalyzer(Module::SECURITY, [$criticalIssue]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    // ---------------------------------------------------------------
    // 3. --security filtre uniquement le module SECURITY
    // ---------------------------------------------------------------
    public function testSecurityOptionFiltersModules(): void
    {
        // Un analyzer SECURITY qui ne remonte rien
        $securityAnalyzer = $this->createAnalyzer(Module::SECURITY, []);

        // Un analyzer ARCHITECTURE qui remonterait un CRITICAL
        // Si le filtre fonctionne, cet analyzer ne sera PAS exécuté.
        $architectureAnalyzer = $this->createAnalyzer(Module::ARCHITECTURE, [
            new Issue(
                severity: Severity::CRITICAL,
                module: Module::ARCHITECTURE,
                analyzer: 'ArchAnalyzer',
                message: 'Should not appear',
                detail: 'This analyzer should be skipped.',
                suggestion: 'N/A',
            ),
        ]);

        // Le mock ARCHITECTURE ne doit JAMAIS être appelé
        $architectureAnalyzer->expects($this->never())->method('analyze');

        $tester = $this->createCommandTester([$securityAnalyzer, $architectureAnalyzer]);
        $tester->execute(['--security' => true]);

        // Pas de CRITICAL car l'analyzer ARCHITECTURE a été ignoré
        $tester->assertCommandIsSuccessful();
    }

    // ---------------------------------------------------------------
    // 4. Un analyzer avec supports() = false est ignoré
    // ---------------------------------------------------------------
    public function testAnalyzerWithoutSupportIsSkipped(): void
    {
        $unsupported = $this->createMock(AnalyzerInterface::class);
        $unsupported->method('getModule')->willReturn(Module::SECURITY);
        $unsupported->method('getName')->willReturn('Unsupported Analyzer');
        $unsupported->method('supports')->willReturn(false);

        // analyze() ne doit JAMAIS être appelé
        $unsupported->expects($this->never())->method('analyze');

        $tester = $this->createCommandTester([$unsupported]);
        $tester->execute(['--security' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('ignoré', $tester->getDisplay());
    }

    // ---------------------------------------------------------------
    // 5. Format inconnu → FAILURE + message d'erreur
    // ---------------------------------------------------------------
    public function testUnknownFormatReturnsFailure(): void
    {
        $tester = $this->createCommandTester([]);
        $tester->execute(['--format' => 'xml']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('inconnu', $tester->getDisplay());
    }

    // ---------------------------------------------------------------
    // 6. Le fallback ConsoleReporter fonctionne sans reporter injecté
    // ---------------------------------------------------------------
    public function testFallbackConsoleReporterIsUsed(): void
    {
        $warningIssue = new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: 'TestAnalyzer',
            message: 'Minor issue detected',
            detail: 'Not critical.',
            suggestion: 'Consider fixing.',
        );

        $analyzer = $this->createAnalyzer(Module::SECURITY, [$warningIssue]);

        // Aucun reporter injecté → le fallback console doit prendre le relais
        $tester = $this->createCommandTester([$analyzer], []);
        $tester->execute([]);

        // La commande réussit (WARNING ne bloque pas)
        $tester->assertCommandIsSuccessful();

        // Le ConsoleReporter a bien affiché quelque chose
        $this->assertStringContainsString('Score', $tester->getDisplay());
    }

    // ---------------------------------------------------------------
    // 7. Warnings seuls → SUCCESS (seul CRITICAL bloque)
    // ---------------------------------------------------------------
    public function testWarningsDoNotCauseFailure(): void
    {
        $warning = new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: 'TestAnalyzer',
            message: 'Deprecated role detected',
            detail: 'ROLE_SUPER_ADMIN is deprecated.',
            suggestion: 'Use a custom role hierarchy.',
        );

        $analyzer = $this->createAnalyzer(Module::SECURITY, [$warning]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
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

        // Quand analyze() est appelé, on ajoute les issues au rapport
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
     * Builds a CommandTester for AuditCommand with given analyzers and reporters.
     *
     * @param list<AnalyzerInterface> $analyzers
     * @param list<ReporterInterface> $reporters
     */
    private function createCommandTester(array $analyzers, array $reporters = []): CommandTester
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        // Le dispatcher peut être appelé plusieurs fois, on ne vérifie pas les appels ici.
        // Les tests d'intégration couvriront le dispatch réel.
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $command = new AuditCommand(
            $analyzers,
            $reporters,
            self::PROJECT_PATH,
            new NullParameterResolver(),
            $dispatcher,
        );

        $application = new Application('SF Doctor', 'test');
        $application->add($command);

        return new CommandTester($application->find('sf-doctor:audit'));
    }
}