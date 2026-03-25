<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Score;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Score\TechnicalDebtCalculator;

final class TechnicalDebtCalculatorTest extends TestCase
{
    private TechnicalDebtCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TechnicalDebtCalculator();
    }

    private function createReport(array $issues = []): AuditReport
    {
        $report = new AuditReport('/fake/project', Module::cases());
        foreach ($issues as $issue) {
            $report->addIssue($issue);
        }
        return $report;
    }

    private function createIssue(
        Severity $severity = Severity::WARNING,
        Module $module = Module::SECURITY,
        ?int $estimatedFixMinutes = null,
    ): Issue {
        return new Issue(
            severity: $severity,
            module: $module,
            analyzer: 'TestAnalyzer',
            message: 'Test issue',
            detail: 'Detail',
            suggestion: 'Fix it',
            estimatedFixMinutes: $estimatedFixMinutes,
        );
    }

    // --- Test 1 : Rapport vide => 0 minutes ---

    public function testEmptyReportReturnsZero(): void
    {
        $report = $this->createReport();

        $this->assertSame(0, $this->calculator->computeTotalMinutes($report));
    }

    // --- Test 2 : Total minutes ---

    public function testTotalMinutesSum(): void
    {
        $report = $this->createReport([
            $this->createIssue(estimatedFixMinutes: 30),
            $this->createIssue(estimatedFixMinutes: 15),
            $this->createIssue(estimatedFixMinutes: null),
            $this->createIssue(estimatedFixMinutes: 45),
        ]);

        $this->assertSame(90, $this->calculator->computeTotalMinutes($report));
    }

    // --- Test 3 : Compute by module ---

    public function testComputeByModule(): void
    {
        $report = $this->createReport([
            $this->createIssue(module: Module::SECURITY, estimatedFixMinutes: 30),
            $this->createIssue(module: Module::SECURITY, estimatedFixMinutes: 20, severity: Severity::CRITICAL),
            $this->createIssue(module: Module::ARCHITECTURE, estimatedFixMinutes: 60),
        ]);

        $byModule = $this->calculator->computeByModule($report);

        $this->assertArrayHasKey('architecture', $byModule);
        $this->assertArrayHasKey('security', $byModule);
        $this->assertSame(50, $byModule['security']['minutes']);
        $this->assertSame(2, $byModule['security']['issues']);
        $this->assertSame(1, $byModule['security']['critical']);
        $this->assertSame(60, $byModule['architecture']['minutes']);
    }

    // --- Test 4 : Compute by priority ---

    public function testComputeByPriority(): void
    {
        $report = $this->createReport([
            $this->createIssue(severity: Severity::CRITICAL, estimatedFixMinutes: 30),
            $this->createIssue(severity: Severity::CRITICAL, estimatedFixMinutes: 20),
            $this->createIssue(severity: Severity::WARNING, estimatedFixMinutes: 10),
        ]);

        $byPriority = $this->calculator->computeByPriority($report);

        $this->assertArrayHasKey('critical', $byPriority);
        $this->assertArrayHasKey('warning', $byPriority);
        $this->assertSame(50, $byPriority['critical']['minutes']);
        $this->assertSame(2, $byPriority['critical']['issues']);
        $this->assertSame(10, $byPriority['warning']['minutes']);
    }

    // --- Test 5 : Compute cost ---

    public function testComputeCost(): void
    {
        $report = $this->createReport([
            $this->createIssue(estimatedFixMinutes: 420), // 7h = 1 day
        ]);

        $cost = $this->calculator->computeCost($report, 500);

        $this->assertSame(420, $cost['total_minutes']);
        $this->assertSame(7.0, $cost['total_hours']);
        $this->assertSame(1.0, $cost['total_days']);
        $this->assertSame(500, $cost['estimated_cost_eur']);
    }

    // --- Test 6 : Top critical issues ---

    public function testGetTopCriticalIssues(): void
    {
        $issue1 = $this->createIssue(severity: Severity::CRITICAL, estimatedFixMinutes: 10);
        $issue2 = $this->createIssue(severity: Severity::CRITICAL, estimatedFixMinutes: 60);
        $issue3 = $this->createIssue(severity: Severity::CRITICAL, estimatedFixMinutes: 30);
        $issueWarning = $this->createIssue(severity: Severity::WARNING, estimatedFixMinutes: 100);

        $report = $this->createReport([$issue1, $issue2, $issue3, $issueWarning]);

        $top = $this->calculator->getTopCriticalIssues($report, 2);

        $this->assertCount(2, $top);
        // First should be the most expensive (60 min)
        $this->assertSame(60, $top[0]->getEstimatedFixMinutes());
        $this->assertSame(30, $top[1]->getEstimatedFixMinutes());
    }

    // --- Test 7 : By module sorted by debt ---

    public function testByModuleSortedByDebt(): void
    {
        $report = $this->createReport([
            $this->createIssue(module: Module::SECURITY, estimatedFixMinutes: 10),
            $this->createIssue(module: Module::ARCHITECTURE, estimatedFixMinutes: 100),
        ]);

        $byModule = $this->calculator->computeByModule($report);

        $keys = array_keys($byModule);
        // Architecture (100 min) should come before Security (10 min)
        $this->assertSame('architecture', $keys[0]);
        $this->assertSame('security', $keys[1]);
    }

    // --- Test 8 : Empty report cost ---

    public function testEmptyReportCostIsZero(): void
    {
        $report = $this->createReport();
        $cost = $this->calculator->computeCost($report);

        $this->assertSame(0, $cost['total_minutes']);
        $this->assertSame(0, $cost['estimated_cost_eur']);
    }
}
