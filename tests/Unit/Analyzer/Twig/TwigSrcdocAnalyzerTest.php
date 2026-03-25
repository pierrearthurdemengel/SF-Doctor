<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Twig;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Twig\TwigSrcdocAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class TwigSrcdocAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_srcdoc_' . uniqid();
        mkdir($this->tempDir . '/templates', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::TWIG]);
    }

    private function createAnalyzer(?array $sanitizerConfig = null): TwigSrcdocAnalyzer
    {
        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn($sanitizerConfig);

        return new TwigSrcdocAnalyzer($this->tempDir, $configReader);
    }

    // --- Test 1 : iframe avec srcdoc sans sandbox => CRITICAL ---

    public function testSrcdocWithoutSandboxCreatesCritical(): void
    {
        file_put_contents(
            $this->tempDir . '/templates/preview.html.twig',
            '<iframe srcdoc="{{ content }}"></iframe>'
        );

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('srcdoc', $criticals[0]->getMessage());
    }

    // --- Test 2 : iframe avec srcdoc et sandbox => OK ---

    public function testSrcdocWithSandboxNoIssue(): void
    {
        file_put_contents(
            $this->tempDir . '/templates/preview.html.twig',
            '<iframe srcdoc="{{ content }}" sandbox="allow-scripts"></iframe>'
        );

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(0, $criticals);
    }

    // --- Test 3 : Pas de templates => OK ---

    public function testNoTemplateDirDoesNothing(): void
    {
        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn(null);

        $analyzer = new TwigSrcdocAnalyzer($this->tempDir . '/nonexistent', $configReader);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 4 : HtmlSanitizer avec srcdoc autorise => WARNING ---

    public function testSanitizerWithSrcdocCreatesWarning(): void
    {
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'html_sanitizer' => [
                    'sanitizers' => [
                        'default' => [
                            'allow_attributes' => ['srcdoc', 'src'],
                        ],
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('HtmlSanitizer', $warnings[0]->getMessage());
    }

    // --- Test 5 : HtmlSanitizer sans srcdoc => OK ---

    public function testSanitizerWithoutSrcdocNoIssue(): void
    {
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'html_sanitizer' => [
                    'sanitizers' => [
                        'default' => [
                            'allow_attributes' => ['src', 'href'],
                        ],
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(0, $warnings);
    }

    // --- Test 6 : Enrichment fields ---

    public function testEnrichmentFields(): void
    {
        file_put_contents(
            $this->tempDir . '/templates/embed.html.twig',
            '<iframe srcdoc="{{ html }}"></iframe>'
        );

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $issue = $report->getIssues()[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
    }

    // --- Test 7 : getName et getModule ---

    public function testGetNameAndGetModule(): void
    {
        $analyzer = $this->createAnalyzer();

        $this->assertSame('Twig Srcdoc Analyzer', $analyzer->getName());
        $this->assertSame(Module::TWIG, $analyzer->getModule());
    }

    // --- Test 8 : supports retourne true si Twig present ---

    public function testSupportsWithTwig(): void
    {
        $analyzer = $this->createAnalyzer();
        $context = new ProjectContext(
            projectPath: '/fake',
            hasDoctrineOrm: false,
            hasMessenger: false,
            hasApiPlatform: false,
            hasTwig: true,
            hasSecurityBundle: false,
            hasWebProfilerBundle: false,
            hasMailer: false,
            hasNelmioCors: false,
            hasNelmioSecurity: false,
            hasJwtAuth: false,
            symfonyVersion: null,
        );
        $this->assertTrue($analyzer->supports($context));
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
