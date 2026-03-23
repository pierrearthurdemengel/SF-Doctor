<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Command;

use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Model\AuditReport;
use Symfony\Component\Console\Command\Command;
use PierreArthur\SfDoctor\Event\IssueFoundEvent;
use Symfony\Component\Console\Input\InputOption;
use PierreArthur\SfDoctor\Report\ConsoleReporter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use PierreArthur\SfDoctor\Report\ReporterInterface;
use Symfony\Component\Console\Input\InputInterface;
use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Event\ModuleCompletedEvent;
use PierreArthur\SfDoctor\Event\AnalysisStartedEvent;
use Symfony\Component\Console\Output\OutputInterface;
use PierreArthur\SfDoctor\Event\AnalysisCompletedEvent;
use PierreArthur\SfDoctor\Config\ParameterResolverInterface;
use PierreArthur\SfDoctor\EventSubscriber\ProgressSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;


#[AsCommand(
    name: 'sf-doctor:audit',
    description: 'Audite un projet Symfony (sécurité, architecture, performance)',
)]
final class AuditCommand extends Command
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
        private readonly EventDispatcherInterface $dispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('security', 's', InputOption::VALUE_NONE, 'Audit sécurité uniquement')
            ->addOption('architecture', 'a', InputOption::VALUE_NONE, 'Audit architecture uniquement')
            ->addOption('performance', 'p', InputOption::VALUE_NONE, 'Audit performance uniquement')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Tous les modules (défaut si aucun module spécifié)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format de sortie (console, json)', 'console')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SF Doctor — Audit en cours');

        $startTime = microtime(true);

        // Enregistrement du ProgressSubscriber avec l'output courant.
        // Le subscriber est créé ici car OutputInterface n'est disponible
        // qu'au moment de l'exécution de la commande, pas au boot du container.
        $this->dispatcher->addSubscriber(new ProgressSubscriber($output));

        $modules = $this->resolveModules($input);

        $io->text(sprintf(
            'Modules : <info>%s</info>',
            implode(', ', array_map(fn (Module $m): string => $m->value, $modules)),
        ));
        $io->newLine();

        // --- Indexer les analyzers actifs par module ---
        // On matérialise l'iterable en tableau pour pouvoir compter
        // et regrouper les analyzers avant de commencer.
        $analyzersByModule = $this->groupAnalyzersByModule($modules);
        $totalCount = array_sum(array_map('count', $analyzersByModule));

        $report = new AuditReport(
            projectPath: $this->projectPath,
            modules: $modules,
        );

        // --- Démarrage : on notifie les subscribers ---
        $this->dispatcher->dispatch(
            new AnalysisStartedEvent($this->projectPath, $totalCount),
            AnalysisStartedEvent::NAME,
        );

        // --- Lancer les analyzers module par module ---
        foreach ($analyzersByModule as $module => $analyzers) {
            $issuesBeforeModule = count($report->getIssues());

            foreach ($analyzers as $analyzer) {
                if (!$analyzer->supports()) {
                    $io->text(sprintf(
                        '  <comment>⏭</comment>  %s (ignoré — dépendance manquante)',
                        $analyzer->getName(),
                    ));
                    continue;
                }

                $issuesBefore = count($report->getIssues());
                $io->text(sprintf('  <info>▶</info>  %s...', $analyzer->getName()));
                $analyzer->analyze($report);

                // Dispatcher un IssueFoundEvent pour chaque nouvelle issue
                $newIssues = array_slice($report->getIssues(), $issuesBefore);
                foreach ($newIssues as $issue) {
                    $this->dispatcher->dispatch(
                        new IssueFoundEvent($issue, $analyzer::class),
                        IssueFoundEvent::NAME,
                    );
                }
            }

            // Un module entier est terminé
            $issuesFoundInModule = count($report->getIssues()) - $issuesBeforeModule;
            $this->dispatcher->dispatch(
                new ModuleCompletedEvent(Module::from($module), $issuesFoundInModule),
                ModuleCompletedEvent::NAME,
            );
        }

        $report->complete();
        $io->newLine();

        // --- Générer le rapport ---
        $format = $input->getOption('format');
        $reported = false;

        foreach ($this->reporters as $reporter) {
            if ($reporter->getFormat() === $format) {
                $reporter->generate($report);
                $reported = true;
                break;
            }
        }

        if (!$reported && $format === 'console') {
            $consoleReporter = new ConsoleReporter($output);
            $consoleReporter->generate($report);
            $reported = true;
        }

        if (!$reported) {
            $io->error(sprintf('Format de rapport inconnu : "%s"', $format));
            return Command::FAILURE;
        }

        // --- Fin : on notifie les subscribers avec la durée et le rapport ---
        $duration = microtime(true) - $startTime;
        $this->dispatcher->dispatch(
            new AnalysisCompletedEvent($report, $duration),
            AnalysisCompletedEvent::NAME,
        );

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        if (count($criticals) > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Regroupe les analyzers actifs par module, sous forme de tableau indexé par module->value.
     * Les analyzers dont le module n'est pas dans $modules sont ignorés.
     *
     * @param list<Module> $modules
     * @return array<string, list<AnalyzerInterface>>
     */
    private function groupAnalyzersByModule(array $modules): array
    {
        $grouped = [];

        // Initialiser les clés dans l'ordre des modules demandés
        foreach ($modules as $module) {
            $grouped[$module->value] = [];
        }

        foreach ($this->analyzers as $analyzer) {
            $moduleValue = $analyzer->getModule()->value;
            if (isset($grouped[$moduleValue])) {
                $grouped[$moduleValue][] = $analyzer;
            }
        }

        return $grouped;
    }

    /**
     * @return list<Module>
     */
    private function resolveModules(InputInterface $input): array
    {
        if ($input->getOption('security')) {
            return [Module::SECURITY];
        }
        if ($input->getOption('architecture')) {
            return [Module::ARCHITECTURE];
        }
        if ($input->getOption('performance')) {
            return [Module::PERFORMANCE];
        }

        return [Module::SECURITY, Module::ARCHITECTURE, Module::PERFORMANCE];
    }
}