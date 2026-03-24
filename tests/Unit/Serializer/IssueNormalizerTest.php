<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Serializer;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Serializer\IssueNormalizer;

final class IssueNormalizerTest extends TestCase
{
    private IssueNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new IssueNormalizer();
    }

    public function testSupportsIssueInstance(): void
    {
        $issue = $this->createIssue();
        $this->assertTrue($this->normalizer->supportsNormalization($issue));
    }

    public function testDoesNotSupportOtherObjects(): void
    {
        $this->assertFalse($this->normalizer->supportsNormalization(new \stdClass()));
    }

    public function testNormalizeReturnsExpectedKeys(): void
    {
        $issue = $this->createIssue();
        $result = $this->normalizer->normalize($issue);

        $this->assertArrayHasKey('severity', $result);
        $this->assertArrayHasKey('module', $result);
        $this->assertArrayHasKey('analyzer', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('detail', $result);
        $this->assertArrayHasKey('suggestion', $result);
        $this->assertArrayHasKey('file', $result);
        $this->assertArrayHasKey('line', $result);
        $this->assertArrayHasKey('fix_code', $result);
        $this->assertArrayHasKey('doc_url', $result);
        $this->assertArrayHasKey('business_impact', $result);
        $this->assertArrayHasKey('estimated_fix_minutes', $result);
    }

    public function testSeverityIsLowercaseName(): void
    {
        $issue = $this->createIssue(severity: Severity::CRITICAL);
        $result = $this->normalizer->normalize($issue);

        $this->assertSame('critical', $result['severity']);
    }

    public function testModuleIsLowercaseName(): void
    {
        $issue = $this->createIssue(module: Module::ARCHITECTURE);
        $result = $this->normalizer->normalize($issue);

        $this->assertSame('architecture', $result['module']);
    }

    public function testFileAndLineAreNullWhenAbsent(): void
    {
        $issue = $this->createIssue();
        $result = $this->normalizer->normalize($issue);

        $this->assertNull($result['file']);
        $this->assertNull($result['line']);
    }

    public function testFileAndLineArePresentWhenSet(): void
    {
        $issue = new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: 'test_analyzer',
            message: 'Test message',
            detail: 'Test detail',
            suggestion: 'Test suggestion',
            file: 'src/Controller/FooController.php',
            line: 42,
        );

        $result = $this->normalizer->normalize($issue);

        $this->assertSame('src/Controller/FooController.php', $result['file']);
        $this->assertSame(42, $result['line']);
    }

    public function testGetSupportedTypes(): void
    {
        $types = $this->normalizer->getSupportedTypes(null);
        $this->assertArrayHasKey(Issue::class, $types);
        $this->assertTrue($types[Issue::class]);
    }

    public function testEnrichmentFieldsAreNullByDefault(): void
    {
        // Sans les 4 champs d'enrichissement, le normalizer doit retourner null pour chacun.
        $issue = $this->createIssue();
        $result = $this->normalizer->normalize($issue);

        $this->assertNull($result['fix_code']);
        $this->assertNull($result['doc_url']);
        $this->assertNull($result['business_impact']);
        $this->assertNull($result['estimated_fix_minutes']);
    }

    public function testEnrichmentFieldsAreNormalizedWhenSet(): void
    {
        $issue = new Issue(
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
        );

        $result = $this->normalizer->normalize($issue);

        $this->assertSame("security:\n  firewalls:\n    main:\n      form_login: ~", $result['fix_code']);
        $this->assertSame('https://symfony.com/doc/current/security.html', $result['doc_url']);
        $this->assertSame('Accès non authentifié aux routes protégées.', $result['business_impact']);
        $this->assertSame(15, $result['estimated_fix_minutes']);
    }

    public function testEnrichmentFieldsUsesSnakeCase(): void
    {
        // Les noms de clés JSON suivent la convention snake_case.
        // Les propriétés PHP sont en camelCase (fixCode) mais les clés JSON en snake_case (fix_code).
        $issue = $this->createIssue();
        $result = $this->normalizer->normalize($issue);

        $this->assertArrayNotHasKey('fixCode', $result);
        $this->assertArrayNotHasKey('docUrl', $result);
        $this->assertArrayNotHasKey('businessImpact', $result);
        $this->assertArrayNotHasKey('estimatedFixMinutes', $result);
    }

    private function createIssue(
        Severity $severity = Severity::WARNING,
        Module $module = Module::SECURITY,
    ): Issue {
        return new Issue(
            severity: $severity,
            module: $module,
            analyzer: 'test_analyzer',
            message: 'Test message',
            detail: 'Test detail',
            suggestion: 'Test suggestion',
        );
    }
}