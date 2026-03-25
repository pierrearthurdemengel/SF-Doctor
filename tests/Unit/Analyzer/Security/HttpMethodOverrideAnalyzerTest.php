<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Security\HttpMethodOverrideAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class HttpMethodOverrideAnalyzerTest extends TestCase
{
    // --- Helper : creer un analyzer avec un mock du ConfigReader ---

    /**
     * @param array<mixed>|null $frameworkConfig Ce que read() retournera
     */
    private function createAnalyzer(?array $frameworkConfig): HttpMethodOverrideAnalyzer
    {
        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn($frameworkConfig);

        return new HttpMethodOverrideAnalyzer($configReader);
    }

    // Helper : creer un rapport vide pour chaque test.
    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::SECURITY]);
    }

    // =============================================
    // Test 1 : Pas de fichier framework.yaml
    // =============================================

    public function testNoConfigDoesNothing(): void
    {
        // Arrange : le reader retourne null (fichier inexistant)
        $analyzer = $this->createAnalyzer(null);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 2 : http_method_override active -> WARNING
    // =============================================

    public function testHttpMethodOverrideEnabledCreatesWarning(): void
    {
        // Arrange : http_method_override explicitement a true
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'http_method_override' => true,
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : un WARNING pour l'activation du method override
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('http_method_override', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 3 : http_method_override desactive -> pas de probleme
    // =============================================

    public function testHttpMethodOverrideDisabledDoesNothing(): void
    {
        // Arrange : http_method_override explicitement a false
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'http_method_override' => false,
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 4 : http_method_override absent -> pas de probleme
    // =============================================

    public function testHttpMethodOverrideMissingDoesNothing(): void
    {
        // Arrange : framework.yaml sans la cle http_method_override
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'secret' => '%env(APP_SECRET)%',
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue (l'absence n'est pas un probleme)
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 5 : Verification des champs d'enrichissement
    // =============================================

    public function testEnrichmentFields(): void
    {
        // Arrange : config qui genere un WARNING
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'http_method_override' => true,
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : verification des champs d'enrichissement
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('http-method-override', $issue->getDocUrl() ?? '');
    }
}
