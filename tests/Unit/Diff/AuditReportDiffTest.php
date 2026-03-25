<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Diff\AuditReportDiff;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class AuditReportDiffTest extends TestCase
{
    public function testNoChangesBetweenIdenticalReports(): void
    {
        $previous = $this->createReport([
            $this->makeIssue(Severity::WARNING, Module::SECURITY, 'Firewall sans authenticator'),
        ]);

        $current = $this->createReport([
            $this->makeIssue(Severity::WARNING, Module::SECURITY, 'Firewall sans authenticator'),
        ]);

        $diff = new AuditReportDiff($previous, $current);

        $this->assertTrue($diff->isEmpty());
        $this->assertFalse($diff->hasRegressions());
        $this->assertCount(0, $diff->getIntroduced());
        $this->assertCount(0, $diff->getFixed());
    }

    public function testDetectsIntroducedIssue(): void
    {
        $previous = $this->createReport([]);

        $current = $this->createReport([
            $this->makeIssue(Severity::CRITICAL, Module::SECURITY, 'Pas de access_control'),
        ]);

        $diff = new AuditReportDiff($previous, $current);

        $this->assertFalse($diff->isEmpty());
        $this->assertTrue($diff->hasRegressions());
        $this->assertCount(1, $diff->getIntroduced());
        $this->assertCount(0, $diff->getFixed());
        $this->assertSame('Pas de access_control', $diff->getIntroduced()[0]->getMessage());
    }

    public function testDetectsFixedIssue(): void
    {
        $previous = $this->createReport([
            $this->makeIssue(Severity::WARNING, Module::ARCHITECTURE, 'QueryBuilder dans controller'),
        ]);

        $current = $this->createReport([]);

        $diff = new AuditReportDiff($previous, $current);

        $this->assertFalse($diff->isEmpty());
        $this->assertFalse($diff->hasRegressions());
        $this->assertCount(0, $diff->getIntroduced());
        $this->assertCount(1, $diff->getFixed());
        $this->assertSame('QueryBuilder dans controller', $diff->getFixed()[0]->getMessage());
    }

    public function testDetectsBothIntroducedAndFixed(): void
    {
        $previous = $this->createReport([
            $this->makeIssue(Severity::WARNING, Module::SECURITY, 'CSRF desactive'),
        ]);

        $current = $this->createReport([
            $this->makeIssue(Severity::CRITICAL, Module::PERFORMANCE, 'N+1 detecte'),
        ]);

        $diff = new AuditReportDiff($previous, $current);

        $this->assertFalse($diff->isEmpty());
        $this->assertTrue($diff->hasRegressions());
        $this->assertCount(1, $diff->getIntroduced());
        $this->assertCount(1, $diff->getFixed());
    }

    public function testIgnoresFileAndLineChanges(): void
    {
        // Meme issue, fichier et ligne differents apres refactoring.
        $previous = $this->createReport([
            $this->makeIssue(Severity::WARNING, Module::SECURITY, 'Firewall sans authenticator', 'security.yaml', 10),
        ]);

        $current = $this->createReport([
            $this->makeIssue(Severity::WARNING, Module::SECURITY, 'Firewall sans authenticator', 'packages/security.yaml', 42),
        ]);

        $diff = new AuditReportDiff($previous, $current);

        $this->assertTrue($diff->isEmpty());
    }

    public function testDifferentSeveritySameMessageIsNewIssue(): void
    {
        // Meme message mais severite differente = issue differente.
        $previous = $this->createReport([
            $this->makeIssue(Severity::SUGGESTION, Module::SECURITY, 'Verifier le firewall'),
        ]);

        $current = $this->createReport([
            $this->makeIssue(Severity::CRITICAL, Module::SECURITY, 'Verifier le firewall'),
        ]);

        $diff = new AuditReportDiff($previous, $current);

        $this->assertFalse($diff->isEmpty());
        $this->assertCount(1, $diff->getIntroduced());
        $this->assertCount(1, $diff->getFixed());
    }

    public function testDifferentModuleSameMessageIsNewIssue(): void
    {
        // Meme message mais module different = issue differente.
        $previous = $this->createReport([
            $this->makeIssue(Severity::WARNING, Module::SECURITY, 'Configuration manquante'),
        ]);

        $current = $this->createReport([
            $this->makeIssue(Severity::WARNING, Module::ARCHITECTURE, 'Configuration manquante'),
        ]);

        $diff = new AuditReportDiff($previous, $current);

        $this->assertCount(1, $diff->getIntroduced());
        $this->assertCount(1, $diff->getFixed());
    }

    public function testEmptyReportsProduceEmptyDiff(): void
    {
        $previous = $this->createReport([]);
        $current  = $this->createReport([]);

        $diff = new AuditReportDiff($previous, $current);

        $this->assertTrue($diff->isEmpty());
        $this->assertFalse($diff->hasRegressions());
    }

    /**
     * @param Issue[] $issues
     */
    private function createReport(array $issues): AuditReport
    {
        $report = new AuditReport('/fake/path', [Module::SECURITY, Module::ARCHITECTURE, Module::PERFORMANCE]);

        foreach ($issues as $issue) {
            $report->addIssue($issue);
        }

        return $report;
    }

    private function makeIssue(
        Severity $severity,
        Module $module,
        string $message,
        ?string $file = null,
        ?int $line = null,
    ): Issue {
        return new Issue(
            severity: $severity,
            module: $module,
            analyzer: 'TestAnalyzer',
            message: $message,
            detail: 'Detail de test',
            suggestion: 'Suggestion de test',
            file: $file,
            line: $line,
        );
    }
}
