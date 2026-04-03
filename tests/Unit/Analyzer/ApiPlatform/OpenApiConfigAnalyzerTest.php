<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\OpenApiConfigAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste la detection des lacunes de documentation OpenAPI dans les ressources API Platform.
 * L'analyzer verifie que #[ApiResource] a une description et que les operations
 * individuelles (#[Get], #[Post], etc.) sont documentees.
 */
final class OpenApiConfigAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
        mkdir($this->tempDir . '/src/Entity', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    // --- Cas sans probleme ---

    /**
     * Si les dossiers src/Entity et src/ApiResource n'existent pas,
     * l'analyzer ne fait rien.
     */
    public function testNoEntityDirDoesNothing(): void
    {
        $analyzer = new OpenApiConfigAnalyzer($this->tempDir . '/nonexistent');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Une entite sans #[ApiResource] n'est pas analysee (pas exposee dans l'API).
     */
    public function testEntityWithoutApiResourceDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\nuse Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ORM\\Entity]\n"
            . "class InternalLog {\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$message;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/InternalLog.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * #[ApiResource] avec description: ne declenche pas d'alerte sur la ressource.
     */
    public function testApiResourceWithDescriptionDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n\n"
            . "#[ApiResource(description: 'Gestion des produits.')]\n"
            . "class Product {\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        // Pas de SUGGESTION pour la description de la ressource.
        $suggestions = array_filter(
            $report->getIssuesBySeverity(Severity::SUGGESTION),
            fn ($i) => str_contains($i->getMessage(), '#[ApiResource]'),
        );
        $this->assertCount(0, $suggestions);
    }

    // --- Cas avec probleme : ApiResource sans description ---

    /**
     * #[ApiResource] sans description ni attribut OpenAPI genere une SUGGESTION.
     * La spec OpenAPI sera incomplete pour les consommateurs de l'API.
     */
    public function testApiResourceWithoutDescriptionCreatesSuggestion(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n\n"
            . "#[ApiResource]\n"
            . "class Product {\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $apiResourceIssues = array_filter(
            $suggestions,
            fn ($i) => str_contains($i->getMessage(), '#[ApiResource]'),
        );
        $this->assertCount(1, $apiResourceIssues);
        $issue = reset($apiResourceIssues);
        $this->assertStringContainsString('description', $issue->getMessage());
    }

    // --- Cas sans probleme : operation avec description ---

    /**
     * #[Get(description: '...')] est correctement documente, pas d'alerte.
     */
    public function testOperationWithDescriptionDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Metadata\\Get;\n\n"
            . "#[ApiResource(description: 'Gestion des produits.')]\n"
            . "#[Get(description: 'Recupere un produit par son identifiant.')]\n"
            . "class Product {\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        // Pas de SUGGESTION pour les operations.
        $suggestions = array_filter(
            $report->getIssuesBySeverity(Severity::SUGGESTION),
            fn ($i) => str_contains($i->getMessage(), 'operation'),
        );
        $this->assertCount(0, $suggestions);
    }

    // --- Cas avec probleme : operation sans description ---

    /**
     * #[Get] sans description ni summary declenche une SUGGESTION.
     * L'endpoint sera present dans la spec OpenAPI sans explication.
     */
    public function testOperationWithoutDescriptionCreatesSuggestion(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Metadata\\Get;\n\n"
            . "#[ApiResource(description: 'Gestion des produits.')]\n"
            . "#[Get]\n"
            . "class Product {\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        $suggestions = array_filter(
            $report->getIssuesBySeverity(Severity::SUGGESTION),
            fn ($i) => str_contains($i->getMessage(), 'operation'),
        );
        $this->assertCount(1, $suggestions);
        $issue = reset($suggestions);
        $this->assertStringContainsString('documentation', $issue->getMessage());
    }

    // --- Cas sans probleme : attribut OpenAPI present ---

    /**
     * Un fichier avec un attribut #[OA\...] (NelmioApiDocBundle ou similaire)
     * ne doit pas declencher d'alerte sur la description de la ressource.
     */
    public function testOpenApiAttributeDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use OpenApi\\Attributes as OA;\n\n"
            . "#[ApiResource]\n"
            . "#[OA\\Schema(description: 'Un produit du catalogue.')]\n"
            . "class Product {\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        // L'attribut #[OA\ dispense de la description dans #[ApiResource].
        $suggestions = array_filter(
            $report->getIssuesBySeverity(Severity::SUGGESTION),
            fn ($i) => str_contains($i->getMessage(), '#[ApiResource]'),
        );
        $this->assertCount(0, $suggestions);
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue SUGGESTION (ApiResource sans description) contient
     * les champs d'enrichissement.
     */
    public function testEnrichmentFields(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n\n"
            . "#[ApiResource]\n"
            . "class Invoice {\n"
            . "    private string \$reference;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Invoice.php', $content);

        $report = $this->runAnalyzer();

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertGreaterThanOrEqual(1, count($suggestions));

        // Prend la premiere issue SUGGESTION (ApiResource sans description).
        $issue = $suggestions[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('description', $issue->getFixCode() ?? '');
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('api-platform.com', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertNotNull($issue->getFile());
    }

    // --- Nom et module ---

    /**
     * Verifie le nom et le module retournes par l'analyzer.
     */
    public function testGetNameAndGetModule(): void
    {
        $analyzer = new OpenApiConfigAnalyzer($this->tempDir);

        $this->assertSame('OpenAPI Config Analyzer', $analyzer->getName());
        $this->assertSame(Module::API_PLATFORM, $analyzer->getModule());
    }

    // --- Helpers ---

    private function runAnalyzer(): AuditReport
    {
        $analyzer = new OpenApiConfigAnalyzer($this->tempDir);
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer->analyze($report);

        return $report;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
