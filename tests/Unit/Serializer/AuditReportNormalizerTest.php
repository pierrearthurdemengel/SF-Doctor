<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Serializer;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Serializer\AuditReportNormalizer;
use PierreArthur\SfDoctor\Serializer\IssueNormalizer;
use Symfony\Component\Serializer\Serializer;

final class AuditReportNormalizerTest extends TestCase
{
    private AuditReportNormalizer $normalizer;

    protected function setUp(): void
    {
        $issueNormalizer = new IssueNormalizer();
        $this->normalizer = new AuditReportNormalizer();
        $serializer = new Serializer([$this->normalizer, $issueNormalizer]);
        $this->normalizer->setNormalizer($serializer);
    }

    public function testSupportsAuditReportInstance(): void
    {
        $report = $this->createReport();
        $this->assertTrue($this->normalizer->supportsNormalization($report));
    }

    public function testDoesNotSupportOtherObjects(): void
    {
        $this->assertFalse($this->normalizer->supportsNormalization(new \stdClass()));
    }

    public function testNormalizeReturnsMetaSummaryAndIssues(): void
    {
        $report = $this->createReport();
        $result = $this->normalizer->normalize($report);

        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('issues', $result);
    }

    public function testMetaContainsProjectPath(): void
    {
        $report = $this->createReport();
        $result = $this->normalizer->normalize($report);

        $this->assertSame('/var/www/my-project', $result['meta']['project_path']);
    }

    public function testMetaContainsGeneratedAt(): void
    {
        $report = $this->createReport();
        $result = $this->normalizer->normalize($report);

        $this->assertArrayHasKey('generated_at', $result['meta']);
        // Verifie que la date est au format ISO 8601.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $result['meta']['generated_at']);
    }

    public function testStatusIsOkWhenNoIssues(): void
    {
        $report = $this->createReport();
        $result = $this->normalizer->normalize($report);

        $this->assertSame('ok', $result['summary']['status']);
    }

    public function testStatusIsCriticalWhenCriticalIssuePresent(): void
    {
        $report = $this->createReport();
        $report->addIssue($this->createIssue(Severity::CRITICAL));
        $result = $this->normalizer->normalize($report);

        $this->assertSame('critical', $result['summary']['status']);
    }

    public function testStatusIsWarningWhenOnlyWarnings(): void
    {
        $report = $this->createReport();
        $report->addIssue($this->createIssue(Severity::WARNING));
        $result = $this->normalizer->normalize($report);

        $this->assertSame('warning', $result['summary']['status']);
    }

    public function testIssuesAreDelegatedToIssueNormalizer(): void
    {
        $report = $this->createReport();
        $report->addIssue($this->createIssue(Severity::WARNING));
        $result = $this->normalizer->normalize($report);

        $this->assertCount(1, $result['issues']);
        $this->assertArrayHasKey('severity', $result['issues'][0]);
        $this->assertArrayHasKey('message', $result['issues'][0]);
    }

    public function testIssuesCountIsCorrect(): void
    {
        $report = $this->createReport();
        $report->addIssue($this->createIssue(Severity::CRITICAL));
        $report->addIssue($this->createIssue(Severity::WARNING));
        $result = $this->normalizer->normalize($report);

        $this->assertSame(2, $result['summary']['issues_count']['total']);
        $this->assertSame(1, $result['summary']['issues_count']['critical']);
        $this->assertSame(1, $result['summary']['issues_count']['warning']);
    }

    public function testGetSupportedTypes(): void
    {
        $types = $this->normalizer->getSupportedTypes(null);
        $this->assertArrayHasKey(AuditReport::class, $types);
        $this->assertTrue($types[AuditReport::class]);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/var/www/my-project', [Module::SECURITY]);
    }

    private function createIssue(Severity $severity = Severity::WARNING): Issue
    {
        return new Issue(
            severity: $severity,
            module: Module::SECURITY,
            analyzer: 'test_analyzer',
            message: 'Test message',
            detail: 'Test detail',
            suggestion: 'Test suggestion',
        );
    }

    public function testIssueEnrichmentFieldsPassThroughNormalization(): void
    {
        // Verifie que les champs d'enrichissement sont bien present dans les issues normalisees.
        // AuditReportNormalizer delegue a IssueNormalizer - ce test garantit que
        // la chaine de delegation ne perd pas les nouveaux champs.
        $report = $this->createReport();
        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::SECURITY,
            analyzer: 'FirewallAnalyzer',
            message: 'Firewall sans authentification',
            detail: 'Aucun authenticator configuré.',
            suggestion: 'Ajouter form_login.',
            fixCode: "security:\n  firewalls:\n    main:\n      form_login: ~",
            docUrl: 'https://symfony.com/doc/current/security.html',
            businessImpact: 'Accès non authentifié aux routes protégées.',
            estimatedFixMinutes: 15,
        ));

        $result = $this->normalizer->normalize($report);

        $normalizedIssue = $result['issues'][0];
        $this->assertSame("security:\n  firewalls:\n    main:\n      form_login: ~", $normalizedIssue['fix_code']);
        $this->assertSame('https://symfony.com/doc/current/security.html', $normalizedIssue['doc_url']);
        $this->assertSame('Accès non authentifié aux routes protégées.', $normalizedIssue['business_impact']);
        $this->assertSame(15, $normalizedIssue['estimated_fix_minutes']);
    }
}