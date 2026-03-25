<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Architecture;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Architecture\PublicServiceAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class PublicServiceAnalyzerTest extends TestCase
{
    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Cree un analyzer avec un mock de ConfigReaderInterface.
     *
     * @param array<mixed>|null $servicesConfig Ce que read() retournera
     */
    private function createAnalyzer(?array $servicesConfig): PublicServiceAnalyzer
    {
        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn($servicesConfig);

        return new PublicServiceAnalyzer($configReader);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::ARCHITECTURE]);
    }

    // ---------------------------------------------------------------
    // 1. Pas de fichier services.yaml - aucun issue
    // ---------------------------------------------------------------

    public function testNoServicesYamlDoesNothing(): void
    {
        // Le reader retourne null (fichier inexistant)
        $analyzer = $this->createAnalyzer(null);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // ---------------------------------------------------------------
    // 2. Service avec public: true - WARNING
    // ---------------------------------------------------------------

    public function testPublicServiceCreatesWarning(): void
    {
        $analyzer = $this->createAnalyzer([
            'services' => [
                'App\\Service\\OrderExporter' => [
                    'public' => true,
                    'arguments' => ['@doctrine.orm.entity_manager'],
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('OrderExporter', $warnings[0]->getMessage());
    }

    // ---------------------------------------------------------------
    // 3. Commande avec public: true - exclue (pas de warning)
    // ---------------------------------------------------------------

    public function testCommandIsExcluded(): void
    {
        // Les commandes sont legitimement publiques
        $analyzer = $this->createAnalyzer([
            'services' => [
                'App\\Command\\ImportUsersCommand' => [
                    'public' => true,
                    'tags' => ['console.command'],
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // ---------------------------------------------------------------
    // 4. Controller avec public: true - exclu (pas de warning)
    // ---------------------------------------------------------------

    public function testControllerIsExcluded(): void
    {
        // Les controllers sont legitimement publics dans certains cas
        $analyzer = $this->createAnalyzer([
            'services' => [
                'App\\Controller\\DashboardController' => [
                    'public' => true,
                    'tags' => ['controller.service_arguments'],
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // ---------------------------------------------------------------
    // 5. Service prive (pas de public: true) - aucun issue
    // ---------------------------------------------------------------

    public function testPrivateServiceDoesNothing(): void
    {
        $analyzer = $this->createAnalyzer([
            'services' => [
                'App\\Service\\Mailer' => [
                    'arguments' => ['@mailer.transport'],
                ],
                'App\\Service\\Logger' => [
                    'public' => false,
                ],
                // Service avec une config scalaire (non-array) - ignore
                'App\\Service\\Simple' => '~',
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // ---------------------------------------------------------------
    // 6. Verification des champs d'enrichissement
    // ---------------------------------------------------------------

    public function testEnrichmentFields(): void
    {
        $analyzer = $this->createAnalyzer([
            'services' => [
                'App\\Service\\EnrichTest' => [
                    'public' => true,
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode(), 'fixCode ne doit pas etre null');
        $this->assertNotNull($issue->getDocUrl(), 'docUrl ne doit pas etre null');
        $this->assertNotNull($issue->getBusinessImpact(), 'businessImpact ne doit pas etre null');
        $this->assertNotNull($issue->getEstimatedFixMinutes(), 'estimatedFixMinutes ne doit pas etre null');
        $this->assertSame(10, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('public-versus-private', $issue->getDocUrl() ?? '');
    }
}
