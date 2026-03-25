<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Score;

use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Calcule la dette technique totale en heures a partir des issues du rapport.
 *
 * Categorise par module et par priorite, estime le cout en jours/homme
 * selon un TJM configurable.
 */
final class TechnicalDebtCalculator
{
    private const DEFAULT_TJM = 500; // EUR/jour
    private const HOURS_PER_DAY = 7;

    /**
     * Calcule la dette technique totale en minutes.
     */
    public function computeTotalMinutes(AuditReport $report): int
    {
        return array_sum(array_filter(array_map(
            fn (Issue $issue): ?int => $issue->getEstimatedFixMinutes(),
            $report->getIssues(),
        )));
    }

    /**
     * Calcule la dette technique par module.
     *
     * @return array<string, array{minutes: int, hours: float, issues: int, critical: int}>
     */
    public function computeByModule(AuditReport $report): array
    {
        $result = [];

        foreach (Module::cases() as $module) {
            $issues = $report->getIssuesByModule($module);
            if (empty($issues)) {
                continue;
            }

            $minutes = array_sum(array_filter(array_map(
                fn (Issue $issue): ?int => $issue->getEstimatedFixMinutes(),
                $issues,
            )));

            $criticalCount = count(array_filter(
                $issues,
                fn (Issue $issue): bool => $issue->getSeverity() === Severity::CRITICAL,
            ));

            $result[$module->value] = [
                'minutes' => $minutes,
                'hours' => round($minutes / 60, 1),
                'issues' => count($issues),
                'critical' => $criticalCount,
            ];
        }

        // Sort by minutes descending (highest debt first)
        uasort($result, fn (array $a, array $b): int => $b['minutes'] <=> $a['minutes']);

        return $result;
    }

    /**
     * Calcule la dette technique par priorite.
     *
     * @return array<string, array{minutes: int, hours: float, issues: int}>
     */
    public function computeByPriority(AuditReport $report): array
    {
        $result = [];

        foreach (Severity::cases() as $severity) {
            $issues = $report->getIssuesBySeverity($severity);
            if (empty($issues)) {
                continue;
            }

            $minutes = array_sum(array_filter(array_map(
                fn (Issue $issue): ?int => $issue->getEstimatedFixMinutes(),
                $issues,
            )));

            $result[$severity->value] = [
                'minutes' => $minutes,
                'hours' => round($minutes / 60, 1),
                'issues' => count($issues),
            ];
        }

        return $result;
    }

    /**
     * Estime le cout financier de la dette technique.
     *
     * @return array{total_minutes: int, total_hours: float, total_days: float, estimated_cost_eur: int}
     */
    public function computeCost(AuditReport $report, int $tjm = self::DEFAULT_TJM): array
    {
        $totalMinutes = $this->computeTotalMinutes($report);
        $totalHours = $totalMinutes / 60;
        $totalDays = $totalHours / self::HOURS_PER_DAY;

        return [
            'total_minutes' => $totalMinutes,
            'total_hours' => round($totalHours, 1),
            'total_days' => round($totalDays, 1),
            'estimated_cost_eur' => (int) round($totalDays * $tjm),
        ];
    }

    /**
     * Retourne les top N issues les plus couteuses a corriger.
     *
     * @return list<Issue>
     */
    public function getTopCriticalIssues(AuditReport $report, int $limit = 5): array
    {
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);

        // Sort by estimatedFixMinutes descending
        usort($criticals, function (Issue $a, Issue $b): int {
            return ($b->getEstimatedFixMinutes() ?? 0) <=> ($a->getEstimatedFixMinutes() ?? 0);
        });

        return array_slice($criticals, 0, $limit);
    }
}
