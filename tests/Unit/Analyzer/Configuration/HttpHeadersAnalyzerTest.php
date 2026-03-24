<?php

// tests/Unit/Analyzer/Configuration/HttpHeadersAnalyzerTest.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Configuration;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Configuration\HttpHeadersAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class HttpHeadersAnalyzerTest extends TestCase
{
    private function makeReport(): AuditReport
    {
        return new AuditReport('/fake/path', [Module::SECURITY]);
    }

    private function makeAnalyzer(?array $frameworkConfig): HttpHeadersAnalyzer
    {
        $reader = $this->createMock(ConfigReaderInterface::class);
        $reader->method('read')->willReturn($frameworkConfig);

        return new HttpHeadersAnalyzer($reader);
    }

    // --- Headers tous absents ---

    public function testDetectsAllMissingHeaders(): void
    {
        $analyzer = $this->makeAnalyzer([
            'framework' => ['http_response' => ['headers' => []]],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);

        // X-Frame-Options + X-Content-Type-Options = 2 warnings, CSP = 1 suggestion.
        $this->assertCount(2, $warnings);
        $this->assertCount(1, $suggestions);
    }

    // --- X-Frame-Options absent ---

    public function testDetectsMissingXFrameOptions(): void
    {
        $analyzer = $this->makeAnalyzer([
            'framework' => ['http_response' => ['headers' => [
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'self'",
            ]]],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('X-Frame-Options', $warnings[0]->getMessage());
    }

    // --- X-Content-Type-Options absent ---

    public function testDetectsMissingXContentTypeOptions(): void
    {
        $analyzer = $this->makeAnalyzer([
            'framework' => ['http_response' => ['headers' => [
                'X-Frame-Options' => 'SAMEORIGIN',
                'Content-Security-Policy' => "default-src 'self'",
            ]]],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('X-Content-Type-Options', $warnings[0]->getMessage());
    }

    // --- CSP absente ---

    public function testDetectsMissingCsp(): void
    {
        $analyzer = $this->makeAnalyzer([
            'framework' => ['http_response' => ['headers' => [
                'X-Frame-Options' => 'SAMEORIGIN',
                'X-Content-Type-Options' => 'nosniff',
            ]]],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));
        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(1, $suggestions);
        $this->assertStringContainsString('Content-Security-Policy', $suggestions[0]->getMessage());
    }

    // --- Tous les headers présents ---

    public function testPassesWithAllSecurityHeaders(): void
    {
        $analyzer = $this->makeAnalyzer([
            'framework' => ['http_response' => ['headers' => [
                'X-Frame-Options' => 'SAMEORIGIN',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'self'",
            ]]],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));
        $this->assertCount(0, $report->getIssuesBySeverity(Severity::SUGGESTION));
    }

    // --- framework.yaml absent ---

    public function testPassesWhenNoFrameworkConfig(): void
    {
        $analyzer = $this->makeAnalyzer(null);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));
    }

    // --- Insensible à la casse des noms de headers ---

    public function testHeaderCheckIsCaseInsensitive(): void
    {
        $analyzer = $this->makeAnalyzer([
            'framework' => ['http_response' => ['headers' => [
                'x-frame-options' => 'SAMEORIGIN',
                'x-content-type-options' => 'nosniff',
                'content-security-policy' => "default-src 'self'",
            ]]],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));
        $this->assertCount(0, $report->getIssuesBySeverity(Severity::SUGGESTION));
    }

    // --- Enrichissement ---

    public function testIssueHasFixCode(): void
    {
        $analyzer = $this->makeAnalyzer([
            'framework' => ['http_response' => ['headers' => []]],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertNotNull($warnings[0]->getFixCode());
        $this->assertNotNull($warnings[0]->getDocUrl());
    }
}