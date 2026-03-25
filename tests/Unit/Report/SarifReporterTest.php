<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Report\SarifReporter;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests du reporter SARIF pour GitHub Code Scanning.
 */
class SarifReporterTest extends TestCase
{
    private SarifReporter $reporter;

    protected function setUp(): void
    {
        $this->reporter = new SarifReporter();
    }

    public function testGetFormatReturnsSarif(): void
    {
        $this->assertSame('sarif', $this->reporter->getFormat());
    }

    public function testGenerateOutputsValidJson(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $output = new BufferedOutput();

        $this->reporter->generate($report, $output);
        $json = json_decode($output->fetch(), true);

        $this->assertIsArray($json);
    }

    public function testSarifContainsSchemaAndVersion(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $output = new BufferedOutput();

        $this->reporter->generate($report, $output);
        $json = json_decode($output->fetch(), true);

        $this->assertSame('2.1.0', $json['version']);
        $this->assertStringContainsString('sarif-schema', $json['$schema']);
    }

    public function testSarifContainsToolInfo(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $output = new BufferedOutput();

        $this->reporter->generate($report, $output);
        $json = json_decode($output->fetch(), true);

        $driver = $json['runs'][0]['tool']['driver'];
        $this->assertSame('SF-Doctor', $driver['name']);
    }

    public function testIssuesAreMappedToResults(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::SECURITY,
            analyzer: 'Test Analyzer',
            message: 'Issue critique',
            detail: 'Detail de la critique',
            suggestion: 'Corriger cela',
            file: 'src/test.php',
            line: 42,
        ));

        $output = new BufferedOutput();
        $this->reporter->generate($report, $output);
        $json = json_decode($output->fetch(), true);

        $results = $json['runs'][0]['results'];
        $this->assertCount(1, $results);
        $this->assertSame('error', $results[0]['level']);
        $this->assertSame('Detail de la critique', $results[0]['message']['text']);

        // Verifie la localisation du fichier.
        $location = $results[0]['locations'][0]['physicalLocation'];
        $this->assertSame('src/test.php', $location['artifactLocation']['uri']);
        $this->assertSame(42, $location['region']['startLine']);
    }

    public function testSeverityMappingCriticalToError(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(Severity::CRITICAL));

        $json = $this->generateSarif($report);

        $this->assertSame('error', $json['runs'][0]['results'][0]['level']);
    }

    public function testSeverityMappingWarningToWarning(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(Severity::WARNING));

        $json = $this->generateSarif($report);

        $this->assertSame('warning', $json['runs'][0]['results'][0]['level']);
    }

    public function testSeverityMappingSuggestionToNote(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(Severity::SUGGESTION));

        $json = $this->generateSarif($report);

        $this->assertSame('note', $json['runs'][0]['results'][0]['level']);
    }

    public function testSeverityMappingOkToNone(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(Severity::OK));

        $json = $this->generateSarif($report);

        $this->assertSame('none', $json['runs'][0]['results'][0]['level']);
    }

    public function testFixesIncludedWhenSuggestionPresent(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: 'Test',
            message: 'Issue',
            detail: 'Detail',
            suggestion: 'Fix this way',
        ));

        $json = $this->generateSarif($report);

        $result = $json['runs'][0]['results'][0];
        $this->assertArrayHasKey('fixes', $result);
        $this->assertSame('Fix this way', $result['fixes'][0]['description']['text']);
    }

    public function testRulesAreDeduplicated(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);

        // Deux issues du meme analyzer/module -> une seule regle.
        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: 'Same Analyzer',
            message: 'Issue 1',
            detail: 'D1',
            suggestion: '',
        ));
        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: 'Same Analyzer',
            message: 'Issue 2',
            detail: 'D2',
            suggestion: '',
        ));

        $json = $this->generateSarif($report);

        $rules = $json['runs'][0]['tool']['driver']['rules'];
        // Les deux issues ont le meme analyzer, donc un seul ruleId.
        $this->assertCount(1, $rules);
    }

    /**
     * @return array<string, mixed>
     */
    private function generateSarif(AuditReport $report): array
    {
        $output = new BufferedOutput();
        $this->reporter->generate($report, $output);

        return json_decode($output->fetch(), true);
    }

    private function createIssue(Severity $severity): Issue
    {
        return new Issue(
            severity: $severity,
            module: Module::SECURITY,
            analyzer: 'Test Analyzer',
            message: 'Test issue',
            detail: 'Detail',
            suggestion: '',
        );
    }
}
