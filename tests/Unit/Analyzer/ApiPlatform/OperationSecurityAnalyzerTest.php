<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\OperationSecurityAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste la detection des ressources API Platform exposees sans controle d'acces.
 * L'analyzer verifie #[ApiResource] et les operations individuelles (#[Get], #[Post], etc.)
 * pour s'assurer qu'un attribut security est present.
 */
final class OperationSecurityAnalyzerTest extends TestCase
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
        $analyzer = new OperationSecurityAnalyzer($this->tempDir . '/nonexistent');
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
     * #[ApiResource] avec security present ne declenche pas d'alerte.
     */
    public function testApiResourceWithSecurityDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n\n"
            . "#[ApiResource(security: \"is_granted('ROLE_USER')\")]\n"
            . "class Product {\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        // Pas de CRITICAL pour l'ApiResource (security present).
        $criticals = array_filter(
            $report->getIssuesBySeverity(Severity::CRITICAL),
            fn ($i) => str_contains($i->getMessage(), '#[ApiResource]'),
        );
        $this->assertCount(0, $criticals);
    }

    // --- Cas avec probleme : ApiResource sans security ---

    /**
     * #[ApiResource] sans security expose toutes les operations CRUD sans controle.
     * L'analyzer doit signaler un CRITICAL.
     */
    public function testApiResourceWithoutSecurityCreatesCritical(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n\n"
            . "#[ApiResource]\n"
            . "class Product {\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertGreaterThanOrEqual(1, count($criticals));

        // Verifie qu'il y a un CRITICAL pour #[ApiResource] sans security.
        $apiResourceIssues = array_filter(
            $criticals,
            fn ($i) => str_contains($i->getMessage(), '#[ApiResource]'),
        );
        $this->assertCount(1, $apiResourceIssues);
    }

    // --- Cas avec probleme : operation individuelle sans security ---

    /**
     * #[Get] ou #[Post] sans security est un risque meme si ApiResource a security.
     */
    public function testOperationWithoutSecurityCreatesCritical(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Metadata\\Get;\n"
            . "use ApiPlatform\\Metadata\\Delete;\n\n"
            . "#[ApiResource(security: \"is_granted('ROLE_USER')\")]\n"
            . "#[Get]\n"
            . "#[Delete]\n"
            . "class Order {\n"
            . "    private int \$id;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Order.php', $content);

        $report = $this->runAnalyzer();

        // Les operations #[Get] et #[Delete] n'ont pas de security individuel.
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $getIssues = array_filter(
            $criticals,
            fn ($i) => str_contains($i->getMessage(), '#[Get]'),
        );
        $deleteIssues = array_filter(
            $criticals,
            fn ($i) => str_contains($i->getMessage(), '#[Delete]'),
        );
        $this->assertCount(1, $getIssues);
        $this->assertCount(1, $deleteIssues);
    }

    // --- Cas avec probleme : PUBLIC_ACCESS sur champ sensible ---

    /**
     * PUBLIC_ACCESS sur une entite contenant un champ sensible ($password)
     * doit signaler un WARNING.
     */
    public function testPublicAccessOnSensitiveFieldCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n\n"
            . "#[ApiResource(security: \"PUBLIC_ACCESS\")]\n"
            . "class User {\n"
            . "    private string \$email;\n"
            . "    private string \$password;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/User.php', $content);

        $report = $this->runAnalyzer();

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertGreaterThanOrEqual(1, count($warnings));
        $passwordIssues = array_filter(
            $warnings,
            fn ($i) => str_contains($i->getMessage(), 'password'),
        );
        $this->assertCount(1, $passwordIssues);
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue CRITICAL (ApiResource sans security) contient
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

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertGreaterThanOrEqual(1, count($criticals));

        // Prend la premiere issue CRITICAL (ApiResource sans security).
        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('security', $issue->getFixCode() ?? '');
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('api-platform.com', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertNotNull($issue->getFile());
    }

    // --- Helpers ---

    private function runAnalyzer(): AuditReport
    {
        $analyzer = new OperationSecurityAnalyzer($this->tempDir);
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
