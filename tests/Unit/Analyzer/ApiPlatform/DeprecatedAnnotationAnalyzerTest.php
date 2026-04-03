<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\DeprecatedAnnotationAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste la detection des annotations API Platform 2.x depreciees.
 * L'analyzer scanne src/Entity/ et src/ApiResource/ pour trouver
 * les annotations @ApiResource, @ApiFilter, @ApiSubresource et @ApiProperty
 * qui doivent etre migrees vers les attributs PHP 8.1+.
 */
final class DeprecatedAnnotationAnalyzerTest extends TestCase
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
        $analyzer = new DeprecatedAnnotationAnalyzer($this->tempDir . '/nonexistent');
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Un fichier utilisant les attributs PHP 8.1+ (#[ApiResource]) ne doit
     * pas declencher d'alerte. Seules les annotations PHPDoc sont depreciees.
     */
    public function testModernAttributeDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n\n"
            . "#[ApiResource]\n"
            . "class Product {\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    // --- Cas avec probleme : annotations depreciees ---

    /**
     * L'annotation @ApiResource dans un bloc PHPDoc doit signaler un WARNING.
     */
    public function testDeprecatedApiResourceAnnotationCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Core\\Annotation\\ApiResource;\n\n"
            . "/**\n"
            . " * @ApiResource\n"
            . " */\n"
            . "class Product {\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('@ApiResource', $warnings[0]->getMessage());
        $this->assertStringContainsString('depreciee', $warnings[0]->getMessage());
    }

    /**
     * L'annotation @ApiFilter dans un bloc PHPDoc doit signaler un WARNING.
     */
    public function testDeprecatedApiFilterAnnotationCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Core\\Annotation\\ApiFilter;\n\n"
            . "/**\n"
            . " * @ApiFilter(SearchFilter::class, properties={\"name\": \"partial\"})\n"
            . " */\n"
            . "class Product {\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('@ApiFilter', $warnings[0]->getMessage());
    }

    /**
     * L'annotation @ApiSubresource est completement supprimee en API Platform 3.x.
     * Le message doit mentionner explicitement cette suppression.
     */
    public function testDeprecatedApiSubresourceCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Core\\Annotation\\ApiSubresource;\n\n"
            . "class Order {\n"
            . "    /**\n"
            . "     * @ApiSubresource\n"
            . "     */\n"
            . "    private Collection \$items;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Order.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('@ApiSubresource', $warnings[0]->getMessage());
        $this->assertStringContainsString('supprime en API Platform 3.x', $warnings[0]->getMessage());
    }

    /**
     * L'annotation @ApiProperty dans un bloc PHPDoc doit signaler un WARNING.
     */
    public function testDeprecatedApiPropertyCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Core\\Annotation\\ApiProperty;\n\n"
            . "class Product {\n"
            . "    /**\n"
            . "     * @ApiProperty(readable=true, writable=false)\n"
            . "     */\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('@ApiProperty', $warnings[0]->getMessage());
    }

    /**
     * Un fichier contenant plusieurs annotations depreciees doit generer
     * autant de warnings que d'annotations trouvees.
     */
    public function testMultipleAnnotationsCreateMultipleWarnings(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Core\\Annotation\\ApiResource;\n"
            . "use ApiPlatform\\Core\\Annotation\\ApiFilter;\n\n"
            . "/**\n"
            . " * @ApiResource\n"
            . " * @ApiFilter(SearchFilter::class)\n"
            . " */\n"
            . "class Product {\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(2, $warnings);

        // Verifie que les deux annotations sont bien detectees.
        $messages = array_map(fn ($i) => $i->getMessage(), $warnings);
        $combined = implode(' ', $messages);
        $this->assertStringContainsString('@ApiResource', $combined);
        $this->assertStringContainsString('@ApiFilter', $combined);
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue WARNING contient les champs d'enrichissement :
     * fixCode, docUrl, businessImpact et le numero de ligne.
     */
    public function testEnrichmentFields(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Core\\Annotation\\ApiResource;\n\n"
            . "/**\n"
            . " * @ApiResource\n"
            . " */\n"
            . "class Invoice {\n"
            . "    private string \$reference;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Invoice.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('@ApiResource', $issue->getFixCode() ?? '');
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('api-platform.com', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(7, $issue->getLine());
        $this->assertNotNull($issue->getFile());
    }

    // --- getName et getModule ---

    /**
     * Verifie que getName() et getModule() retournent les valeurs attendues.
     */
    public function testGetNameAndGetModule(): void
    {
        $analyzer = new DeprecatedAnnotationAnalyzer($this->tempDir);

        $this->assertSame('Deprecated Annotation Analyzer', $analyzer->getName());
        $this->assertSame(Module::API_PLATFORM, $analyzer->getModule());
    }

    // --- Helpers ---

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::API_PLATFORM]);
    }

    private function runAnalyzer(): AuditReport
    {
        $analyzer = new DeprecatedAnnotationAnalyzer($this->tempDir);
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
