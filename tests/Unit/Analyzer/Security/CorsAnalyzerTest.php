<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Security\CorsAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class CorsAnalyzerTest extends TestCase
{
    // --- Helper : creer un analyzer avec controle fin sur read() et exists() ---

    /**
     * Cree un CorsAnalyzer avec un mock ConfigReader configurable.
     *
     * @param array<mixed>|null $corsConfig  Ce que read('nelmio_cors.yaml') retournera
     * @param array<string>     $existingFiles Liste des fichiers pour lesquels exists() retourne true
     */
    private function createAnalyzer(?array $corsConfig, array $existingFiles = []): CorsAnalyzer
    {
        $configReader = $this->createMock(ConfigReaderInterface::class);

        // read() retourne la config CORS fournie
        $configReader->method('read')->willReturn($corsConfig);

        // exists() retourne true uniquement pour les fichiers listes
        $configReader->method('exists')->willReturnCallback(function (string $path) use ($existingFiles) {
            return in_array($path, $existingFiles, true);
        });

        return new CorsAnalyzer($configReader);
    }

    // Helper : creer un rapport vide pour chaque test.
    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::SECURITY]);
    }

    // =============================================
    // Test 1 : Pas de config CORS mais API Platform detecte
    // =============================================

    public function testNoCorsConfigWithApiPlatformCreatesSuggestion(): void
    {
        // Arrange : pas de nelmio_cors.yaml, mais api_platform.yaml existe
        $analyzer = $this->createAnalyzer(null, [
            'config/packages/api_platform.yaml',
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : une SUGGESTION pour installer NelmioCorsBundle
        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(1, $suggestions);
        $this->assertStringContainsString('CORS', $suggestions[0]->getMessage());
    }

    // =============================================
    // Test 2 : Pas de config CORS et pas d'API
    // =============================================

    public function testNoCorsConfigWithoutApiDoesNothing(): void
    {
        // Arrange : pas de nelmio_cors.yaml, pas d'api_platform.yaml
        $analyzer = $this->createAnalyzer(null, []);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 3 : Wildcard avec credentials (CRITICAL)
    // =============================================

    public function testWildcardWithCredentialsCreatesCritical(): void
    {
        // Arrange : allow_origin: ['*'] + allow_credentials: true
        $analyzer = $this->createAnalyzer([
            'nelmio_cors' => [
                'defaults' => [
                    'allow_origin' => ['*'],
                    'allow_credentials' => true,
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : une issue CRITICAL
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('allow_origin', $criticals[0]->getMessage());
        $this->assertStringContainsString('allow_credentials', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 4 : Wildcard sans credentials (WARNING)
    // =============================================

    public function testWildcardWithoutCredentialsCreatesWarning(): void
    {
        // Arrange : allow_origin: ['*'] sans credentials
        $analyzer = $this->createAnalyzer([
            'nelmio_cors' => [
                'defaults' => [
                    'allow_origin' => ['*'],
                    'allow_credentials' => false,
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : une issue WARNING (wildcard sur routes non publiques)
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('allow_origin', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 5 : Origines explicites, pas de probleme
    // =============================================

    public function testExplicitOriginsDoesNothing(): void
    {
        // Arrange : origines explicites sans wildcard
        $analyzer = $this->createAnalyzer([
            'nelmio_cors' => [
                'defaults' => [
                    'allow_origin' => ['https://mon-frontend.com'],
                    'allow_credentials' => true,
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 6 : Verification des champs d'enrichissement
    // =============================================

    public function testEnrichmentFields(): void
    {
        // Arrange : config qui genere un CRITICAL (wildcard + credentials)
        $analyzer = $this->createAnalyzer([
            'nelmio_cors' => [
                'defaults' => [
                    'allow_origin' => ['*'],
                    'allow_credentials' => true,
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : verification des champs d'enrichissement sur l'issue CRITICAL
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('NelmioCorsBundle', $issue->getDocUrl() ?? '');
    }
}
