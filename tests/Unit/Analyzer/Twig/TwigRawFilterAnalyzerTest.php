<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Twig;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Twig\TwigRawFilterAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Tests unitaires pour TwigRawFilterAnalyzer.
 *
 * Verifie la detection du filtre |raw dans les templates Twig,
 * avec distinction entre usage general (WARNING) et usage sur
 * donnees utilisateur (CRITICAL).
 */
final class TwigRawFilterAnalyzerTest extends TestCase
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
        // Creer le dossier racine mais pas templates/.
        mkdir($this->tempDir, 0777, true);

        $analyzer = new TwigRawFilterAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 2 : |raw sur variable standard - WARNING
    // =============================================

    public function testRawFilterCreatesWarning(): void
    {
        mkdir($this->tempDir . '/templates', 0777, true);
        file_put_contents(
            $this->tempDir . '/templates/page.html.twig',
            '<div>{{ variable|raw }}</div>',
        );

        $analyzer = new TwigRawFilterAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('|raw', $warnings[0]->getMessage());
        $this->assertStringContainsString('page.html.twig', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 3 : |raw sur donnee utilisateur (form.data) - CRITICAL
    // =============================================

    public function testRawOnUserInputCreatesCritical(): void
    {
        mkdir($this->tempDir . '/templates', 0777, true);
        file_put_contents(
            $this->tempDir . '/templates/form.html.twig',
            '<div>{{ form.data|raw }}</div>',
        );

        $analyzer = new TwigRawFilterAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('donnee utilisateur', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 4 : Pas de filtre |raw - aucune issue
    // =============================================

    public function testNoRawFilterDoesNothing(): void
    {
        mkdir($this->tempDir . '/templates', 0777, true);
        file_put_contents(
            $this->tempDir . '/templates/safe.html.twig',
            '<div>{{ variable }}</div><p>{{ name|escape }}</p>',
        );

        $analyzer = new TwigRawFilterAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 5 : Champs d'enrichissement sur issue CRITICAL
    // =============================================

    public function testEnrichmentFields(): void
    {
        mkdir($this->tempDir . '/templates', 0777, true);
        file_put_contents(
            $this->tempDir . '/templates/danger.html.twig',
            '<div>{{ form.data|raw }}</div>',
        );

        $analyzer = new TwigRawFilterAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertSame(20, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('html_sanitizer', $issue->getDocUrl() ?? '');
        $this->assertStringContainsString('XSS', $issue->getBusinessImpact() ?? '');
    }

    // =============================================
    // Test 6 : |raw avec espaces autour du pipe est detecte
    // =============================================

    public function testRawFilterWithSpacesIsDetected(): void
    {
        mkdir($this->tempDir . '/templates', 0777, true);
        file_put_contents(
            $this->tempDir . '/templates/spaced.html.twig',
            '<div>{{ variable | raw }}</div>',
        );

        $analyzer = new TwigRawFilterAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
    }

    // =============================================
    // Test 7 : Fichiers non-twig sont ignores
    // =============================================

    public function testNonTwigFilesAreIgnored(): void
    {
        mkdir($this->tempDir . '/templates', 0777, true);
        file_put_contents(
            $this->tempDir . '/templates/readme.txt',
            '{{ variable|raw }}',
        );

        $analyzer = new TwigRawFilterAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 8 : Verification des metadonnees de l'analyzer
    // =============================================

    public function testGetModuleReturnsTwig(): void
    {
        mkdir($this->tempDir, 0777, true);
        $analyzer = new TwigRawFilterAnalyzer($this->tempDir);
        $this->assertSame(Module::TWIG, $analyzer->getModule());
    }

    public function testGetNameReturnsExpectedName(): void
    {
        mkdir($this->tempDir, 0777, true);
        $analyzer = new TwigRawFilterAnalyzer($this->tempDir);
        $this->assertSame('Twig Raw Filter Analyzer', $analyzer->getName());
    }

    // =============================================
    // Test 9 : Melange de |raw standard et |raw sur donnee utilisateur
    // =============================================

    public function testMixedRawUsageCreatesBothIssues(): void
    {
        mkdir($this->tempDir . '/templates', 0777, true);
        $template = <<<'TWIG'
        <div>{{ title|raw }}</div>
        <div>{{ form.data|raw }}</div>
        TWIG;
        file_put_contents($this->tempDir . '/templates/mixed.html.twig', $template);

        $analyzer = new TwigRawFilterAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        // Une issue CRITICAL pour form.data|raw et une WARNING pour title|raw.
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $criticals);
        $this->assertCount(1, $warnings);
    }
}
