<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\GlobalApiPlatformConfigAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste l'analyse de la configuration globale d'API Platform.
 * Quatre verifications : show_webby actif, absence de error_formats,
 * format HTML dans les formats, et mapping.paths non configure.
 */
final class GlobalApiPlatformConfigAnalyzerTest extends TestCase
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

    // --- show_webby ---

    /**
     * show_webby: false ne genere aucune issue.
     */
    public function testShowWebbyExplicitFalseDoesNothing(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'show_webby' => false,
                'error_formats' => ['jsonproblem' => ['application/problem+json']],
                'mapping' => ['paths' => ['%kernel.project_dir%/src/Entity']],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $showWebbySuggestions = array_filter(
            $suggestions,
            static fn ($issue) => str_contains($issue->getMessage(), 'show_webby')
                || str_contains($issue->getMessage(), 'Webby'),
        );
        $this->assertCount(0, $showWebbySuggestions);
    }

    /**
     * show_webby: true genere une SUGGESTION pour la masquer en production.
     */
    public function testShowWebbyTrueCreatesSuggestion(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'show_webby' => true,
                'error_formats' => ['jsonproblem' => ['application/problem+json']],
                'mapping' => ['paths' => ['%kernel.project_dir%/src/Entity']],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $showWebbySuggestions = array_filter(
            $suggestions,
            static fn ($issue) => str_contains($issue->getMessage(), 'show_webby'),
        );
        $this->assertCount(1, $showWebbySuggestions);
    }

    /**
     * Si show_webby est absent de la config, il vaut true par defaut.
     * L'analyzer doit signaler une SUGGESTION.
     */
    public function testShowWebbyMissingCreatesSuggestion(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'error_formats' => ['jsonproblem' => ['application/problem+json']],
                'mapping' => ['paths' => ['%kernel.project_dir%/src/Entity']],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $showWebbySuggestions = array_filter(
            $suggestions,
            static fn ($issue) => str_contains($issue->getMessage(), 'show_webby')
                || str_contains($issue->getMessage(), 'Webby'),
        );
        $this->assertCount(1, $showWebbySuggestions);
    }

    // --- error_formats ---

    /**
     * Pas de error_formats configure genere un WARNING.
     */
    public function testErrorFormatsMissingCreatesWarning(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'show_webby' => false,
                'mapping' => ['paths' => ['%kernel.project_dir%/src/Entity']],
                // Pas de error_formats
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $errorFormatWarnings = array_filter(
            $warnings,
            static fn ($issue) => str_contains($issue->getMessage(), 'error_formats'),
        );
        $this->assertCount(1, $errorFormatWarnings);
    }

    /**
     * error_formats configure ne genere aucun WARNING.
     */
    public function testErrorFormatsConfiguredDoesNothing(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'show_webby' => false,
                'error_formats' => [
                    'jsonproblem' => ['application/problem+json'],
                    'jsonld' => ['application/ld+json'],
                ],
                'mapping' => ['paths' => ['%kernel.project_dir%/src/Entity']],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $errorFormatWarnings = array_filter(
            $warnings,
            static fn ($issue) => str_contains($issue->getMessage(), 'error_formats'),
        );
        $this->assertCount(0, $errorFormatWarnings);
    }

    // --- formats HTML ---

    /**
     * Le format HTML dans la liste des formats genere un WARNING.
     */
    public function testHtmlFormatCreatesWarning(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'show_webby' => false,
                'error_formats' => ['jsonproblem' => ['application/problem+json']],
                'formats' => [
                    'jsonld' => ['application/ld+json'],
                    'html' => ['text/html'],
                ],
                'mapping' => ['paths' => ['%kernel.project_dir%/src/Entity']],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $htmlWarnings = array_filter(
            $warnings,
            static fn ($issue) => str_contains($issue->getMessage(), 'HTML'),
        );
        $this->assertCount(1, $htmlWarnings);
    }

    /**
     * Formats JSON uniquement ne genere aucun WARNING sur le format.
     */
    public function testJsonOnlyFormatsDoesNothing(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'show_webby' => false,
                'error_formats' => ['jsonproblem' => ['application/problem+json']],
                'formats' => [
                    'jsonld' => ['application/ld+json'],
                    'json' => ['application/json'],
                ],
                'mapping' => ['paths' => ['%kernel.project_dir%/src/Entity']],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $htmlWarnings = array_filter(
            $warnings,
            static fn ($issue) => str_contains($issue->getMessage(), 'HTML'),
        );
        $this->assertCount(0, $htmlWarnings);
    }

    // --- mapping.paths ---

    /**
     * mapping.paths absent genere une SUGGESTION.
     */
    public function testMappingPathsMissingCreatesSuggestion(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'show_webby' => false,
                'error_formats' => ['jsonproblem' => ['application/problem+json']],
                // Pas de mapping.paths
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $mappingSuggestions = array_filter(
            $suggestions,
            static fn ($issue) => str_contains($issue->getMessage(), 'mapping.paths'),
        );
        $this->assertCount(1, $mappingSuggestions);
    }

    /**
     * mapping.paths configure ne genere aucune SUGGESTION sur les paths.
     */
    public function testMappingPathsConfiguredDoesNothing(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'show_webby' => false,
                'error_formats' => ['jsonproblem' => ['application/problem+json']],
                'mapping' => [
                    'paths' => [
                        '%kernel.project_dir%/src/Entity',
                        '%kernel.project_dir%/src/ApiResource',
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $mappingSuggestions = array_filter(
            $suggestions,
            static fn ($issue) => str_contains($issue->getMessage(), 'mapping.paths'),
        );
        $this->assertCount(0, $mappingSuggestions);
    }

    // --- Config complete sans probleme ---

    /**
     * Config complete et correcte : aucune issue generee.
     */
    public function testFullProperConfigCreatesNoIssue(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'show_webby' => false,
                'error_formats' => [
                    'jsonproblem' => ['application/problem+json'],
                    'jsonld' => ['application/ld+json'],
                ],
                'formats' => [
                    'jsonld' => ['application/ld+json'],
                    'json' => ['application/json'],
                ],
                'mapping' => [
                    'paths' => [
                        '%kernel.project_dir%/src/Entity',
                        '%kernel.project_dir%/src/ApiResource',
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue SUGGESTION (show_webby actif par defaut) contient
     * les champs d'enrichissement.
     */
    public function testEnrichmentFields(): void
    {
        $analyzer = $this->createAnalyzer([
            'api_platform' => [
                'show_webby' => true,
                'error_formats' => ['jsonproblem' => ['application/problem+json']],
                'mapping' => ['paths' => ['%kernel.project_dir%/src/Entity']],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $showWebbySuggestions = array_values(array_filter(
            $suggestions,
            static fn ($issue) => str_contains($issue->getMessage(), 'show_webby'),
        ));
        $this->assertCount(1, $showWebbySuggestions);

        $issue = $showWebbySuggestions[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('show_webby', $issue->getFixCode() ?? '');
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('api-platform.com', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(2, $issue->getEstimatedFixMinutes());
        $this->assertSame('config/packages/api_platform.yaml', $issue->getFile());
    }

    // --- Nom et module ---

    /**
     * Verifie le nom de l'analyzer et le module associe.
     */
    public function testGetNameAndGetModule(): void
    {
        $analyzer = $this->createAnalyzer(null);

        $this->assertSame('Global API Platform Config Analyzer', $analyzer->getName());
        $this->assertSame(Module::API_PLATFORM, $analyzer->getModule());
    }

    // --- Helpers ---

    /**
     * Cree un analyzer avec un mock du ConfigReader.
     *
     * @param array<mixed>|null $config Ce que read() retournera
     */
    private function createAnalyzer(?array $config): GlobalApiPlatformConfigAnalyzer
    {
        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn($config);

        return new GlobalApiPlatformConfigAnalyzer($configReader);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::API_PLATFORM]);
    }
}
