<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\NormalizationContextAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste la detection des incoherences de normalization/denormalization context
 * dans les ressources API Platform.
 * Trois verifications : absence de denormalizationContext avec operations d'ecriture,
 * groupes identiques en lecture et ecriture, et groupes orphelins.
 */
final class NormalizationContextAnalyzerTest extends TestCase
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
        $analyzer = new NormalizationContextAnalyzer($this->tempDir . '/nonexistent');
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
            . "    private string \$key;\n"
            . "    private string \$value;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/InternalConfig.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Une entite avec normalizationContext et denormalizationContext differents
     * ne declenche pas d'alerte.
     */
    public function testBothContextsDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Symfony\\Component\\Serializer\\Annotation\\Groups;\n\n"
            . "#[ApiResource(normalizationContext: ['groups' => ['read']], denormalizationContext: ['groups' => ['write']])]\n"
            . "class Product {\n"
            . "    #[Groups(['read', 'write'])]\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        // Pas de warning pour denormalizationContext manquant ni groupes identiques.
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $contextIssues = array_filter(
            $warnings,
            fn ($i) => str_contains($i->getMessage(), 'denormalizationContext')
                || str_contains($i->getMessage(), 'Memes groupes'),
        );
        $this->assertCount(0, $contextIssues);
    }

    // --- Cas avec probleme : denormalizationContext manquant ---

    /**
     * normalizationContext sans denormalizationContext avec une operation d'ecriture (#[Post])
     * doit declencher un WARNING.
     */
    public function testMissingDenormalizationWithWriteOpCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Metadata\\Post;\n\n"
            . "#[ApiResource(normalizationContext: ['groups' => ['read']])]\n"
            . "#[Post]\n"
            . "class Order {\n"
            . "    private string \$reference;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Order.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $denormIssues = array_filter(
            $warnings,
            fn ($i) => str_contains($i->getMessage(), 'denormalizationContext'),
        );
        $this->assertCount(1, $denormIssues);
    }

    /**
     * normalizationContext sans denormalizationContext mais uniquement des operations
     * en lecture (#[Get]) ne declenche pas d'alerte.
     */
    public function testMissingDenormalizationWithoutWriteOpDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Metadata\\Get;\n"
            . "use ApiPlatform\\Metadata\\GetCollection;\n\n"
            . "#[ApiResource(operations: [new Get(), new GetCollection()], normalizationContext: ['groups' => ['read']])]\n"
            . "#[Get]\n"
            . "class Report {\n"
            . "    private string \$title;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Report.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $denormIssues = array_filter(
            $warnings,
            fn ($i) => str_contains($i->getMessage(), 'denormalizationContext'),
        );
        $this->assertCount(0, $denormIssues);
    }

    // --- Cas avec probleme : groupes identiques ---

    /**
     * Memes groupes ['user'] dans normalizationContext et denormalizationContext.
     * Toutes les proprietes lisibles sont aussi modifiables.
     * L'analyzer doit signaler un WARNING.
     */
    public function testIdenticalGroupsCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Symfony\\Component\\Serializer\\Annotation\\Groups;\n\n"
            . "#[ApiResource(normalizationContext: ['groups' => ['user']], denormalizationContext: ['groups' => ['user']])]\n"
            . "class Customer {\n"
            . "    #[Groups(['user'])]\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Customer.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $identicalIssues = array_filter(
            $warnings,
            fn ($i) => str_contains($i->getMessage(), 'Memes groupes'),
        );
        $this->assertCount(1, $identicalIssues);
    }

    /**
     * Groupes differents ('read' vs 'write') ne declenchent pas d'alerte.
     */
    public function testDifferentGroupsDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Symfony\\Component\\Serializer\\Annotation\\Groups;\n\n"
            . "#[ApiResource(normalizationContext: ['groups' => ['read']], denormalizationContext: ['groups' => ['write']])]\n"
            . "class Invoice {\n"
            . "    #[Groups(['read', 'write'])]\n"
            . "    private string \$amount;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Invoice.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $identicalIssues = array_filter(
            $warnings,
            fn ($i) => str_contains($i->getMessage(), 'Memes groupes'),
        );
        $this->assertCount(0, $identicalIssues);
    }

    // --- Cas avec probleme : groupe orphelin ---

    /**
     * Le groupe 'read' est reference dans normalizationContext mais aucune propriete
     * ne porte #[Groups(['read'])]. L'API retournera un objet vide.
     * L'analyzer doit signaler un WARNING.
     */
    public function testOrphanedGroupCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n\n"
            . "#[ApiResource(normalizationContext: ['groups' => ['read']])]\n"
            . "class Warehouse {\n"
            . "    private string \$location;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Warehouse.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $orphanIssues = array_filter(
            $warnings,
            fn ($i) => str_contains($i->getMessage(), 'absent des proprietes'),
        );
        $this->assertCount(1, $orphanIssues);
        $this->assertStringContainsString('read', array_values($orphanIssues)[0]->getMessage());
    }

    /**
     * Le groupe 'read' est reference dans le context et une propriete porte
     * #[Groups(['read'])]. Pas de warning pour groupe orphelin.
     */
    public function testMatchingGroupDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Symfony\\Component\\Serializer\\Annotation\\Groups;\n\n"
            . "#[ApiResource(normalizationContext: ['groups' => ['read']])]\n"
            . "class Category {\n"
            . "    #[Groups(['read'])]\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Category.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $orphanIssues = array_filter(
            $warnings,
            fn ($i) => str_contains($i->getMessage(), 'absent des proprietes'),
        );
        $this->assertCount(0, $orphanIssues);
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue WARNING contient les champs d'enrichissement.
     */
    public function testEnrichmentFields(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n\n"
            . "#[ApiResource(normalizationContext: ['groups' => ['read']])]\n"
            . "class Shipment {\n"
            . "    private string \$tracking;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Shipment.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertGreaterThanOrEqual(1, count($warnings));

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('api-platform.com', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertNotNull($issue->getFile());
    }

    // --- Nom et module ---

    /**
     * Verifie le nom et le module de l'analyzer.
     */
    public function testGetNameAndGetModule(): void
    {
        $analyzer = new NormalizationContextAnalyzer($this->tempDir);

        $this->assertSame('Normalization Context Analyzer', $analyzer->getName());
        $this->assertSame(Module::API_PLATFORM, $analyzer->getModule());
    }

    // --- Helpers ---

    private function runAnalyzer(): AuditReport
    {
        $analyzer = new NormalizationContextAnalyzer($this->tempDir);
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
