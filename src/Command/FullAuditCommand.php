<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Command;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ParameterResolverInterface;
use PierreArthur\SfDoctor\Context\ProjectContextDetector;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Report\ReporterInterface;
use PierreArthur\SfDoctor\Score\ScoreEngine;
use PierreArthur\SfDoctor\Score\TechnicalDebtCalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lance un audit complet sur tous les modules detectes.
 *
 * C'est la commande "Projet inconnu" : une agence qui reprend un projet
 * existant veut savoir en 2 minutes si c'est une bombe ou un projet sain.
 */
#[AsCommand(
    name: 'sf-doctor:full-audit',
    description: 'Audit complet de tous les modules avec rapport detaille',
)]
final class FullAuditCommand extends Command
{
    /**
     * @param iterable<AnalyzerInterface> $analyzers
     * @param iterable<ReporterInterface> $reporters
     */
    public function __construct(
        private readonly iterable $analyzers,
        private readonly iterable $reporters,
        private readonly string $projectPath,
        /** @phpstan-ignore-next-line */
        private readonly ParameterResolverInterface $parameterResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format de sortie (console, json, sarif)', 'console')
            ->addOption('tjm', null, InputOption::VALUE_REQUIRED, 'TJM en EUR pour estimation financiere', '500')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SF Doctor — Audit complet du projet');

        $projectContext = (new ProjectContextDetector($this->projectPath))->detect();

        $io->text(sprintf('Projet : <info>%s</info>', $this->projectPath));

        // Detect available modules
        $modules = Module::cases();
        $io->text(sprintf(
            'Modules detectes : <info>%s</info>',
            implode(', ', array_map(fn (Module $m) => $m->value, $modules)),
        ));
        $io->newLine();

        // Collect analyzers
        $allAnalyzers = [];
        foreach ($this->analyzers as $analyzer) {
            if ($analyzer->supports($projectContext)) {
                $allAnalyzers[] = $analyzer;
            }
        }

        $io->text(sprintf('<info>%d</info> analyzers actifs', count($allAnalyzers)));
        $io->newLine();

        $report = new AuditReport($this->projectPath, $modules);

        // Run with progress bar
        $progressBar = new ProgressBar($output, count($allAnalyzers));
        $progressBar->setFormat(' %current%/%max% [%bar%] %message%');
        $progressBar->start();

        foreach ($allAnalyzers as $analyzer) {
            $progressBar->setMessage($analyzer->getName());
            $analyzer->analyze($report);
            $progressBar->advance();
        }

        $progressBar->setMessage('Termine');
        $progressBar->finish();
        $io->newLine(2);

        $report->complete();

        // Generate report
        $format = $input->getOption('format');
        $reporter = $this->findReporter($format);

        if ($reporter === null) {
            $io->error(sprintf('Format de rapport inconnu : "%s"', $format));
            return Command::FAILURE;
        }

        $reporter->generate($report, $output);

        // Technical debt summary
        $io->newLine();
        $this->printDebtSummary($io, $report, (int) $input->getOption('tjm'));

        // Top 5 critical issues
        $this->printTopCriticals($io, $report);

        // Verdict
        $this->printVerdict($io, $report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        return count($criticals) > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function findReporter(string $format): ?ReporterInterface
    {
        foreach ($this->reporters as $reporter) {
            if ($reporter->getFormat() === $format) {
                return $reporter;
            }
        }
        return null;
    }

    private function printDebtSummary(SymfonyStyle $io, AuditReport $report, int $tjm): void
    {
        $calculator = new TechnicalDebtCalculator();
        $cost = $calculator->computeCost($report, $tjm);

        $io->section('Dette technique');
        $io->text([
            sprintf('Temps total de correction : <info>%.1f heures</info> (~%.1f jours)', $cost['total_hours'], $cost['total_days']),
            sprintf('Cout estime (TJM %d EUR) : <info>%d EUR</info>', $tjm, $cost['estimated_cost_eur']),
        ]);

        $byModule = $calculator->computeByModule($report);
        if (!empty($byModule)) {
            $io->newLine();
            $rows = [];
            foreach ($byModule as $module => $data) {
                $rows[] = [
                    ucfirst($module),
                    $data['issues'],
                    $data['critical'],
                    sprintf('%.1fh', $data['hours']),
                ];
            }
            $io->table(['Module', 'Issues', 'Critical', 'Temps'], $rows);
        }
    }

    private function printTopCriticals(SymfonyStyle $io, AuditReport $report): void
    {
        $calculator = new TechnicalDebtCalculator();
        $top = $calculator->getTopCriticalIssues($report, 5);

        if (empty($top)) {
            return;
        }

        $io->section('Top 5 CRITICAL a corriger en priorite');
        foreach ($top as $i => $issue) {
            $io->text(sprintf(
                '  <fg=red>%d.</> [%s] %s%s',
                $i + 1,
                $issue->getModule()->value,
                $issue->getMessage(),
                $issue->getEstimatedFixMinutes() !== null ? sprintf(' (~%d min)', $issue->getEstimatedFixMinutes()) : '',
            ));
        }
    }

    private function printVerdict(SymfonyStyle $io, AuditReport $report): void
    {
        $scoreEngine = new ScoreEngine();
        $globalScore = $scoreEngine->computeGlobalScore($report);
        $criticalCount = count($report->getIssuesBySeverity(Severity::CRITICAL));

        $io->newLine();

        if ($globalScore >= 80 && $criticalCount === 0) {
            $io->success(sprintf(
                'VERDICT : Projet sain (score %d/100, 0 CRITICAL)',
                $globalScore,
            ));
        } elseif ($globalScore >= 50 || $criticalCount <= 3) {
            $io->warning(sprintf(
                'VERDICT : Dette technique significative (score %d/100, %d CRITICAL)',
                $globalScore,
                $criticalCount,
            ));
        } else {
            $io->error(sprintf(
                'VERDICT : Refonte recommandee (score %d/100, %d CRITICAL)',
                $globalScore,
                $criticalCount,
            ));
        }
    }
}
