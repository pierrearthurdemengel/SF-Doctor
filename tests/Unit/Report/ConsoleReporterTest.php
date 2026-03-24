<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Report\ConsoleReporter;
use Symfony\Component\Console\Output\BufferedOutput;

class ConsoleReporterTest extends TestCase
{
    private function createReporterAndOutput(): array
    {
        $output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        $reporter = new ConsoleReporter();

        return [$reporter, $output];
    }

    private function createIssue(
        Severity $severity = Severity::WARNING,
        Module $module = Module::SECURITY,
        string $analyzer = 'TestAnalyzer',
        string $message = 'Test message',
        ?string $file = null,
        ?int $line = null,
        ?string $fixCode = null,
        ?string $docUrl = null,
        ?string $businessImpact = null,
        ?int $estimatedFixMinutes = null,
    ): Issue {
        return new Issue(
            severity: $severity,
            module: $module,
            analyzer: $analyzer,
            message: $message,
            detail: 'Test detail',
            suggestion: 'Test suggestion',
            file: $file,
            line: $line,
            fixCode: $fixCode,
            docUrl: $docUrl,
            businessImpact: $businessImpact,
            estimatedFixMinutes: $estimatedFixMinutes,
        );
    }

    // =============================================
    // Test du format
    // =============================================

    public function testGetFormatReturnsConsole(): void
    {
        [$reporter] = $this->createReporterAndOutput();

        $this->assertSame('console', $reporter->getFormat());
    }

    // =============================================
    // Test de l'en-tête
    // =============================================

    public function testOutputContainsTitle(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake/project', [Module::SECURITY]);
        $reporter->generate($report, $output);

        $content = $output->fetch();

        $this->assertStringContainsString('SF Doctor', $content);
    }

    public function testOutputContainsProjectPath(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/home/pierre/mon-projet', [Module::SECURITY]);
        $reporter->generate($report, $output);

        $content = $output->fetch();

        $this->assertStringContainsString('/home/pierre/mon-projet', $content);
    }

