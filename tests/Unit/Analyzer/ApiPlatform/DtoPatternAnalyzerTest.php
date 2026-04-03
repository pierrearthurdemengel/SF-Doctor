<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\DtoPatternAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste la detection des entites Doctrine exposees directement comme
 * ressources API Platform sans couche DTO (input/output).
 * Deux niveaux : SUGGESTION pour les entites simples, WARNING au-dela de 8 proprietes.
 */
final class DtoPatternAnalyzerTest extends TestCase
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
        $analyzer = new DtoPatternAnalyzer($this->tempDir . '/nonexistent');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Une entite Doctrine sans #[ApiResource] n'est pas analysee
     * (pas exposee dans l'API).
     */
    public function testEntityWithoutApiResourceDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use Doctrine\\ORM\\Mapping as ORM;\n\n"
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
     * Une classe #[ApiResource] sans #[ORM\Entity] n'est pas une entite Doctrine,
     * donc pas de risque de couplage schema/API.
     */
    public function testApiResourceWithoutDoctrineDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n\n"
            . "#[ApiResource]\n"
            . "class ProductDto {\n"
            . "    private string \$name;\n"
            . "    private string \$description;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/ProductDto.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Une entite Doctrine exposee avec output: configure utilise deja un DTO.
     * L'analyzer ne doit pas signaler de probleme.
     */
    public function testEntityWithDtoDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ORM\\Entity]\n"
            . "#[ApiResource(\n"
            . "    output: ProductOutput::class,\n"
            . ")]\n"
            . "class Product {\n"
            . "    #[ORM\\Id]\n"
            . "    private int \$id;\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$name;\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$description;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    // --- Cas avec probleme : entite simple sans DTO ---

    /**
     * Une entite simple (<=8 proprietes) exposee sans DTO genere une SUGGESTION.
     */
    public function testSimpleEntityWithoutDtoCreatesSuggestion(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ORM\\Entity]\n"
            . "#[ApiResource]\n"
            . "class Tag {\n"
            . "    #[ORM\\Id]\n"
            . "    private int \$id;\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$name;\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$slug;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Tag.php', $content);

        $report = $this->runAnalyzer();

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(1, $suggestions);
        $this->assertStringContainsString('Tag', $suggestions[0]->getMessage());
        $this->assertStringContainsString('sans DTO', $suggestions[0]->getMessage());
    }

    // --- Cas avec probleme : entite complexe sans DTO ---

    /**
     * Une entite complexe (>8 proprietes) exposee sans DTO genere un WARNING.
     */
    public function testComplexEntityWithoutDtoCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ORM\\Entity]\n"
            . "#[ApiResource]\n"
            . "class Order {\n"
            . "    #[ORM\\Id]\n"
            . "    private int \$id;\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$reference;\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$status;\n"
            . "    #[ORM\\Column]\n"
            . "    private float \$total;\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$currency;\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$customerEmail;\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$shippingAddress;\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$billingAddress;\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$notes;\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$trackingNumber;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Order.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Order', $warnings[0]->getMessage());
        $this->assertStringContainsString('10', $warnings[0]->getMessage());
        $this->assertStringContainsString('sans DTO', $warnings[0]->getMessage());
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue contient les champs d'enrichissement attendus
     * (fixCode, docUrl, businessImpact, estimatedFixMinutes).
     */
    public function testEnrichmentFields(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ORM\\Entity]\n"
            . "#[ApiResource]\n"
            . "class Invoice {\n"
            . "    #[ORM\\Id]\n"
            . "    private int \$id;\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$reference;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Invoice.php', $content);

        $report = $this->runAnalyzer();

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(1, $suggestions);

        $issue = $suggestions[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('output:', $issue->getFixCode() ?? '');
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('api-platform.com', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertNotNull($issue->getFile());
    }

    // --- Metadata ---

    /**
     * Verifie le nom et le module de l'analyzer.
     */
    public function testGetNameAndGetModule(): void
    {
        $analyzer = new DtoPatternAnalyzer($this->tempDir);

        $this->assertSame('DTO Pattern Analyzer', $analyzer->getName());
        $this->assertSame(Module::API_PLATFORM, $analyzer->getModule());
    }

    // --- Helpers ---

    private function runAnalyzer(): AuditReport
    {
        $analyzer = new DtoPatternAnalyzer($this->tempDir);
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
