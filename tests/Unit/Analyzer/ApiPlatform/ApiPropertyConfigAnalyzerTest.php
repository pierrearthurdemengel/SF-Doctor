<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\ApiPropertyConfigAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste la detection des problemes de configuration des proprietes API Platform.
 * Deux analyses : identifiant auto-increment sans UUID et identifiant dans un groupe d'ecriture.
 */
final class ApiPropertyConfigAnalyzerTest extends TestCase
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
        $analyzer = new ApiPropertyConfigAnalyzer($this->tempDir . '/nonexistent');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);

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
            . "    #[ORM\\Id]\n"
            . "    #[ORM\\GeneratedValue]\n"
            . "    #[ORM\\Column]\n"
            . "    private ?int \$id = null;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/InternalConfig.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    // --- Cas auto-increment sans UUID ---

    /**
     * #[ORM\GeneratedValue] avec une propriete Uuid ne declenche pas d'alerte.
     * L'analyzer detecte l'alternative UUID et ne signale rien.
     */
    public function testAutoIncrementWithUuidDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Doctrine\\ORM\\Mapping as ORM;\n"
            . "use Symfony\\Component\\Uid\\Uuid;\n\n"
            . "#[ApiResource]\n"
            . "#[ORM\\Entity]\n"
            . "class Product {\n"
            . "    #[ORM\\Id]\n"
            . "    #[ORM\\GeneratedValue]\n"
            . "    #[ORM\\Column]\n"
            . "    private ?int \$id = null;\n\n"
            . "    #[ORM\\Column(type: 'uuid', unique: true)]\n"
            . "    private Uuid \$uuid;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        // Pas de SUGGESTION pour l'auto-increment (UUID present).
        $suggestions = array_filter(
            $report->getIssuesBySeverity(Severity::SUGGESTION),
            fn ($i) => str_contains($i->getMessage(), 'auto-increment'),
        );
        $this->assertCount(0, $suggestions);
    }

    /**
     * #[ORM\GeneratedValue] sans UUID ni ULID declenche une SUGGESTION.
     * Les IDs sequentiels dans l'URL facilitent les attaques IDOR.
     */
    public function testAutoIncrementWithoutUuidCreatesSuggestion(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ApiResource]\n"
            . "#[ORM\\Entity]\n"
            . "class Order {\n"
            . "    #[ORM\\Id]\n"
            . "    #[ORM\\GeneratedValue]\n"
            . "    #[ORM\\Column]\n"
            . "    private ?int \$id = null;\n\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$reference;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Order.php', $content);

        $report = $this->runAnalyzer();

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $autoIncrementIssues = array_filter(
            $suggestions,
            fn ($i) => str_contains($i->getMessage(), 'auto-increment'),
        );
        $this->assertCount(1, $autoIncrementIssues);
    }

    /**
     * Sans #[ORM\GeneratedValue], l'analyzer ne signale pas de probleme
     * d'auto-increment.
     */
    public function testNoAutoIncrementDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ApiResource]\n"
            . "#[ORM\\Entity]\n"
            . "class Category {\n"
            . "    #[ORM\\Id]\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$code;\n\n"
            . "    #[ORM\\Column]\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Category.php', $content);

        $report = $this->runAnalyzer();

        // Pas de SUGGESTION pour l'auto-increment (pas de GeneratedValue).
        $suggestions = array_filter(
            $report->getIssuesBySeverity(Severity::SUGGESTION),
            fn ($i) => str_contains($i->getMessage(), 'auto-increment'),
        );
        $this->assertCount(0, $suggestions);
    }

    /**
     * #[ApiProperty(identifier: true)] sur un champ non-id indique un identifiant
     * personnalise. L'analyzer ne signale pas de probleme d'auto-increment.
     */
    public function testCustomIdentifierDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Metadata\\ApiProperty;\n"
            . "use Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ApiResource]\n"
            . "#[ORM\\Entity]\n"
            . "class Article {\n"
            . "    #[ORM\\Id]\n"
            . "    #[ORM\\GeneratedValue]\n"
            . "    #[ORM\\Column]\n"
            . "    private ?int \$id = null;\n\n"
            . "    #[ApiProperty(identifier: true)]\n"
            . "    private string \$slug;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Article.php', $content);

        $report = $this->runAnalyzer();

        // Pas de SUGGESTION (identifiant personnalise sur $slug).
        $suggestions = array_filter(
            $report->getIssuesBySeverity(Severity::SUGGESTION),
            fn ($i) => str_contains($i->getMessage(), 'auto-increment'),
        );
        $this->assertCount(0, $suggestions);
    }

    // --- Cas identifiant dans un groupe d'ecriture ---

    /**
     * #[ORM\Id] avec #[Groups(['read', 'write'])] expose l'identifiant en ecriture.
     * L'analyzer doit signaler un WARNING.
     */
    public function testWritableIdentifierCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Doctrine\\ORM\\Mapping as ORM;\n"
            . "use Symfony\\Component\\Serializer\\Annotation\\Groups;\n\n"
            . "#[ApiResource]\n"
            . "#[ORM\\Entity]\n"
            . "class Invoice {\n"
            . "    #[ORM\\Id]\n"
            . "    #[Groups(['read', 'write'])]\n"
            . "    #[ORM\\Column]\n"
            . "    private ?int \$id = null;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Invoice.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $writableIdIssues = array_filter(
            $warnings,
            fn ($i) => str_contains($i->getMessage(), 'groupe'),
        );
        $this->assertCount(1, $writableIdIssues);
    }

    /**
     * #[ORM\Id] avec #[Groups(['read'])] uniquement ne pose pas de probleme.
     * L'identifiant n'est pas modifiable par le client.
     */
    public function testReadOnlyIdentifierDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Doctrine\\ORM\\Mapping as ORM;\n"
            . "use Symfony\\Component\\Serializer\\Annotation\\Groups;\n\n"
            . "#[ApiResource]\n"
            . "#[ORM\\Entity]\n"
            . "class Payment {\n"
            . "    #[ORM\\Id]\n"
            . "    #[Groups(['read'])]\n"
            . "    #[ORM\\Column]\n"
            . "    private ?int \$id = null;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Payment.php', $content);

        $report = $this->runAnalyzer();

        // Pas de WARNING pour l'identifiant (lecture seule).
        $warnings = array_filter(
            $report->getIssuesBySeverity(Severity::WARNING),
            fn ($i) => str_contains($i->getMessage(), 'groupe'),
        );
        $this->assertCount(0, $warnings);
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue SUGGESTION (auto-increment) contient les champs
     * d'enrichissement requis.
     */
    public function testEnrichmentFields(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Doctrine\\ORM\\Mapping as ORM;\n\n"
            . "#[ApiResource]\n"
            . "#[ORM\\Entity]\n"
            . "class Customer {\n"
            . "    #[ORM\\Id]\n"
            . "    #[ORM\\GeneratedValue]\n"
            . "    #[ORM\\Column]\n"
            . "    private ?int \$id = null;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Customer.php', $content);

        $report = $this->runAnalyzer();

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $autoIncrementIssues = array_values(array_filter(
            $suggestions,
            fn ($i) => str_contains($i->getMessage(), 'auto-increment'),
        ));
        $this->assertCount(1, $autoIncrementIssues);

        $issue = $autoIncrementIssues[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('Uuid', $issue->getFixCode() ?? '');
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('api-platform.com', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(30, $issue->getEstimatedFixMinutes());
        $this->assertNotNull($issue->getFile());
    }

    // --- Nom et module ---

    /**
     * Verifie le nom et le module de l'analyzer.
     */
    public function testGetNameAndGetModule(): void
    {
        $analyzer = new ApiPropertyConfigAnalyzer($this->tempDir);

        $this->assertSame('API Property Config Analyzer', $analyzer->getName());
        $this->assertSame(Module::API_PLATFORM, $analyzer->getModule());
    }

    // --- Helpers ---

    private function runAnalyzer(): AuditReport
    {
        $analyzer = new ApiPropertyConfigAnalyzer($this->tempDir);
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
