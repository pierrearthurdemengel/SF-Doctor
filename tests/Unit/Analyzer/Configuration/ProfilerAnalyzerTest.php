<?php

// tests/Unit/Analyzer/Configuration/ProfilerAnalyzerTest.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Configuration;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Configuration\ProfilerAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class ProfilerAnalyzerTest extends TestCase
{
    private function makeReport(): AuditReport
    {
        return new AuditReport('/fake/path', [Module::SECURITY]);
    }

    private function makeAnalyzer(array $configMap): ProfilerAnalyzer
    {
        $reader = $this->createMock(ConfigReaderInterface::class);
        $reader->method('read')->willReturnMap($configMap);

        return new ProfilerAnalyzer($reader);
    }

    // --- toolbar: true ---

    public function testDetectsToolbarEnabledGlobally(): void
    {
        $analyzer = $this->makeAnalyzer([
            ['config/packages/web_profiler.yaml', ['web_profiler' => ['toolbar' => true]]],
            ['config/packages/framework.yaml', null],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('toolbar', $criticals[0]->getMessage());
    }

    // --- intercept_redirects: true ---

    public function testDetectsInterceptRedirectsEnabled(): void
    {
        $analyzer = $this->makeAnalyzer([
            ['config/packages/web_profiler.yaml', ['web_profiler' => ['intercept_redirects' => true]]],
            ['config/packages/framework.yaml', null],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('intercept_redirects', $warnings[0]->getMessage());
    }

    // --- framework.profiler.enabled: true ---

    public function testDetectsFrameworkProfilerEnabled(): void
    {
        $analyzer = $this->makeAnalyzer([
            ['config/packages/web_profiler.yaml', null],
            ['config/packages/framework.yaml', ['framework' => ['profiler' => ['enabled' => true]]]],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('framework.profiler', $criticals[0]->getMessage());
    }

    // --- framework.profiler.collect: true ---

    public function testDetectsFrameworkProfilerCollectEnabled(): void
    {
        $analyzer = $this->makeAnalyzer([
            ['config/packages/web_profiler.yaml', null],
            ['config/packages/framework.yaml', ['framework' => ['profiler' => ['collect' => true]]]],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
    }

    // --- Config saine ---

    public function testPassesWhenNoGlobalProfilerConfig(): void
    {
        $analyzer = $this->makeAnalyzer([
            ['config/packages/web_profiler.yaml', null],
            ['config/packages/framework.yaml', null],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::CRITICAL));
        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));
    }

    // --- toolbar: false est acceptable ---

    public function testPassesWhenToolbarExplicitlyDisabled(): void
    {
        $analyzer = $this->makeAnalyzer([
            ['config/packages/web_profiler.yaml', ['web_profiler' => ['toolbar' => false]]],
            ['config/packages/framework.yaml', null],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::CRITICAL));
        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));
    }

    // --- Enrichissement ---

    public function testIssueHasDocUrl(): void
    {
        $analyzer = $this->makeAnalyzer([
            ['config/packages/web_profiler.yaml', ['web_profiler' => ['toolbar' => true]]],
            ['config/packages/framework.yaml', null],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertNotNull($criticals[0]->getDocUrl());
        $this->assertNotNull($criticals[0]->getFixCode());
    }
}