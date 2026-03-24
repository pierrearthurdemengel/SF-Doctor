<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Performance;

use PierreArthur\SfDoctor\Analyzer\Performance\NplusOneAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Severity;
use PHPUnit\Framework\TestCase;

final class NplusOneAnalyzerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/' . uniqid('sf_doctor_test_', true);
        mkdir($this->tmpDir . '/templates', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    // --- supports() ---

    public function testSupportsReturnsTrueWhenTemplatesDirExists(): void
    {
        $analyzer = new NplusOneAnalyzer($this->tmpDir);
        $this->assertTrue($analyzer->supports());
    }

    public function testSupportsReturnsFalseWhenTemplatesDirMissing(): void
    {
        $analyzer = new NplusOneAnalyzer($this->tmpDir . '/nonexistent');
        $this->assertFalse($analyzer->supports());
    }

    // --- analyze() : cas sans probleme ---

    public function testNoIssueForFormVarsAccess(): void
    {
        // form.vars.value, field.vars.errors = acces memoire Symfony, pas de requete SQL.
        $this->writeTwig('form.html.twig', <<<TWIG
            {% for field in form %}
                {{ field.vars.value }}
                {{ field.vars.errors }}
            {% endfor %}
            TWIG
        );

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    public function testNoIssueWhenTemplatesDirIsEmpty(): void
    {
        $analyzer = new NplusOneAnalyzer($this->tmpDir);
        $report = new AuditReport($this->tmpDir, []);

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    public function testNoIssueWhenAccessIsOnlyOneLevel(): void
    {
        // order.status = un seul niveau, pas de N+1
        $this->writeTwig('index.html.twig', <<<TWIG
            {% for order in orders %}
                {{ order.status }}
            {% endfor %}
            TWIG
        );

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    public function testNoIssueWhenTwoLevelAccessIsOutsideLoop(): void
    {
        // order.customer.name en dehors d'une boucle = pas de N+1
        $this->writeTwig('index.html.twig', <<<TWIG
            {{ order.customer.name }}
            TWIG
        );

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    // --- analyze() : cas avec probleme ---

    public function testDetectsNplusOneInSimpleLoop(): void
    {
        $this->writeTwig('index.html.twig', <<<TWIG
            {% for order in orders %}
                {{ order.customer.name }}
            {% endfor %}
            TWIG
        );

        $report = $this->runAnalyzer();

        $this->assertCount(1, $report->getIssues());
        $issue = $report->getIssues()[0];
        $this->assertSame(Severity::WARNING, $issue->getSeverity());
        $this->assertStringContainsString('order.customer.name', $issue->getMessage());
    }

    public function testDetectsMultipleNplusOneInSameFile(): void
    {
        $this->writeTwig('index.html.twig', <<<TWIG
            {% for order in orders %}
                {{ order.customer.name }}
                {{ order.product.title }}
            {% endfor %}
            TWIG
        );

        $report = $this->runAnalyzer();

        $this->assertCount(2, $report->getIssues());
    }

    public function testDetectsNplusOneInNestedLoop(): void
    {
        // La variable de la boucle interne est "item", pas "order".
        // order.customer.name est dans la boucle externe.
        $this->writeTwig('index.html.twig', <<<TWIG
            {% for order in orders %}
                {% for item in order.items %}
                    {{ item.product.title }}
                {% endfor %}
            {% endfor %}
            TWIG
        );

        $report = $this->runAnalyzer();

        $this->assertCount(1, $report->getIssues());
        $this->assertStringContainsString('item.product.title', $report->getIssues()[0]->getMessage());
    }

    public function testScansMultipleTwigFiles(): void
    {
        $this->writeTwig('orders.html.twig', <<<TWIG
            {% for order in orders %}
                {{ order.customer.name }}
            {% endfor %}
            TWIG
        );

        $this->writeTwig('products.html.twig', <<<TWIG
            {% for product in products %}
                {{ product.category.name }}
            {% endfor %}
            TWIG
        );

        $report = $this->runAnalyzer();

        $this->assertCount(2, $report->getIssues());
    }

    // --- Helpers ---

    private function writeTwig(string $filename, string $content): void
    {
        file_put_contents($this->tmpDir . '/templates/' . $filename, $content);
    }

    private function runAnalyzer(): AuditReport
    {
        $analyzer = new NplusOneAnalyzer($this->tmpDir);
        $report = new AuditReport($this->tmpDir, []);
        $analyzer->analyze($report);

        return $report;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    // --- Enrichissement des champs ---

    public function testNplusOneIssueHasEnrichmentFields(): void
    {
        $this->writeTwig('index.html.twig', <<<TWIG
            {% for order in orders %}
                {{ order.customer.name }}
            {% endfor %}
            TWIG
        );

        $report = $this->runAnalyzer();

        $this->assertCount(1, $report->getIssues());

        $issue = $report->getIssues()[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(20, $issue->getEstimatedFixMinutes());
        // Le fichier et la ligne sont deja renseignes depuis la V1.0.
        $this->assertNotNull($issue->getFile());
        $this->assertNotNull($issue->getLine());
        $this->assertStringContainsString('customer', $issue->getFixCode() ?? '');
    }
}