    public function testOutputContainsModuleNames(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY, Module::ARCHITECTURE]);
        $reporter->generate($report, $output);

        $content = $output->fetch();

        $this->assertStringContainsString('security', $content);
        $this->assertStringContainsString('architecture', $content);
    }

    // =============================================
    // Test des issues dans le rapport
    // =============================================

    public function testOutputContainsIssueMessage(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(
            message: 'Firewall sans authentification',
        ));
        $reporter->generate($report, $output);

        $content = $output->fetch();

        $this->assertStringContainsString('Firewall sans authentification', $content);
    }

    public function testOutputContainsAnalyzerName(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(
            analyzer: 'Firewall Analyzer',
        ));
        $reporter->generate($report, $output);

        $content = $output->fetch();

        $this->assertStringContainsString('Firewall Analyzer', $content);
    }

    public function testOutputContainsSuggestion(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: 'TestAnalyzer',
            message: 'Problème détecté',
            detail: 'Détail du problème',
            suggestion: 'Voici comment corriger',
        ));
        $reporter->generate($report, $output);

        $content = $output->fetch();

        $this->assertStringContainsString('Voici comment corriger', $content);
    }

    public function testOutputContainsFileLocation(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(
            file: 'config/packages/security.yaml',
            line: 42,
        ));
        $reporter->generate($report, $output);

        $content = $output->fetch();

        $this->assertStringContainsString('security.yaml:42', $content);
    }

    public function testOutputShowsDashWhenNoFile(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue());
        $reporter->generate($report, $output);

        $content = $output->fetch();

        $this->assertStringContainsString('-', $content);
    }

    // =============================================
    // Test du score
    // =============================================

    public function testPerfectScoreShowsSuccess(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $reporter->generate($report, $output);

        $content = $output->fetch();

        $this->assertStringContainsString('100/100', $content);
    }

    public function testLowScoreShowsError(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);

        for ($i = 0; $i < 6; $i++) {
            $report->addIssue($this->createIssue(Severity::CRITICAL));
        }

        $reporter->generate($report, $output);
        $content = $output->fetch();

        $this->assertStringContainsString('40/100', $content);
    }

    public function testMediumScoreShowsWarning(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);

        for ($i = 0; $i < 4; $i++) {
            $report->addIssue($this->createIssue(Severity::CRITICAL));
        }

        $reporter->generate($report, $output);
        $content = $output->fetch();

        $this->assertStringContainsString('60/100', $content);
    }

    // =============================================
    // Test du rapport vide
    // =============================================

    public function testEmptyModuleShowsSuccessMessage(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $reporter->generate($report, $output);

        $content = $output->fetch();

        $this->assertStringContainsString('Aucun problème', $content);
    }

    // =============================================
    // Test de l'issue count
    // =============================================

    public function testOutputContainsIssueCount(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue());
        $report->addIssue($this->createIssue());
        $report->addIssue($this->createIssue());
        $reporter->generate($report, $output);

        $content = $output->fetch();

        $this->assertStringContainsString('3', $content);
    }

    // =============================================
    // Test des sévérités dans le tableau
    // =============================================

    public function testCriticalSeverityDisplayed(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(Severity::CRITICAL));
        $reporter->generate($report, $output);

        $content = $output->fetch();

        $this->assertStringContainsString('CRITICAL', $content);
    }

    public function testOkIssuesAreDisplayed(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(Severity::OK));
        $reporter->generate($report, $output);

        $content = $output->fetch();

        $this->assertStringContainsString('OK', $content);
    }

    public function testOkIssuesSuggestionNotShown(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue(new Issue(
            severity: Severity::OK,
            module: Module::SECURITY,
            analyzer: 'TestAnalyzer',
            message: 'Check passé',
            detail: 'Tout va bien',
            suggestion: 'Suggestion invisible',
        ));
        $reporter->generate($report, $output);

        $content = $output->fetch();

        $this->assertStringNotContainsString('Suggestion invisible', $content);
    }

    // =============================================
    // Test des champs d'enrichissement
    // =============================================

    public function testBusinessImpactIsDisplayed(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(
            businessImpact: 'Un attaquant peut accéder aux données clients.',
        ));
        $reporter->generate($report, $output);

        $this->assertStringContainsString(
            'Un attaquant peut accéder aux données clients.',
            $output->fetch(),
        );
    }

    public function testFixCodeIsDisplayed(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(
            fixCode: 'form_login: ~',
        ));
        $reporter->generate($report, $output);

        $this->assertStringContainsString('form_login: ~', $output->fetch());
    }

    public function testDocUrlIsDisplayed(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(
            docUrl: 'https://symfony.com/doc/current/security.html',
        ));
        $reporter->generate($report, $output);

        $this->assertStringContainsString(
            'https://symfony.com/doc/current/security.html',
            $output->fetch(),
        );
    }

    public function testEnrichmentFieldsNotDisplayedWhenNull(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue());
        $reporter->generate($report, $output);

        $content = $output->fetch();

        $this->assertStringNotContainsString('Impact :', $content);
        $this->assertStringNotContainsString('Fix :', $content);
        $this->assertStringNotContainsString('Documentation :', $content);
        $this->assertStringNotContainsString('Temps estimé :', $content);
    }

    public function testTotalEstimatedTimeIsDisplayed(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(estimatedFixMinutes: 15));
        $report->addIssue($this->createIssue(estimatedFixMinutes: 30));
        $reporter->generate($report, $output);

        // 15 + 30 = 45 minutes au total
        $this->assertStringContainsString('45', $output->fetch());
    }

    public function testTotalEstimatedTimeNotDisplayedWhenAllNull(): void
    {
        [$reporter, $output] = $this->createReporterAndOutput();

        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue());
        $reporter->generate($report, $output);

        $this->assertStringNotContainsString('Temps total', $output->fetch());
    }
}