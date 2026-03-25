<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Twig;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Twig\BusinessLogicInTwigAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Tests unitaires pour BusinessLogicInTwigAnalyzer.
 *
 * Verifie la detection de logique metier dans les templates Twig :
 * blocs set complexes et appels de repository.
 */
final class BusinessLogicInTwigAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport($this->tempDir, [Module::TWIG]);
    }

    // =============================================
    // Test 1 : Pas de dossier templates/ - rien ne se passe
    // =============================================

    public function testNoTemplateDirDoesNothing(): void
    {
        mkdir($this->tempDir, 0777, true);

        $analyzer = new BusinessLogicInTwigAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 2 : Appel de repository dans un template - CRITICAL
    // =============================================

    public function testRepositoryCallCreatesCritical(): void
    {
        mkdir($this->tempDir . '/templates', 0777, true);
        $template = <<<'TWIG'
        <ul>
        {% set users = repository.findAll() %}
        {% for user in users %}
            <li>{{ user.name }}</li>
        {% endfor %}
        </ul>
        TWIG;
        file_put_contents($this->tempDir . '/templates/list.html.twig', $template);

        $analyzer = new BusinessLogicInTwigAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('repository', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 3 : findBy() dans un template - CRITICAL
    // =============================================

    public function testFindByCallCreatesCritical(): void
    {
        mkdir($this->tempDir . '/templates', 0777, true);
        $template = '<div>{% set items = repo.findBy({"active": true}) %}</div>';
        file_put_contents($this->tempDir . '/templates/items.html.twig', $template);

        $analyzer = new BusinessLogicInTwigAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
    }

    // =============================================
    // Test 4 : Bloc set complexe (> 3 conditions) - WARNING
    // =============================================

    public function testComplexSetBlockCreatesWarning(): void
    {
        mkdir($this->tempDir . '/templates', 0777, true);
        // Expression avec 4+ operateurs de condition (and, or, ?, is defined).
        $template = '{% set result = (a and b) or (c and d) ? "yes" : (e is defined and f or g ? "maybe" : "no") %}';
        file_put_contents($this->tempDir . '/templates/complex.html.twig', $template);

        $analyzer = new BusinessLogicInTwigAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('set', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 5 : Bloc set simple (<= 3 conditions) - pas de warning
    // =============================================

    public function testSimpleSetBlockDoesNothing(): void
    {
        mkdir($this->tempDir . '/templates', 0777, true);
        $template = '{% set active = user is defined and user.active ? true : false %}';
        file_put_contents($this->tempDir . '/templates/simple.html.twig', $template);

        $analyzer = new BusinessLogicInTwigAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(0, $warnings);
    }

    // =============================================
    // Test 6 : Template propre sans logique metier
    // =============================================

    public function testCleanTemplateDoesNothing(): void
    {
        mkdir($this->tempDir . '/templates', 0777, true);
        $template = <<<'TWIG'
        <h1>{{ title }}</h1>
        <ul>
        {% for item in items %}
            <li>{{ item.name }}</li>
        {% endfor %}
        </ul>
        TWIG;
        file_put_contents($this->tempDir . '/templates/clean.html.twig', $template);

        $analyzer = new BusinessLogicInTwigAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 7 : Verification des metadonnees
    // =============================================

    public function testGetModuleReturnsTwig(): void
    {
        mkdir($this->tempDir, 0777, true);
        $analyzer = new BusinessLogicInTwigAnalyzer($this->tempDir);
        $this->assertSame(Module::TWIG, $analyzer->getModule());
    }

    public function testGetNameReturnsExpectedName(): void
    {
        mkdir($this->tempDir, 0777, true);
        $analyzer = new BusinessLogicInTwigAnalyzer($this->tempDir);
        $this->assertSame('Business Logic In Twig Analyzer', $analyzer->getName());
    }

    // =============================================
    // Test 8 : Champs d'enrichissement sur appel de repository
    // =============================================

    public function testRepositoryCallIssueHasEnrichmentFields(): void
    {
        mkdir($this->tempDir . '/templates', 0777, true);
        $template = '{% set users = repository.findAll() %}';
        file_put_contents($this->tempDir . '/templates/repo.html.twig', $template);

        $analyzer = new BusinessLogicInTwigAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertSame(30, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('template', $issue->getDocUrl() ?? '');
    }

    // =============================================
    // Test 9 : Champs d'enrichissement sur bloc set complexe
    // =============================================

    public function testComplexSetIssueHasEnrichmentFields(): void
    {
        mkdir($this->tempDir . '/templates', 0777, true);
        $template = '{% set result = (a and b) or (c and d) ? "yes" : (e is defined and f or g ? "maybe" : "no") %}';
        file_put_contents($this->tempDir . '/templates/complex2.html.twig', $template);

        $analyzer = new BusinessLogicInTwigAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertSame(30, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('twig_extension', $issue->getDocUrl() ?? '');
    }

    // =============================================
    // Test 10 : findOneBy() detecte aussi
    // =============================================

    public function testFindOneByCallCreatesCritical(): void
    {
        mkdir($this->tempDir . '/templates', 0777, true);
        $template = '{% set user = userRepo.findOneBy({"email": email}) %}';
        file_put_contents($this->tempDir . '/templates/find.html.twig', $template);

        $analyzer = new BusinessLogicInTwigAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
    }
}
