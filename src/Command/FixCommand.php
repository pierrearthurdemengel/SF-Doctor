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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sf-doctor:fix',
    description: 'Mode fix interactif : propose et applique les corrections',
)]
final class FixCommand extends Command
{
    /**
     * @param iterable<AnalyzerInterface> $analyzers
     */
    public function __construct(
        private readonly iterable $analyzers,
        private readonly string $projectPath,
        /** @phpstan-ignore-next-line */
        private readonly ParameterResolverInterface $parameterResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les corrections sans les appliquer')
            ->addOption('auto', null, InputOption::VALUE_NONE, 'Applique toutes les corrections sans demander')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SF Doctor — Mode Fix');

        $projectContext = (new ProjectContextDetector($this->projectPath))->detect();

        // Run analyzers
        $report = new AuditReport($this->projectPath, Module::cases());
        foreach ($this->analyzers as $analyzer) {
            if ($analyzer->supports($projectContext)) {
                $analyzer->analyze($report);
            }
        }
        $report->complete();

        // Filter fixable issues (CRITICAL/WARNING with fixCode and file)
        $fixableIssues = $this->getFixableIssues($report);

        if (empty($fixableIssues)) {
            $io->success('Aucune correction automatique disponible.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('<info>%d</info> corrections disponibles', count($fixableIssues)));
        $io->newLine();

        $dryRun = (bool) $input->getOption('dry-run');
        $auto = (bool) $input->getOption('auto');

        $accepted = [];
        $skipped = 0;

        foreach ($fixableIssues as $i => $issue) {
            $io->section(sprintf(
                'Fix %d/%d — [%s] %s',
                $i + 1,
                count($fixableIssues),
                strtoupper($issue->getSeverity()->value),
                $issue->getMessage(),
            ));

            $io->text([
                sprintf('Module   : <info>%s</info>', $issue->getModule()->value),
                sprintf('Fichier  : <info>%s</info>', $issue->getFile()),
                sprintf('Analyzer : %s', $issue->getAnalyzer()),
            ]);
            $io->newLine();
            $io->text('Suggestion : ' . $issue->getSuggestion());
            $io->newLine();
            $io->text('Code correctif :');
            $io->block($issue->getFixCode(), null, 'fg=green');

            if ($dryRun) {
                $io->note('Mode dry-run : correction non appliquee');
                $accepted[] = $this->issueToArray($issue);
                continue;
            }

            if ($auto) {
                $accepted[] = $this->issueToArray($issue);
                $io->text('<info>Accepte (mode auto)</info>');
                continue;
            }

            // Interactive mode
            $answer = $io->choice('Appliquer ce fix ?', ['oui', 'non', 'quitter'], 'oui');

            if ($answer === 'quitter') {
                $io->warning('Arret demande par l\'utilisateur.');
                break;
            }

            if ($answer === 'oui') {
                $accepted[] = $this->issueToArray($issue);
                $io->text('<info>Accepte</info>');
            } else {
                $skipped++;
                $io->text('<comment>Ignore</comment>');
            }
        }

        // Generate fix plan
        if (!$dryRun && !empty($accepted)) {
            $this->saveFixPlan($accepted);
            $io->newLine();
            $io->text(sprintf(
                'Plan de correction sauvegarde dans <info>%s/.sf-doctor/fix-plan.json</info>',
                $this->projectPath,
            ));
        }

        // Summary
        $io->newLine();
        $io->section('Resume');
        $io->text([
            sprintf('Corrections acceptees : <info>%d</info>', count($accepted)),
            sprintf('Corrections ignorees  : <comment>%d</comment>', $skipped),
        ]);

        if ($dryRun) {
            $io->note('Mode dry-run : aucune modification effectuee.');
        }

        if (!empty($accepted)) {
            $io->success(sprintf('%d corrections dans le plan.', count($accepted)));
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<Issue>
     */
    private function getFixableIssues(AuditReport $report): array
    {
        $fixable = [];

        foreach ($report->getIssues() as $issue) {
            if ($issue->getFixCode() === null || $issue->getFile() === null) {
                continue;
            }

            if ($issue->getSeverity() !== Severity::CRITICAL && $issue->getSeverity() !== Severity::WARNING) {
                continue;
            }

            $fixable[] = $issue;
        }

        // Sort: CRITICAL first, then WARNING
        usort($fixable, function (Issue $a, Issue $b): int {
            $order = [Severity::CRITICAL->value => 0, Severity::WARNING->value => 1];
            return ($order[$a->getSeverity()->value] ?? 2) <=> ($order[$b->getSeverity()->value] ?? 2);
        });

        return $fixable;
    }

    /**
     * @return array<string, mixed>
     */
    private function issueToArray(Issue $issue): array
    {
        return [
            'severity' => $issue->getSeverity()->value,
            'module' => $issue->getModule()->value,
            'analyzer' => $issue->getAnalyzer(),
            'message' => $issue->getMessage(),
            'file' => $issue->getFile(),
            'fixCode' => $issue->getFixCode(),
            'estimatedFixMinutes' => $issue->getEstimatedFixMinutes(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $accepted
     */
    private function saveFixPlan(array $accepted): void
    {
        $dir = $this->projectPath . '/.sf-doctor';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $plan = [
            'generated_at' => date('c'),
            'project_path' => $this->projectPath,
            'fixes' => $accepted,
        ];

        file_put_contents(
            $dir . '/fix-plan.json',
            json_encode($plan, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        );
    }
}
