<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Report\JsonReporter;
use PierreArthur\SfDoctor\Serializer\AuditReportNormalizer;
use PierreArthur\SfDoctor\Serializer\IssueNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Console\Output\BufferedOutput;

final class JsonReporterTest extends TestCase
{
    private BufferedOutput $output;
    private JsonReporter $reporter;

    protected function setUp(): void
    {
        $this->output = new BufferedOutput();

        // Construction du Serializer avec les deux normalizers.
        // IssueNormalizer est injecte dans AuditReportNormalizer
        // via le Serializer central (NormalizerAwareTrait).
        $issueNormalizer = new IssueNormalizer();
        $auditReportNormalizer = new AuditReportNormalizer();
        $serializer = new Serializer([$auditReportNormalizer, $issueNormalizer]);
        $auditReportNormalizer->setNormalizer($serializer);

        $this->reporter = new JsonReporter($auditReportNormalizer);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/var/www/my-project', [Module::SECURITY]);
    }

    private function getDecodedOutput(): mixed
    {
        return json_decode($this->output->fetch(), associative: true);
    }

    public function testOutputIsValidJson(): void
    {
        $this->reporter->generate($this->createReport(), $this->output);

        $json = $this->output->fetch();

        $this->assertNotNull(json_decode($json));
    }

    public function testMetaContainsProjectPath(): void
    {
        $this->reporter->generate($this->createReport(), $this->output);

        $data = $this->getDecodedOutput();

        $this->assertSame('/var/www/my-project', $data['meta']['project_path']);
    }

    public function testMetaContainsGeneratedAt(): void
    {
        $this->reporter->generate($this->createReport(), $this->output);

        $data = $this->getDecodedOutput();

        $this->assertArrayHasKey('generated_at', $data['meta']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $data['meta']['generated_at']
        );
    }

    public function testStatusIsOkWhenNoIssues(): void
    {
        $this->reporter->generate($this->createReport(), $this->output);

        $data = $this->getDecodedOutput();

        $this->assertSame('ok', $data['summary']['status']);
    }

    public function testStatusIsWarningWhenOnlyWarnings(): void
    {
        $report = $this->createReport();
        $report->addIssue(new Issue(
            Severity::WARNING,
            Module::SECURITY,
            'FirewallAnalyzer',
            'Lazy mode not enabled',
            'Some detail',
            'Enable lazy mode',
        ));

        $this->reporter->generate($report, $this->output);

        $data = $this->getDecodedOutput();

        $this->assertSame('warning', $data['summary']['status']);
    }

    public function testStatusIsCriticalWhenCriticalIssuePresent(): void
    {
        $report = $this->createReport();
        $report->addIssue(new Issue(
            Severity::WARNING,
            Module::SECURITY,
            'FirewallAnalyzer',
            'Lazy mode not enabled',
            'Some detail',
            'Enable lazy mode',
        ));
        $report->addIssue(new Issue(
            Severity::CRITICAL,
            Module::SECURITY,
            'FirewallAnalyzer',
            'No authenticator configured',
            'Some detail',
            'Add an authenticator',
        ));

        $this->reporter->generate($report, $this->output);

        $data = $this->getDecodedOutput();

        // Un seul CRITICAL suffit a passer le statut global en "critical".
        $this->assertSame('critical', $data['summary']['status']);
    }

    public function testIssuesCountIsCorrect(): void
    {
        $report = $this->createReport();
        $report->addIssue(new Issue(
            Severity::CRITICAL,
            Module::SECURITY,
            'FirewallAnalyzer',
            'No authenticator configured',
            'Some detail',
            'Add an authenticator',
        ));
        $report->addIssue(new Issue(
            Severity::WARNING,
            Module::SECURITY,
            'FirewallAnalyzer',
            'Lazy mode not enabled',
            'Some detail',
            'Enable lazy mode',
        ));
        $report->addIssue(new Issue(
            Severity::SUGGESTION,
            Module::SECURITY,
            'FirewallAnalyzer',
            'Consider enabling HSTS',
            'Some detail',
            'Add HSTS header',
        ));

        $this->reporter->generate($report, $this->output);

        $data = $this->getDecodedOutput();

        $this->assertSame(3, $data['summary']['issues_count']['total']);
        $this->assertSame(1, $data['summary']['issues_count']['critical']);
        $this->assertSame(1, $data['summary']['issues_count']['warning']);
        $this->assertSame(1, $data['summary']['issues_count']['suggestion']);
    }

    public function testIssueFieldsAreComplete(): void
    {
        $report = $this->createReport();
        $report->addIssue(new Issue(
            Severity::CRITICAL,
            Module::SECURITY,
            'FirewallAnalyzer',
            'No authenticator configured',
            'The firewall main has no authenticator',
            'Add form_login or custom_authenticator',
            'config/packages/security.yaml',
            42,
        ));

        $this->reporter->generate($report, $this->output);

        $data  = $this->getDecodedOutput();
        $issue = $data['issues'][0];

        $this->assertSame('critical', $issue['severity']);
        $this->assertSame('security', $issue['module']);
        $this->assertSame('FirewallAnalyzer', $issue['analyzer']);
        $this->assertSame('No authenticator configured', $issue['message']);
        $this->assertSame('The firewall main has no authenticator', $issue['detail']);
        $this->assertSame('Add form_login or custom_authenticator', $issue['suggestion']);
        $this->assertSame('config/packages/security.yaml', $issue['file']);
        $this->assertSame(42, $issue['line']);
    }

    public function testIssueFileAndLineAreNullWhenAbsent(): void
    {
        $report = $this->createReport();
        $report->addIssue(new Issue(
            Severity::WARNING,
            Module::SECURITY,
            'FirewallAnalyzer',
            'No firewall configured',
            'Some detail',
            'Add a firewall',
        ));

        $this->reporter->generate($report, $this->output);

        $data = $this->getDecodedOutput();

        $this->assertNull($data['issues'][0]['file']);
        $this->assertNull($data['issues'][0]['line']);
    }

    public function testScoreIsIncludedInSummary(): void
    {
        $this->reporter->generate($this->createReport(), $this->output);

        $data = $this->getDecodedOutput();

        $this->assertSame(100, $data['summary']['score']);
    }

    public function testGetFormatReturnsJson(): void
    {
        $this->assertSame('json', $this->reporter->getFormat());
    }
}