<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\FilterConfigAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste la detection des configurations de filtres API Platform dangereuses ou manquantes.
 * Trois verifications : SearchFilter partial sur champ sensible (CRITICAL),
 * OrderFilter sans restriction de proprietes (WARNING),
 * ressource sans aucun filtre (SUGGESTION).
 */
final class FilterConfigAnalyzerTest extends TestCase
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
     * Si le dossier src/Entity n'existe pas, l'analyzer ne fait rien.
     */
    public function testNoEntityDirDoesNothing(): void
    {
        $analyzer = new FilterConfigAnalyzer($this->tempDir . '/nonexistent');
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Une entite sans #[ApiResource] n'est pas analysee.
     */
    public function testEntityWithoutApiResourceDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ORM\\Entity]\n"
            . "class InternalConfig {\n"
            . "    private string \$email;\n"
            . "    private string \$password;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/InternalConfig.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    // --- Cas avec probleme : SearchFilter partial sur champ sensible ---

    /**
     * SearchFilter avec strategy 'partial' sur le champ 'email' permet
     * l'enumeration par recherche incrementale. L'analyzer doit signaler un CRITICAL.
     */
    public function testSearchFilterPartialOnSensitiveFieldCreatesCritical(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Doctrine\\Orm\\Filter\\SearchFilter;\n"
            . "use ApiPlatform\\Metadata\\ApiFilter;\n\n"
            . "#[ApiResource]\n"
            . "#[ApiFilter(SearchFilter::class, properties: ['email' => 'partial'])]\n"
            . "class User {\n"
            . "    private string \$email;\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/User.php', $content);

        $report = $this->runAnalyzer();

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $emailIssues = array_filter(
            $criticals,
            fn ($i) => str_contains($i->getMessage(), 'email'),
        );
        $this->assertCount(1, $emailIssues);
    }

    /**
     * SearchFilter avec strategy 'exact' sur un champ sensible est securise.
     * L'analyzer ne doit pas signaler de CRITICAL.
     */
    public function testSearchFilterExactOnSensitiveFieldDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Doctrine\\Orm\\Filter\\SearchFilter;\n"
            . "use ApiPlatform\\Metadata\\ApiFilter;\n\n"
            . "#[ApiResource]\n"
            . "#[ApiFilter(SearchFilter::class, properties: ['email' => 'exact'])]\n"
            . "class User {\n"
            . "    private string \$email;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/User.php', $content);

        $report = $this->runAnalyzer();

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $emailIssues = array_filter(
            $criticals,
            fn ($i) => str_contains($i->getMessage(), 'email'),
        );
        $this->assertCount(0, $emailIssues);
    }

    /**
     * SearchFilter avec strategy 'partial' sur un champ non sensible (name)
     * ne declenche pas d'alerte.
     */
    public function testSearchFilterPartialOnNormalFieldDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Doctrine\\Orm\\Filter\\SearchFilter;\n"
            . "use ApiPlatform\\Metadata\\ApiFilter;\n\n"
            . "#[ApiResource]\n"
            . "#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial'])]\n"
            . "class Product {\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(0, $criticals);
    }

    // --- Cas avec probleme : OrderFilter sans restriction ---

    /**
     * OrderFilter sans proprietes specifiees permet le tri sur n'importe
     * quelle colonne. L'analyzer doit signaler un WARNING.
     */
    public function testOrderFilterWithoutPropertiesCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Doctrine\\Orm\\Filter\\OrderFilter;\n"
            . "use ApiPlatform\\Metadata\\ApiFilter;\n\n"
            . "#[ApiResource]\n"
            . "#[ApiFilter(OrderFilter::class)]\n"
            . "class Article {\n"
            . "    private string \$title;\n"
            . "    private \\DateTimeImmutable \$createdAt;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Article.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $orderIssues = array_filter(
            $warnings,
            fn ($i) => str_contains($i->getMessage(), 'OrderFilter'),
        );
        $this->assertCount(1, $orderIssues);
    }

    /**
     * OrderFilter avec proprietes specifiees est correctement restreint.
     * L'analyzer ne doit pas signaler de WARNING.
     */
    public function testOrderFilterWithPropertiesDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Doctrine\\Orm\\Filter\\OrderFilter;\n"
            . "use ApiPlatform\\Metadata\\ApiFilter;\n\n"
            . "#[ApiResource]\n"
            . "#[ApiFilter(OrderFilter::class, properties: ['title', 'createdAt'])]\n"
            . "class Article {\n"
            . "    private string \$title;\n"
            . "    private \\DateTimeImmutable \$createdAt;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Article.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $orderIssues = array_filter(
            $warnings,
            fn ($i) => str_contains($i->getMessage(), 'OrderFilter'),
        );
        $this->assertCount(0, $orderIssues);
    }

    // --- Cas avec probleme : ressource sans aucun filtre ---

    /**
     * #[ApiResource] avec operations de collection mais sans aucun #[ApiFilter]
     * oblige le client a charger toutes les donnees. L'analyzer doit signaler une SUGGESTION.
     */
    public function testNoFiltersOnResourceCreatesSuggestion(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Metadata\\GetCollection;\n\n"
            . "#[ApiResource]\n"
            . "class Category {\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Category.php', $content);

        $report = $this->runAnalyzer();

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $filterIssues = array_filter(
            $suggestions,
            fn ($i) => str_contains($i->getMessage(), 'filtre'),
        );
        $this->assertCount(1, $filterIssues);
    }

    /**
     * #[ApiResource] avec au moins un #[ApiFilter] ne declenche pas de SUGGESTION.
     */
    public function testResourceWithFiltersDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Doctrine\\Orm\\Filter\\SearchFilter;\n"
            . "use ApiPlatform\\Metadata\\ApiFilter;\n\n"
            . "#[ApiResource]\n"
            . "#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial'])]\n"
            . "class Category {\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Category.php', $content);

        $report = $this->runAnalyzer();

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $filterIssues = array_filter(
            $suggestions,
            fn ($i) => str_contains($i->getMessage(), 'filtre'),
        );
        $this->assertCount(0, $filterIssues);
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue CRITICAL (SearchFilter partial sur champ sensible)
     * contient les champs d'enrichissement.
     */
    public function testEnrichmentFields(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Doctrine\\Orm\\Filter\\SearchFilter;\n"
            . "use ApiPlatform\\Metadata\\ApiFilter;\n\n"
            . "#[ApiResource]\n"
            . "#[ApiFilter(SearchFilter::class, properties: ['email' => 'partial'])]\n"
            . "class Account {\n"
            . "    private string \$email;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Account.php', $content);

        $report = $this->runAnalyzer();

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $emailIssues = array_values(array_filter(
            $criticals,
            fn ($i) => str_contains($i->getMessage(), 'email'),
        ));
        $this->assertCount(1, $emailIssues);

        $issue = $emailIssues[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('exact', $issue->getFixCode() ?? '');
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('api-platform.com', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(10, $issue->getEstimatedFixMinutes());
        $this->assertNotNull($issue->getFile());
    }

    // --- Metadata ---

    /**
     * Verifie le nom et le module de l'analyzer.
     */
    public function testGetNameAndGetModule(): void
    {
        $analyzer = new FilterConfigAnalyzer($this->tempDir);

        $this->assertSame('Filter Config Analyzer', $analyzer->getName());
        $this->assertSame(Module::API_PLATFORM, $analyzer->getModule());
    }

    // --- Helpers ---

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::API_PLATFORM]);
    }

    private function runAnalyzer(): AuditReport
    {
        $analyzer = new FilterConfigAnalyzer($this->tempDir);
        $report = $this->createReport();
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
