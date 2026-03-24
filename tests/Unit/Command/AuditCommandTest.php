<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use Symfony\Component\Console\Application;
use PierreArthur\SfDoctor\Cache\ResultCache;
use PierreArthur\SfDoctor\Model\AuditReport;
use Symfony\Component\Console\Command\Command;
use PierreArthur\SfDoctor\Command\AuditCommand;
use PierreArthur\SfDoctor\Report\ReporterInterface;
use Symfony\Component\Console\Tester\CommandTester;
use PierreArthur\SfDoctor\Cache\ResultCacheInterface;
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
    // 6. Sans reporter injecte, la commande retourne FAILURE avec message d'erreur
    // ---------------------------------------------------------------
    public function testUnknownFormatReturnsFailureWithMessage(): void
    {
        $analyzer = $this->createAnalyzer(Module::SECURITY, []);
        // Aucun reporter injecte - format inconnu
        $tester = $this->createCommandTester([$analyzer], []);
        $tester->execute(['--format' => 'unknown']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('inconnu', $tester->getDisplay());
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

// ---------------------------------------------------------------
    // 8. Workflow : statut "completed" affiché après une analyse réussie
    // ---------------------------------------------------------------
    public function testWorkflowStatusCompletedAppearsOnSuccess(): void
    {
        $analyzer = $this->createAnalyzer(Module::SECURITY, []);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute([]);

        $this->assertStringContainsString('completed', $tester->getDisplay());
    }

    // ---------------------------------------------------------------
    // 9. Workflow : FAILURE si un analyzer lève une exception
    // ---------------------------------------------------------------
    public function testWorkflowTransitionsToFailedOnException(): void
    {
        $analyzer = $this->createMock(AnalyzerInterface::class);
        $analyzer->method('getModule')->willReturn(Module::SECURITY);
        $analyzer->method('getName')->willReturn('Crashing Analyzer');
        $analyzer->method('supports')->willReturn(true);
        $analyzer->method('analyze')->willThrowException(
            new \RuntimeException('Erreur inattendue dans l\'analyzer.')
        );

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute([], ['capture_stderr_separately' => false]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Erreur inattendue', $tester->getDisplay());
    }

    // ---------------------------------------------------------------
    // 10. --async avec bus : les issues du rapport partiel sont fusionnées
    // ---------------------------------------------------------------
    public function testAsyncMergesIssuesFromPartialReports(): void
    {
        $issue = new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: 'TestAnalyzer',
            message: 'Async issue',
            detail: 'Detected asynchronously.',
            suggestion: 'Fix it.',
        );

        $partialReport = new AuditReport('/tmp/fake-project', [Module::SECURITY]);
        $partialReport->addIssue($issue);

        $stamp    = new \Symfony\Component\Messenger\Stamp\HandledStamp($partialReport, 'handler');
        $envelope = new \Symfony\Component\Messenger\Envelope(
            new \PierreArthur\SfDoctor\Message\RunAnalyzerMessage('SomeAnalyzer', '/tmp', []),
            [$stamp],
        );

        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->method('dispatch')->willReturn($envelope);

        $analyzer = $this->createAnalyzer(Module::SECURITY, []);

        $tester = $this->createCommandTester([$analyzer], null, $bus);
        $tester->execute(['--async' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('completed', $tester->getDisplay());
    }

    // ---------------------------------------------------------------
    // 11. --async sans bus : warning affiché, analyse en mode sync
    // ---------------------------------------------------------------
    public function testAsyncWithoutBusShowsWarning(): void
    {
        $analyzer = $this->createAnalyzer(Module::SECURITY, []);

        $tester = $this->createCommandTester([$analyzer], null, null);
        $tester->execute(['--async' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('ignorée', $tester->getDisplay());
    }

    // ---------------------------------------------------------------
    // 12. --brief : les champs d'enrichissement ne sont pas affichés
    // ---------------------------------------------------------------
    public function testBriefOptionHidesEnrichmentFields(): void
    {
        $issue = new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: 'TestAnalyzer',
            message: 'Firewall sans authentification',
            detail: 'Aucun authenticator configuré.',
            suggestion: 'Ajouter form_login.',
            businessImpact: 'Impact confidentiel qui ne doit pas apparaître.',
            fixCode: 'fix_code_qui_ne_doit_pas_apparaitre',
            docUrl: 'https://symfony.com/secret',
            estimatedFixMinutes: 99,
        );

        $analyzer = $this->createAnalyzer(Module::SECURITY, [$issue]);

        $tester = $this->createCommandTester([$analyzer]);
        $tester->execute(['--brief' => true]);

        $display = $tester->getDisplay();

        // Le message principal reste visible
        $this->assertStringContainsString('Firewall sans authentification', $display);

        // Les champs d'enrichissement sont absents
        $this->assertStringNotContainsString('Impact confidentiel', $display);
        $this->assertStringNotContainsString('fix_code_qui_ne_doit_pas_apparaitre', $display);
        $this->assertStringNotContainsString('https://symfony.com/secret', $display);
        $this->assertStringNotContainsString('99', $display);
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
     * @param list<AnalyzerInterface> $analyzers
     * @param list<ReporterInterface>|null $reporters
     */
    private function createCommandTester(
        array $analyzers,
        ?array $reporters = null,
        ?\Symfony\Component\Messenger\MessageBusInterface $bus = null,
    ): CommandTester {
        if ($reporters === null) {
            $reporters = [new \PierreArthur\SfDoctor\Report\ConsoleReporter()];
        }

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $cache = $this->createMock(ResultCacheInterface::class);
        $cache->method('computeHash')->willReturn('fake-hash');
        $cache->method('load')->willReturn(null);

        $command = new AuditCommand(
            $analyzers,
            $reporters,
            self::PROJECT_PATH,
            new NullParameterResolver(),
            $dispatcher,
            $cache,
            $bus,
        );

        $application = new Application('SF Doctor', 'test');
        $application->add($command);

        return new CommandTester($application->find('sf-doctor:audit'));
    }
}