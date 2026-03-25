<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\PaginationAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste l'analyse de la configuration de pagination d'API Platform.
 * Deux verifications : pagination desactivee globalement et
 * client_items_per_page actif sans maximum_items_per_page.
 */
final class PaginationAnalyzerTest extends TestCase
{
    // --- Cas sans probleme ---

    /**
     * Si le fichier api_platform.yaml n'existe pas, l'analyzer ne fait rien.
     */
    public function testNullConfigDoesNothing(): void
    {
        $analyzer = $this->createAnalyzer(null);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Si la cle api_platform n'est pas presente dans la config, pas d'analyse.
     */
    public function testMissingApiPlatformKeyDoesNothing(): void
    {
        $analyzer = $this->createAnalyzer([
            'framework' => ['cache' => ['app' => 'cache.adapter.redis']],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Config complete et correcte : pagination active, client_items_per_page
     * avec maximum_items_per_page defini.
     */
    public function testProperConfigCreatesNoIssue(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'defaults' => [
                    'pagination_enabled' => true,
                    'pagination_items_per_page' => 30,
                    'pagination_client_items_per_page' => true,
                    'pagination_maximum_items_per_page' => 100,
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Pagination active sans client_items_per_page ne pose pas de probleme.
     */
    public function testPaginationEnabledWithoutClientItemsDoesNothing(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'defaults' => [
                    'pagination_enabled' => true,
                    'pagination_items_per_page' => 30,
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Cas avec probleme : pagination desactivee ---

    /**
     * pagination_enabled: false desactive la pagination globalement.
     * Toutes les collections retournent tous les enregistrements.
     * L'analyzer doit signaler un WARNING.
     */
    public function testPaginationDisabledCreatesWarning(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'defaults' => [
                    'pagination_enabled' => false,
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Pagination', $warnings[0]->getMessage());
        $this->assertStringContainsString('desactivee', $warnings[0]->getMessage());
    }

    /**
     * La cle alternative collection.pagination.enabled: false est aussi detectee.
     */
    public function testPaginationDisabledViaCollectionKeyCreatesWarning(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'collection' => [
                    'pagination' => [
                        'enabled' => false,
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Pagination', $warnings[0]->getMessage());
    }

    // --- Cas avec probleme : client_items_per_page sans maximum ---

    /**
     * client_items_per_page: true sans maximum_items_per_page permet un DoS.
     * Un client malveillant peut demander items_per_page=999999.
     * L'analyzer doit signaler un WARNING.
     */
    public function testClientItemsPerPageWithoutMaxCreatesWarning(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'defaults' => [
                    'pagination_client_items_per_page' => true,
                    // Pas de pagination_maximum_items_per_page
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('client_items_per_page', $warnings[0]->getMessage());
        $this->assertStringContainsString('maximum_items_per_page', $warnings[0]->getMessage());
    }

    /**
     * client_items_per_page: true AVEC maximum_items_per_page ne pose pas de probleme.
     */
    public function testClientItemsPerPageWithMaxDoesNothing(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'defaults' => [
                    'pagination_client_items_per_page' => true,
                    'pagination_maximum_items_per_page' => 50,
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue WARNING (pagination desactivee) contient
     * les champs d'enrichissement.
     */
    public function testEnrichmentFields(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'defaults' => [
                    'pagination_enabled' => false,
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('pagination_enabled', $issue->getFixCode() ?? '');
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('api-platform.com', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(10, $issue->getEstimatedFixMinutes());
        $this->assertSame('config/packages/api_platform.yaml', $issue->getFile());
    }

    // --- Helpers ---

    /**
     * Cree un analyzer avec un mock du ConfigReader.
     *
     * @param array<mixed>|null $config Ce que read() retournera
     */
    private function createAnalyzer(?array $config): PaginationAnalyzer
    {
        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn($config);

        return new PaginationAnalyzer($configReader);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::API_PLATFORM]);
    }
}
