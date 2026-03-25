<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Score;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Score\ScoreEngine;

/**
 * Tests du moteur de score par dimension et score global.
 */
class ScoreEngineTest extends TestCase
{
    private ScoreEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new ScoreEngine();
    }

    public function testEmptyReportReturnsMaxScore(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);

        $this->assertSame(100, $this->engine->computeGlobalScore($report));
    }

    public function testCriticalIssuesReduceScore(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(Severity::CRITICAL, Module::SECURITY));

        $scores = $this->engine->computeScores($report);

        $this->assertArrayHasKey('securite', $scores);
        // 100 - 10 = 90
        $this->assertSame(90, $scores['securite']['score']);
    }

    public function testWarningIssuesReduceScore(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(Severity::WARNING, Module::SECURITY));

        $scores = $this->engine->computeScores($report);

        // 100 - 3 = 97
        $this->assertSame(97, $scores['securite']['score']);
    }

    public function testSuggestionIssuesReduceScore(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(Severity::SUGGESTION, Module::SECURITY));

        $scores = $this->engine->computeScores($report);

        // 100 - 1 = 99
        $this->assertSame(99, $scores['securite']['score']);
    }

    public function testScoreNeverBelowZero(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);

        // 15 critiques = 150 points de penalite, mais le score est clampe a 0.
        for ($i = 0; $i < 15; $i++) {
            $report->addIssue($this->createIssue(Severity::CRITICAL, Module::SECURITY));
        }

        $scores = $this->engine->computeScores($report);

        $this->assertSame(0, $scores['securite']['score']);
    }

    public function testStatusCritique(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);

        for ($i = 0; $i < 8; $i++) {
            $report->addIssue($this->createIssue(Severity::CRITICAL, Module::SECURITY));
        }

        $scores = $this->engine->computeScores($report);

        // 100 - 80 = 20 -> 'critique'
        $this->assertSame('critique', $scores['securite']['status']);
    }

    public function testStatusAMeliorer(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);

        for ($i = 0; $i < 5; $i++) {
            $report->addIssue($this->createIssue(Severity::CRITICAL, Module::SECURITY));
        }

        $scores = $this->engine->computeScores($report);

        // 100 - 50 = 50 -> 'a-ameliorer'
        $this->assertSame('a-ameliorer', $scores['securite']['status']);
    }

    public function testStatusBon(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);

        for ($i = 0; $i < 2; $i++) {
            $report->addIssue($this->createIssue(Severity::CRITICAL, Module::SECURITY));
        }

        $scores = $this->engine->computeScores($report);

        // 100 - 20 = 80 -> 'bon'
        $this->assertSame('bon', $scores['securite']['status']);
    }

    public function testStatusExcellent(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(Severity::SUGGESTION, Module::SECURITY));

        $scores = $this->engine->computeScores($report);

        // 100 - 1 = 99 -> 'excellent'
        $this->assertSame('excellent', $scores['securite']['status']);
    }

    public function testGlobalScoreIsWeightedAverage(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY, Module::ARCHITECTURE]);

        // Securite : 100 - 10 = 90 (poids 3)
        $report->addIssue($this->createIssue(Severity::CRITICAL, Module::SECURITY));

        // Architecture : 100 - 10 = 90 (poids 2)
        $report->addIssue($this->createIssue(Severity::CRITICAL, Module::ARCHITECTURE));

        $globalScore = $this->engine->computeGlobalScore($report);

        // Les deux sont a 90, donc la moyenne ponderee est aussi 90.
        $this->assertSame(90, $globalScore);
    }

    public function testMultipleDimensionsWithDifferentScores(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY, Module::ARCHITECTURE]);

        // Securite : 100 - 30 = 70 (3 critiques, poids 3)
        for ($i = 0; $i < 3; $i++) {
            $report->addIssue($this->createIssue(Severity::CRITICAL, Module::SECURITY));
        }

        // Architecture : 100 (pas d'issue, poids 2)
        // Global = (70*3 + 100*2) / (3+2) = (210+200)/5 = 82
        $globalScore = $this->engine->computeGlobalScore($report);
        $this->assertSame(82, $globalScore);
    }

    public function testIssueCountInScores(): void
    {
        $report = new AuditReport('/fake', [Module::SECURITY]);
        $report->addIssue($this->createIssue(Severity::CRITICAL, Module::SECURITY));
        $report->addIssue($this->createIssue(Severity::WARNING, Module::SECURITY));

        $scores = $this->engine->computeScores($report);

        $this->assertSame(2, $scores['securite']['issues']);
    }

    private function createIssue(Severity $severity, Module $module): Issue
    {
        return new Issue(
            severity: $severity,
            module: $module,
            analyzer: 'Test Analyzer',
            message: 'Issue de test',
            detail: 'Detail de test',
            suggestion: 'Suggestion de test',
        );
    }
}
