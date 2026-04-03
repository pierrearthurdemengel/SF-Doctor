<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\SubresourceSecurityAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste la detection des sous-ressources API Platform (uriTemplate avec parametres)
 * sans controle de securite adequat.
 * Verifie aussi la detection des sous-ressources profondes (3+ niveaux de nesting).
 */
final class SubresourceSecurityAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_subresource_' . uniqid();
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
        $analyzer = new SubresourceSecurityAnalyzer($this->tempDir . '/nonexistent');
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Un fichier sans le mot-cle uriTemplate est ignore.
     */
    public function testNoUriTemplateDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Metadata\\Get;\n\n"
            . "#[ApiResource]\n"
            . "#[Get]\n"
            . "class Product {\n"
            . "    private int \$id;\n"
            . "    private string \$name;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Product.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Un uriTemplate avec un seul parametre (ex: /items/{id}) n'est pas
     * une sous-ressource, donc pas d'alerte.
     */
    public function testSingleParamUriTemplateDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Metadata\\Get;\n\n"
            . "#[ApiResource]\n"
            . "#[Get(uriTemplate: '/items/{id}')]\n"
            . "class Item {\n"
            . "    private int \$id;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Item.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Un uriTemplate avec 2 parametres mais un attribut security sur l'operation
     * ne declenche pas d'alerte.
     */
    public function testSubresourceWithSecurityDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Metadata\\GetCollection;\n\n"
            . "#[ApiResource]\n"
            . "#[GetCollection(uriTemplate: '/users/{userId}/orders/{orderId}', security: \"is_granted('ROLE_USER')\")]\n"
            . "class Order {\n"
            . "    private int \$id;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Order.php', $content);

        $report = $this->runAnalyzer();

        // Pas de WARNING pour sous-ressource sans securite (security est present).
        $warnings = array_filter(
            $report->getIssuesBySeverity(Severity::WARNING),
            fn ($i) => str_contains($i->getMessage(), 'sans securite'),
        );
        $this->assertCount(0, $warnings);
    }

    /**
     * Un uriTemplate avec 2 parametres sans security sur l'operation
     * mais avec security sur #[ApiResource] ne declenche pas d'alerte
     * pour la sous-ressource sans securite.
     */
    public function testSubresourceWithResourceSecurityDoesNothing(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Metadata\\GetCollection;\n\n"
            . "#[ApiResource(security: \"is_granted('ROLE_USER')\")]\n"
            . "#[GetCollection(uriTemplate: '/users/{userId}/orders/{orderId}')]\n"
            . "class Order {\n"
            . "    private int \$id;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Order.php', $content);

        $report = $this->runAnalyzer();

        // Pas d'alerte sous-ressource sans securite (ApiResource a security).
        $warnings = array_filter(
            $report->getIssuesBySeverity(Severity::WARNING),
            fn ($i) => str_contains($i->getMessage(), 'sans securite'),
        );
        $this->assertCount(0, $warnings);
    }

    // --- Cas avec probleme : sous-ressource sans securite ---

    /**
     * Un uriTemplate avec 2 parametres sans security nulle part
     * doit signaler un WARNING (faille IDOR potentielle).
     */
    public function testSubresourceWithoutSecurityCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Metadata\\Get;\n\n"
            . "#[ApiResource]\n"
            . "#[Get(uriTemplate: '/users/{userId}/orders/{orderId}')]\n"
            . "class Order {\n"
            . "    private int \$id;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Order.php', $content);

        $report = $this->runAnalyzer();

        $warnings = array_filter(
            $report->getIssuesBySeverity(Severity::WARNING),
            fn ($i) => str_contains($i->getMessage(), 'sans securite'),
        );
        $this->assertCount(1, $warnings);

        $issue = array_values($warnings)[0];
        $this->assertStringContainsString('/users/{userId}/orders/{orderId}', $issue->getMessage());
    }

    // --- Cas avec probleme : nesting profond ---

    /**
     * Un uriTemplate avec 3+ parametres declenche un WARNING
     * pour nesting profond (complexite d'autorisation).
     */
    public function testDeepNestingCreatesWarning(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Metadata\\GetCollection;\n\n"
            . "#[ApiResource]\n"
            . "#[GetCollection(uriTemplate: '/a/{aId}/b/{bId}/c/{cId}')]\n"
            . "class DeepEntity {\n"
            . "    private int \$id;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/DeepEntity.php', $content);

        $report = $this->runAnalyzer();

        $warnings = array_filter(
            $report->getIssuesBySeverity(Severity::WARNING),
            fn ($i) => str_contains($i->getMessage(), 'profonde'),
        );
        $this->assertCount(1, $warnings);

        $issue = array_values($warnings)[0];
        $this->assertStringContainsString('3 niveaux', $issue->getMessage());
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue WARNING (sous-ressource sans securite)
     * contient les champs d'enrichissement.
     */
    public function testEnrichmentFields(): void
    {
        $content = "<?php\nnamespace App\\Entity;\n\n"
            . "use ApiPlatform\\Metadata\\ApiResource;\n"
            . "use ApiPlatform\\Metadata\\Get;\n\n"
            . "#[ApiResource]\n"
            . "#[Get(uriTemplate: '/users/{userId}/profile/{profileId}')]\n"
            . "class Profile {\n"
            . "    private int \$id;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Entity/Profile.php', $content);

        $report = $this->runAnalyzer();

        $warnings = array_filter(
            $report->getIssuesBySeverity(Severity::WARNING),
            fn ($i) => str_contains($i->getMessage(), 'sans securite'),
        );
        $this->assertGreaterThanOrEqual(1, count($warnings));

        $issue = array_values($warnings)[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('security', $issue->getFixCode() ?? '');
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
        $analyzer = new SubresourceSecurityAnalyzer($this->tempDir);

        $this->assertSame('Subresource Security Analyzer', $analyzer->getName());
        $this->assertSame(Module::API_PLATFORM, $analyzer->getModule());
    }

    // --- Helpers ---

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::API_PLATFORM]);
    }

    private function runAnalyzer(): AuditReport
    {
        $analyzer = new SubresourceSecurityAnalyzer($this->tempDir);
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
