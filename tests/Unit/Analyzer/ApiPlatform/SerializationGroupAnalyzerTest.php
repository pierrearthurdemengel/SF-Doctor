<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\SerializationGroupAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste la detection des problemes de serialisation dans les entites API Platform.
 * Deux analyses : absence de #[Groups] et proprietes sensibles sans #[Ignore].
 */
final class SerializationGroupAnalyzerTest extends TestCase
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
        $analyzer = new SerializationGroupAnalyzer($this->tempDir . '/nonexistent');
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
     * Une entite #[ApiResource] avec #[Groups] sur les proprietes est correcte.
     */
    public function testApiResourceWithGroupsDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Symfony\\Component\\Serializer\\Annotation\\Groups;\n\n"
            . "#[ApiResource(\n"
            . "    normalizationContext: ['groups' => ['read']],\n"
            . ")]\n"
            . "class Product {\n"
            . "    #[Groups(['read'])]\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        // Pas de CRITICAL pour les groupes manquants (il y a #[Groups]).
        $groupIssues = array_filter(
            $report->getIssuesBySeverity(Severity::CRITICAL),
            fn ($i) => str_contains($i->getMessage(), '#[Groups]'),
        );
        $this->assertCount(0, $groupIssues);
    }

    /**
     * Une propriete sensible avec #[Ignore] est correctement protegee.
     */
    public function testSensitivePropertyWithIgnoreDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Symfony\\Component\\Serializer\\Annotation\\Groups;\n"
            . "use Symfony\\Component\\Serializer\\Annotation\\Ignore;\n\n"
            . "#[ApiResource]\n"
            . "class User {\n"
            . "    #[Groups(['read'])]\n"
            . "    private string \$email;\n\n"
            . "    #[Ignore]\n"
            . "    private string \$password;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/User.php', $content);

        $report = $this->runAnalyzer();

        // Pas de CRITICAL pour la propriete password (elle a #[Ignore]).
        $passwordIssues = array_filter(
            $report->getIssuesBySeverity(Severity::CRITICAL),
            fn ($i) => str_contains($i->getMessage(), 'password'),
        );
        $this->assertCount(0, $passwordIssues);
    }

    // --- Cas avec probleme : absence de #[Groups] ---

    /**
     * #[ApiResource] sans aucun #[Groups] sur les proprietes expose tout.
     * L'analyzer doit signaler un CRITICAL.
     */
    public function testApiResourceWithoutGroupsCreatesCritical(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n\n"
            . "#[ApiResource]\n"
            . "class Customer {\n"
            . "    private string \$name;\n"
            . "    private string \$internalNotes;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Customer.php', $content);

        $report = $this->runAnalyzer();

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $groupIssues = array_filter(
            $criticals,
            fn ($i) => str_contains($i->getMessage(), '#[Groups]'),
        );
        $this->assertCount(1, $groupIssues);
    }

    // --- Cas avec probleme : propriete sensible sans #[Ignore] ---

    /**
     * $password sans #[Ignore] dans une entite API Platform est dangereux.
     * L'analyzer doit signaler un CRITICAL.
     */
    public function testSensitivePropertyWithoutIgnoreCreatesCritical(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use Symfony\\Component\\Serializer\\Annotation\\Groups;\n\n"
            . "#[ApiResource]\n"
            . "class Account {\n"
            . "    #[Groups(['read'])]\n"
            . "    private string \$username;\n\n"
            . "    private string \$token;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Account.php', $content);

        $report = $this->runAnalyzer();

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $tokenIssues = array_filter(
            $criticals,
            fn ($i) => str_contains($i->getMessage(), 'token'),
        );
        $this->assertCount(1, $tokenIssues);
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue CRITICAL (absence de groups) contient les champs d'enrichissement.
     */
    public function testEnrichmentFields(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n\n"
            . "#[ApiResource]\n"
            . "class Warehouse {\n"
            . "    private string \$location;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Warehouse.php', $content);

        $report = $this->runAnalyzer();

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $groupIssues = array_values(array_filter(
            $criticals,
            fn ($i) => str_contains($i->getMessage(), '#[Groups]'),
        ));
        $this->assertCount(1, $groupIssues);

        $issue = $groupIssues[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('Groups', $issue->getFixCode() ?? '');
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('api-platform.com', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(30, $issue->getEstimatedFixMinutes());
        $this->assertNotNull($issue->getFile());
    }

    // --- Helpers ---

    private function runAnalyzer(): AuditReport
    {
        $analyzer = new SerializationGroupAnalyzer($this->tempDir);
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
