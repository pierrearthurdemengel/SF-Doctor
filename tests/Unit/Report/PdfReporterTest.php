<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Report;

use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Report\PdfReporter;
use PHPUnit\Framework\TestCase;

final class PdfReporterTest extends TestCase
{
    private string $tmpDir;
    private string $outputPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/' . uniqid('sf_doctor_pdf_', true);
        mkdir($this->tmpDir, 0777, true);
        $this->outputPath = $this->tmpDir . '/report.pdf';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->outputPath)) {
            unlink($this->outputPath);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testGetFormatReturnsPdf(): void
    {
        $reporter = new PdfReporter($this->outputPath);
        $this->assertSame('pdf', $reporter->getFormat());
    }

    public function testGenerateCreatesPdfFile(): void
    {
        $reporter = new PdfReporter($this->outputPath);
        $report = new AuditReport('/fake/path', []);

        $reporter->generate($report, new \Symfony\Component\Console\Output\NullOutput());

        $this->assertFileExists($this->outputPath);
    }

    public function testGeneratedFileIsNotEmpty(): void
    {
        $reporter = new PdfReporter($this->outputPath);
        $report = new AuditReport('/fake/path', []);

        $reporter->generate($report, new \Symfony\Component\Console\Output\NullOutput());

        $this->assertGreaterThan(0, filesize($this->outputPath));
    }

    public function testGeneratedFileStartsWithPdfHeader(): void
    {
        $reporter = new PdfReporter($this->outputPath);
        $report = new AuditReport('/fake/path', []);

        $reporter->generate($report, new \Symfony\Component\Console\Output\NullOutput());

        // Tout fichier PDF valide commence par "%PDF".
        $header = file_get_contents($this->outputPath, false, null, 0, 4);
        $this->assertSame('%PDF', $header);
    }

    public function testGenerateWithIssuesProducesPdfFile(): void
    {
        $reporter = new PdfReporter($this->outputPath);
        $report = new AuditReport('/fake/path', []);

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::SECURITY,
            analyzer: 'Test Analyzer',
            message: 'Un probleme critique',
            detail: 'Detail du probleme',
            suggestion: 'Corriger le probleme',
            file: 'config/packages/security.yaml',
        ));

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::PERFORMANCE,
            analyzer: 'Test Analyzer',
            message: 'Un avertissement',
            detail: 'Detail de l avertissement',
            suggestion: '',
        ));

        $reporter->generate($report, new \Symfony\Component\Console\Output\NullOutput());

        $this->assertFileExists($this->outputPath);
        $this->assertGreaterThan(0, filesize($this->outputPath));
    }
}