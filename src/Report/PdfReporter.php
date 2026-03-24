<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Report;

use Dompdf\Dompdf;
use Dompdf\Options;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Severity;
use Symfony\Component\Console\Output\OutputInterface;

// Genere un rapport d'audit au format PDF via Dompdf.
// OutputInterface est recu pour respecter le contrat ReporterInterface
// mais n'est pas utilise : le PDF est ecrit directement dans $outputPath.
final class PdfReporter implements ReporterInterface
{
    // Chemin complet du fichier PDF a generer.
    public function __construct(private readonly string $outputPath)
    {
    }

    public function generate(AuditReport $report, OutputInterface $output): void
    {
        $options = new Options();
        // Necessaire pour que Dompdf charge les polices systeme.
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->buildHtml($report));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // output() retourne le contenu binaire du PDF.
        // On l'ecrit directement dans le fichier de sortie.
        file_put_contents($this->outputPath, $dompdf->output());
    }

    public function getFormat(): string
    {
        return 'pdf';
    }

    private function buildHtml(AuditReport $report): string
    {
        $issues = $report->getIssues();
        $score = $report->getScore();
        $criticalCount = count($report->getIssuesBySeverity(Severity::CRITICAL));
        $warningCount = count($report->getIssuesBySeverity(Severity::WARNING));
        $issueCount = count($issues);
        $generatedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $scoreColor = match (true) {
            $score >= 80 => '#2d7a2d',
            $score >= 50 => '#b36b00',
            default      => '#c0392b',
        };

        $issuesHtml = '';

        foreach ($issues as $issue) {
            $severityColor = match ($issue->getSeverity()) {
                Severity::CRITICAL   => '#c0392b',
                Severity::WARNING    => '#b36b00',
                Severity::SUGGESTION => '#2471a3',
                Severity::OK         => '#2d7a2d',
            };

            $severityLabel = $issue->getSeverity()->name;
            $fileInfo = $issue->getFile() !== null
                ? '<p style="color:#666;font-size:12px;margin:4px 0 0 0;">Fichier : ' . htmlspecialchars($issue->getFile()) . '</p>'
                : '';

            $issuesHtml .= sprintf(
                '<div style="border-left:4px solid %s;padding:10px 14px;margin-bottom:12px;background:#fafafa;">
                    <span style="color:%s;font-weight:bold;font-size:12px;">%s</span>
                    <span style="color:#888;font-size:12px;margin-left:8px;">%s</span>
                    <p style="margin:6px 0 4px 0;font-size:13px;font-weight:bold;">%s</p>
                    <p style="margin:0;font-size:12px;color:#444;">%s</p>
                    %s
                    %s
                </div>',
                $severityColor,
                $severityColor,
                htmlspecialchars($severityLabel),
                htmlspecialchars($issue->getAnalyzer()),
                htmlspecialchars($issue->getMessage()),
                htmlspecialchars($issue->getDetail()),
                $issue->getSuggestion() !== ''
                    ? '<p style="margin:4px 0 0 0;font-size:12px;color:#2471a3;">Suggestion : ' . htmlspecialchars($issue->getSuggestion()) . '</p>'
                    : '',
                $fileInfo,
            );
        }

        if ($issuesHtml === '') {
            $issuesHtml = '<p style="color:#2d7a2d;font-size:14px;">Aucun probleme detecte.</p>';
        }

        return <<<HTML
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <style>
                    body { font-family: Helvetica, Arial, sans-serif; color: #222; padding: 30px; }
                    h1   { font-size: 22px; margin-bottom: 4px; }
                    h2   { font-size: 16px; margin-top: 28px; margin-bottom: 12px; border-bottom: 1px solid #ddd; padding-bottom: 4px; }
                    .meta { color: #666; font-size: 12px; margin-bottom: 24px; }
                    .score { font-size: 48px; font-weight: bold; color: {$scoreColor}; }
                    .summary { display: flex; gap: 24px; margin-bottom: 8px; }
                    .summary span { font-size: 13px; }
                </style>
            </head>
            <body>
                <h1>SF-Doctor - Rapport d'audit</h1>
                <p class="meta">
                    Projet : {$report->getProjectPath()}<br>
                    Genere le : {$generatedAt}
                </p>

                <h2>Score global</h2>
                <div class="score">{$score}/100</div>
                <div class="summary">
                    <span style="color:#c0392b;">Critical : {$criticalCount}</span>
                    <span style="color:#b36b00;">Warning : {$warningCount}</span>
                    <span>Total : {$issueCount}</span>
                </div>

                <h2>Detail des issues</h2>
                {$issuesHtml}
            </body>
            </html>
            HTML;
    }
}