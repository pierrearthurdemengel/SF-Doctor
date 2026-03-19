<?php

namespace SfDoctor\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use SfDoctor\Model\AuditReport;
use SfDoctor\Model\Issue;
use SfDoctor\Model\Module;
use SfDoctor\Model\Severity;

class AuditReportTest extends TestCase
{
    // --- Helpers ---
    // Une méthode privée pour créer des Issues rapidement dans les tests.
    // Sans ça, chaque test aurait 10 lignes de création d'Issue identiques.
    // "factory method" de test
    private function createIssue(
        Severity $severity = Severity::WARNING,
        Module $module = Module::SECURITY,
    ): Issue {
        return new Issue(
            severity: $severity,
            module: $module,
            analyzer: 'TestAnalyzer',
            message: 'Test message',
            detail: 'Test detail',
            suggestion: 'Test suggestion',
        );
    }

    // --- Tests du score ---

    public function testScoreStartsAt100WithNoIssues(): void
    {
        // Arrange : un rapport vide
        $report = new AuditReport('/fake/path', [Module::SECURITY]);

        // Assert : pas d'issues → score parfait
        $this->assertSame(100, $report->getScore());
    }

    public function testScoreDecreasesWithCriticalIssue(): void
    {
        // Arrange
        $report = new AuditReport('/fake/path', [Module::SECURITY]);

        // Act : on ajoute un CRITICAL (-10 points)
        $report->addIssue($this->createIssue(Severity::CRITICAL));

        // Assert
        $this->assertSame(90, $report->getScore());
    }

    public function testScoreDecreasesWithWarningIssue(): void
    {
        $report = new AuditReport('/fake/path', [Module::SECURITY]);

        $report->addIssue($this->createIssue(Severity::WARNING));

        $this->assertSame(97, $report->getScore());
    }

    public function testScoreDecreasesWithSuggestionIssue(): void
    {
        $report = new AuditReport('/fake/path', [Module::SECURITY]);

        $report->addIssue($this->createIssue(Severity::SUGGESTION));

        $this->assertSame(99, $report->getScore());
    }

    public function testOkIssueDoesNotAffectScore(): void
    {
        $report = new AuditReport('/fake/path', [Module::SECURITY]);

        // OK = check passé, pas de pénalité
        $report->addIssue($this->createIssue(Severity::OK));

        $this->assertSame(100, $report->getScore());
    }

    public function testScoreCumulatesMultipleIssues(): void
    {
        $report = new AuditReport('/fake/path', [Module::SECURITY]);

        // 2 CRITICAL (-20) + 1 WARNING (-3) + 1 SUGGESTION (-1) = 100 - 24 = 76
        $report->addIssue($this->createIssue(Severity::CRITICAL));
        $report->addIssue($this->createIssue(Severity::CRITICAL));
        $report->addIssue($this->createIssue(Severity::WARNING));
        $report->addIssue($this->createIssue(Severity::SUGGESTION));

        $this->assertSame(76, $report->getScore());
    }

    public function testScoreNeverGoesBelowZero(): void
    {
        $report = new AuditReport('/fake/path', [Module::SECURITY]);

        // 15 CRITICAL = -150 points, mais le score reste à 0 (pas -50)
        for ($i = 0; $i < 15; $i++) {
            $report->addIssue($this->createIssue(Severity::CRITICAL));
        }

        $this->assertSame(0, $report->getScore());
    }

    // --- Tests des filtres ---

    public function testGetIssuesBySeverity(): void
    {
        $report = new AuditReport('/fake/path', [Module::SECURITY]);

        $report->addIssue($this->createIssue(Severity::CRITICAL));
        $report->addIssue($this->createIssue(Severity::WARNING));
        $report->addIssue($this->createIssue(Severity::WARNING));
        $report->addIssue($this->createIssue(Severity::SUGGESTION));

        // assertCount vérifie le nombre d'éléments dans un tableau.
        $this->assertCount(1, $report->getIssuesBySeverity(Severity::CRITICAL));
        $this->assertCount(2, $report->getIssuesBySeverity(Severity::WARNING));
        $this->assertCount(1, $report->getIssuesBySeverity(Severity::SUGGESTION));
        $this->assertCount(0, $report->getIssuesBySeverity(Severity::OK));
    }

    public function testGetIssuesByModule(): void
    {
        $report = new AuditReport('/fake/path', [Module::SECURITY, Module::ARCHITECTURE]);

        $report->addIssue($this->createIssue(module: Module::SECURITY));
        $report->addIssue($this->createIssue(module: Module::SECURITY));
        $report->addIssue($this->createIssue(module: Module::ARCHITECTURE));

        $this->assertCount(2, $report->getIssuesByModule(Module::SECURITY));
        $this->assertCount(1, $report->getIssuesByModule(Module::ARCHITECTURE));
        $this->assertCount(0, $report->getIssuesByModule(Module::PERFORMANCE));
    }

    // --- Tests du cycle de vie ---

    public function testGetIssuesReturnsAllIssues(): void
    {
        $report = new AuditReport('/fake/path', [Module::SECURITY]);

        $report->addIssue($this->createIssue(Severity::CRITICAL));
        $report->addIssue($this->createIssue(Severity::WARNING));

        $this->assertCount(2, $report->getIssues());
    }

    public function testDurationIsNullBeforeComplete(): void
    {
        $report = new AuditReport('/fake/path', [Module::SECURITY]);

        // L'audit n'est pas terminé → pas de durée
        $this->assertNull($report->getDuration());
    }

    public function testDurationIsAvailableAfterComplete(): void
    {
        $report = new AuditReport('/fake/path', [Module::SECURITY]);

        // On termine immédiatement. La durée sera 0 (ou très proche).
        $report->complete();

        // assertNotNull vérifie que la valeur n'est PAS null.
        $this->assertNotNull($report->getDuration());

        // assertGreaterThanOrEqual vérifie que $actual >= $expected.
        // La durée doit être >= 0 (pas négative).
        $this->assertGreaterThanOrEqual(0, $report->getDuration());
    }

    public function testProjectPathIsStored(): void
    {
        $report = new AuditReport('/home/pierre/mon-projet', [Module::SECURITY]);

        $this->assertSame('/home/pierre/mon-projet', $report->getProjectPath());
    }

    public function testModulesAreStored(): void
    {
        $modules = [Module::SECURITY, Module::ARCHITECTURE];
        $report = new AuditReport('/fake/path', $modules);

        $this->assertSame($modules, $report->getModules());
    }

    public function testStartedAtIsSetAutomatically(): void
    {
        // On note l'heure AVANT la création
        $before = new \DateTimeImmutable();

        $report = new AuditReport('/fake/path', [Module::SECURITY]);

        // On note l'heure APRÈS la création
        $after = new \DateTimeImmutable();

        // Le startedAt doit être entre les deux
        $this->assertGreaterThanOrEqual($before, $report->getStartedAt());
        $this->assertLessThanOrEqual($after, $report->getStartedAt());
    }

    public function testCompletedAtIsNullBeforeComplete(): void
    {
        $report = new AuditReport('/fake/path', [Module::SECURITY]);

        $this->assertNull($report->getCompletedAt());
    }

    public function testCompletedAtIsSetAfterComplete(): void
    {
        $report = new AuditReport('/fake/path', [Module::SECURITY]);
        $report->complete();

        $this->assertNotNull($report->getCompletedAt());
    }
}