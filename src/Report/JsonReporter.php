<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Report;

use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Severity;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Formate le rapport d'audit en JSON pour une consommation par des outils CI/CD.
 */
final class JsonReporter implements ReporterInterface
{
    public function __construct(private readonly OutputInterface $output)
    {
    }

    public function generate(AuditReport $report): void
    {
        $issues = $report->getIssues();

        $criticalCount   = count($report->getIssuesBySeverity(Severity::CRITICAL));
        $warningCount    = count($report->getIssuesBySeverity(Severity::WARNING));
        $suggestionCount = count($report->getIssuesBySeverity(Severity::SUGGESTION));

        // Le statut global est determine par la presence d'issues CRITICAL,
        // independamment du score numerique.
        $status = match (true) {
            $criticalCount > 0 => 'critical',
            count($issues) > 0 => 'warning',
            default            => 'ok',
        };

        $data = [
            'meta' => [
                'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'project_path' => $report->getProjectPath(),
            ],
            'summary' => [
                'score'        => $report->getScore(),
                'status'       => $status,
                'issues_count' => [
                    'total'      => count($issues),
                    'critical'   => $criticalCount,
                    'warning'    => $warningCount,
                    'suggestion' => $suggestionCount,
                ],
            ],
            'issues' => array_map(
                fn ($issue) => [
                    'severity'   => strtolower($issue->getSeverity()->name),
                    'module'     => strtolower($issue->getModule()->name),
                    'analyzer'   => $issue->getAnalyzer(),
                    'message'    => $issue->getMessage(),
                    'detail'     => $issue->getDetail(),
                    'suggestion' => $issue->getSuggestion(),
                    'file'       => $issue->getFile(),
                    'line'       => $issue->getLine(),
                ],
                $issues
            ),
        ];

        $this->output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function getFormat(): string
    {
        return 'json';
    }
}