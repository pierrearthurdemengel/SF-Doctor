<?php

// src/Score/ScoreEngine.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Score;

use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Moteur de calcul des scores par dimension et du score global.
 *
 * Chaque dimension (securite, architecture, performance, etc.) obtient un score
 * de 0 a 100 base sur le nombre et la gravite des issues detectees.
 * Le score global est une moyenne ponderee des dimensions actives.
 */
final class ScoreEngine
{
    // Correspondance entre les noms de dimension et les modules.
    // Les cles sont les noms affiches dans les rapports.
    private const DIMENSION_MAP = [
        'securite' => Module::SECURITY,
        'architecture' => Module::ARCHITECTURE,
        'performance' => Module::PERFORMANCE,
        'doctrine' => Module::DOCTRINE,
        'messenger' => Module::MESSENGER,
        'api-platform' => Module::API_PLATFORM,
        'twig' => Module::TWIG,
        'deployment' => Module::DEPLOYMENT,
        'tests' => Module::TESTS,
        'maintenabilite' => Module::MIGRATION,
    ];

    // Poids de chaque dimension dans le score global.
    // Les dimensions critiques (securite) pesent plus lourd.
    private const DIMENSION_WEIGHTS = [
        'securite' => 3,
        'architecture' => 2,
        'performance' => 2,
        'doctrine' => 2,
        'messenger' => 1,
        'api-platform' => 1,
        'twig' => 1,
        'deployment' => 2,
        'tests' => 2,
        'maintenabilite' => 1,
    ];

    // Penalites par niveau de gravite.
    private const PENALTY_CRITICAL = 10;
    private const PENALTY_WARNING = 3;
    private const PENALTY_SUGGESTION = 1;

    public function __construct()
    {
    }

    /**
     * Calcule le score de chaque dimension (module) de l'audit.
     *
     * Seules les dimensions ayant au moins 1 issue ou faisant partie des modules
     * audites sont incluses dans le resultat.
     *
     * @return array<string, array{score: int, status: string, issues: int}>
     */
    public function computeScores(AuditReport $report): array
    {
        $scores = [];
        $auditedModules = $report->getModules();

        foreach (self::DIMENSION_MAP as $dimension => $module) {
            $issues = $report->getIssuesByModule($module);
            $isAudited = in_array($module, $auditedModules, true);

            // Inclure la dimension si elle a ete auditee ou si elle a des issues.
            if (!$isAudited && count($issues) === 0) {
                continue;
            }

            $score = $this->computeDimensionScore($issues);

            $scores[$dimension] = [
                'score' => $score,
                'status' => $this->getStatus($score),
                'issues' => count($issues),
            ];
        }

        return $scores;
    }

    /**
     * Calcule le score global du projet.
     *
     * Moyenne ponderee des scores de chaque dimension active.
     * Les dimensions non auditees et sans issues ne comptent pas.
     */
    public function computeGlobalScore(AuditReport $report): int
    {
        $scores = $this->computeScores($report);

        if (empty($scores)) {
            return 100;
        }

        $weightedSum = 0;
        $totalWeight = 0;

        foreach ($scores as $dimension => $data) {
            $weight = self::DIMENSION_WEIGHTS[$dimension] ?? 1;
            $weightedSum += $data['score'] * $weight;
            $totalWeight += $weight;
        }

        return (int) round($weightedSum / $totalWeight);
    }

    /**
     * Calcule le score d'une dimension a partir de ses issues.
     *
     * Demarre a 100 et soustrait une penalite par issue selon sa gravite :
     *   CRITICAL   : -10 points
     *   WARNING    : -3 points
     *   SUGGESTION : -1 point
     *   OK         : 0 point
     *
     * Le score est clampe a 0 minimum.
     *
     * @param list<Issue> $issues
     */
    private function computeDimensionScore(array $issues): int
    {
        $score = 100;

        foreach ($issues as $issue) {
            $score -= match ($issue->getSeverity()) {
                Severity::CRITICAL => self::PENALTY_CRITICAL,
                Severity::WARNING => self::PENALTY_WARNING,
                Severity::SUGGESTION => self::PENALTY_SUGGESTION,
                Severity::OK => 0,
            };
        }

        return max(0, $score);
    }

    /**
     * Determine le statut textuel d'un score.
     *
     *   score < 40  : 'critique'
     *   score < 70  : 'a-ameliorer'
     *   score < 90  : 'bon'
     *   score >= 90 : 'excellent'
     */
    private function getStatus(int $score): string
    {
        if ($score < 40) {
            return 'critique';
        }

        if ($score < 70) {
            return 'a-ameliorer';
        }

        if ($score < 90) {
            return 'bon';
        }

        return 'excellent';
    }
}
