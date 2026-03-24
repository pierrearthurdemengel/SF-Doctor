<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Report;

use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ConsoleReporter implements ReporterInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function generate(AuditReport $report, OutputInterface $output, array $context = []): void
    {
        // SymfonyStyle est construit ici car OutputInterface n'est disponible
        // qu'au moment de l'execution, pas au boot du container.
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        // En mode brief, seuls le tableau des issues (message + fichier) et le score sont affiches.
        // Les champs d'enrichissement (fixCode, docUrl, businessImpact) sont omis.
        $brief = (bool) ($context['brief'] ?? false);

        $io->title('SF Doctor — Rapport d\'audit');
        $this->printSummary($report, $io);

        foreach ($report->getModules() as $module) {
            $this->printModule($report, $module, $io, $brief);
        }

        $this->printScore($report, $io);

        if (!$brief) {
            $this->printTotalEstimatedTime($report, $io);
        }
    }

    public function getFormat(): string
    {
        return 'console';
    }

    private function printSummary(AuditReport $report, SymfonyStyle $io): void
    {
        $io->section('Résumé');

        $io->text([
            sprintf('Projet : <info>%s</info>', $report->getProjectPath()),
            sprintf('Modules : <info>%s</info>', implode(', ', array_map(
                fn (Module $m): string => $m->value,
                $report->getModules(),
            ))),
            sprintf('Issues trouvées : <info>%d</info>', count($report->getIssues())),
        ]);
    }

    private function printModule(AuditReport $report, Module $module, SymfonyStyle $io, bool $brief): void
    {
        $issues = $report->getIssuesByModule($module);

        $io->section(sprintf(
            'Module %s (%d issue%s)',
            ucfirst($module->value),
            count($issues),
            count($issues) > 1 ? 's' : '',
        ));

        if (empty($issues)) {
            $io->success('Aucun problème détecté.');
            return;
        }

        $io->table(
            ['Sévérité', 'Analyzer', 'Message', 'Fichier'],
            array_map(
                fn (Issue $issue): array => [
                    $this->formatSeverity($issue->getSeverity()),
                    $issue->getAnalyzer(),
                    $issue->getMessage(),
                    $this->formatLocation($issue),
                ],
                $issues,
            ),
        );

        // En mode brief, on s'arrete apres le tableau.
        if ($brief) {
            return;
        }

        foreach ($issues as $issue) {
            if ($issue->getSeverity() === Severity::OK) {
                continue;
            }

            $this->printIssueDetail($issue, $io);
        }
    }

    private function printIssueDetail(Issue $issue, SymfonyStyle $io): void
    {
        $io->text(sprintf('  <comment>→</comment> %s', $issue->getSuggestion()));

        if ($issue->getBusinessImpact() !== null) {
            $io->text(sprintf('  <fg=white;options=underscore>Impact : %s</>', $issue->getBusinessImpact()));
        }

        if ($issue->getFixCode() !== null) {
            $io->text('  <fg=cyan>Fix :</>');
            // Chaque ligne du snippet est indentee et coloree pour se demarquer du texte courant.
            foreach (explode("\n", $issue->getFixCode()) as $codeLine) {
                $io->text(sprintf('  <fg=cyan>%s</>', $codeLine));
            }
        }

        if ($issue->getDocUrl() !== null) {
            $io->text(sprintf('  <href=%s>Documentation : %s</>', $issue->getDocUrl(), $issue->getDocUrl()));
        }

        if ($issue->getEstimatedFixMinutes() !== null) {
            $io->text(sprintf('  <fg=white>Temps estimé : %d min</>', $issue->getEstimatedFixMinutes()));
        }
    }

    private function printScore(AuditReport $report, SymfonyStyle $io): void
    {
        $score = $report->getScore();
        $io->newLine();

        if ($score >= 80) {
            $io->success(sprintf('Score : %d/100', $score));
        } elseif ($score >= 50) {
            $io->warning(sprintf('Score : %d/100', $score));
        } else {
            $io->error(sprintf('Score : %d/100', $score));
        }
    }

    private function printTotalEstimatedTime(AuditReport $report, SymfonyStyle $io): void
    {
        // Somme uniquement les issues dont le temps estime est renseigne.
        $total = array_sum(array_filter(array_map(
            fn (Issue $issue): ?int => $issue->getEstimatedFixMinutes(),
            $report->getIssues(),
        )));

        // Si aucune issue n'a de temps estime, on n'affiche pas la ligne.
        if ($total === 0) {
            return;
        }

        $io->text(sprintf(
            '<fg=white>Temps total de correction estimé : ~%d min</> ',
            $total,
        ));
    }

    private function formatSeverity(Severity $severity): string
    {
        return match ($severity) {
            Severity::CRITICAL   => '<fg=red;options=bold>CRITICAL</>',
            Severity::WARNING    => '<fg=yellow>WARNING</>',
            Severity::SUGGESTION => '<fg=blue>SUGGESTION</>',
            Severity::OK         => '<fg=green>OK</>',
        };
    }

    private function formatLocation(Issue $issue): string
    {
        if ($issue->getFile() === null) {
            return '-';
        }

        if ($issue->getLine() !== null) {
            return sprintf('%s:%d', $issue->getFile(), $issue->getLine());
        }

        return $issue->getFile();
    }
}