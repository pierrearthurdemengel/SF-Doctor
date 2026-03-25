<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Command;

use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Context\ProjectContextDetector;
use PierreArthur\SfDoctor\Cache\ResultCacheInterface;
use PierreArthur\SfDoctor\Config\ParameterResolverInterface;
use PierreArthur\SfDoctor\Event\AnalysisCompletedEvent;
use PierreArthur\SfDoctor\Event\AnalysisStartedEvent;
use PierreArthur\SfDoctor\Event\IssueFoundEvent;
use PierreArthur\SfDoctor\Event\ModuleCompletedEvent;
use PierreArthur\SfDoctor\Diff\AuditReportDiff;
use PierreArthur\SfDoctor\Diff\BaselineStorage;
use PierreArthur\SfDoctor\EventSubscriber\CacheSubscriber;
use PierreArthur\SfDoctor\EventSubscriber\ProgressSubscriber;
use PierreArthur\SfDoctor\Message\RunAnalyzerMessage;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Report\ReporterInterface;
use PierreArthur\SfDoctor\Workflow\AuditContext;
use PierreArthur\SfDoctor\Watch\FileWatcher;
use PierreArthur\SfDoctor\Workflow\AuditWorkflow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;

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
        private readonly ResultCacheInterface $cache,
        // Optionnel : absent en mode standalone (bin/sf-doctor).
        // Présent en mode bundle via injection du container Symfony.
        private readonly ?MessageBusInterface $bus = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('security', 's', InputOption::VALUE_NONE, 'Audit sécurité uniquement')
            ->addOption('architecture', 'a', InputOption::VALUE_NONE, 'Audit architecture uniquement')
            ->addOption('performance', 'p', InputOption::VALUE_NONE, 'Audit performance uniquement')
            ->addOption('doctrine', null, InputOption::VALUE_NONE, 'Audit Doctrine uniquement')
            ->addOption('messenger', null, InputOption::VALUE_NONE, 'Audit Messenger uniquement')
            ->addOption('api-platform', null, InputOption::VALUE_NONE, 'Audit API Platform uniquement')
            ->addOption('migration', null, InputOption::VALUE_NONE, 'Audit migration uniquement')
            ->addOption('twig', null, InputOption::VALUE_NONE, 'Audit Twig uniquement')
            ->addOption('deployment', null, InputOption::VALUE_NONE, 'Audit deployabilite uniquement')
            ->addOption('tests', null, InputOption::VALUE_NONE, 'Audit tests uniquement')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Tous les modules (défaut si aucun module spécifié)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format de sortie (console, json)', 'console')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Exécuter les analyzers via Messenger (nécessite un bus configuré)')
            ->addOption('brief', null, InputOption::VALUE_NONE, 'Affichage condensé : message et fichier uniquement, sans enrichissement')
            ->addOption('diff', null, InputOption::VALUE_REQUIRED, 'Comparer avec une baseline (chemin du fichier JSON)')
            ->addOption('save-baseline', null, InputOption::VALUE_REQUIRED, 'Sauvegarder le rapport comme baseline (chemin du fichier JSON)')
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Surveiller les fichiers et relancer l\'audit a chaque modification')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {

        $projectContext = (new ProjectContextDetector($this->projectPath))->detect();

        $io = new SymfonyStyle($input, $output);
        $io->title('SF Doctor — Audit en cours');

        $startTime = microtime(true);

        $workflow = AuditWorkflow::create();
        $context  = new AuditContext();

        $this->dispatcher->addSubscriber(new ProgressSubscriber($output));
        $this->dispatcher->addSubscriber(new CacheSubscriber($this->cache));

        // --- Vérification du cache ---
        // En mode --watch, le cache est ignore : on veut toujours un audit frais.
        $watchMode    = (bool) $input->getOption('watch');
        $hash         = $this->cache->computeHash($this->projectPath);
        $cachedReport = $this->cache->load($hash);

        if ($cachedReport !== null && !$watchMode) {
            $io->text('<comment>Rapport chargé depuis le cache.</comment>');
            $io->newLine();

            $format   = $input->getOption('format');
            $reporter = $this->findReporter($format);

            if ($reporter === null) {
                $io->error(sprintf('Format de rapport inconnu : "%s"', $format));
                return Command::FAILURE;
            }

            $reporter->generate($cachedReport, $output, ['brief' => (bool) $input->getOption('brief')]);

            return $this->handlePostAnalysis($cachedReport, $input, $io);
        }

        $modules = $this->resolveModules($input);

        $io->text(sprintf(
            'Modules : <info>%s</info>',
            implode(', ', array_map(fn (Module $m): string => $m->value, $modules)),
        ));
        $io->newLine();

        $analyzersByModule = $this->groupAnalyzersByModule($modules);
        $totalCount        = array_sum(array_map('count', $analyzersByModule));

        $report = new AuditReport(
            projectPath: $this->projectPath,
            modules: $modules,
        );

        $workflow->apply($context, AuditWorkflow::TRANSITION_START);

        $this->dispatcher->dispatch(
            new AnalysisStartedEvent($this->projectPath, $totalCount),
            AnalysisStartedEvent::NAME,
        );

        $async = $input->getOption('async') && $this->bus !== null;

        if ($input->getOption('async') && $this->bus === null) {
            $io->warning('Option --async ignorée : aucun bus Messenger disponible.');
        }

        try {
            foreach ($analyzersByModule as $module => $analyzers) {
                $issuesBeforeModule = count($report->getIssues());

                foreach ($analyzers as $analyzer) {
                    if (!$analyzer->supports($projectContext)) {
                        $io->text(sprintf(
                            '  <comment>⏭</comment>  %s (ignoré — dépendance manquante)',
                            $analyzer->getName(),
                        ));
                        continue;
                    }

                    $issuesBefore = count($report->getIssues());
                    $io->text(sprintf('  <info>▶</info>  %s...', $analyzer->getName()));

                    if ($async && $this->bus !== null) {
                        
                        // Envoi du message sur le bus.
                        // Le HandledStamp contient le AuditReport retourné par le handler.
                        $envelope = $this->bus->dispatch(
                            new RunAnalyzerMessage($analyzer::class, $this->projectPath, $modules)
                        );

                        /** @var HandledStamp|null $stamp */
                        $stamp = $envelope->last(HandledStamp::class);

                        if ($stamp !== null) {
                            /** @var AuditReport $partialReport */
                            $partialReport = $stamp->getResult();

                            // Fusion des issues du rapport partiel dans le rapport principal.
                            foreach ($partialReport->getIssues() as $issue) {
                                $report->addIssue($issue);
                            }
                        }
                    } else {
                        $analyzer->analyze($report);
                    }

                    $newIssues = array_slice($report->getIssues(), $issuesBefore);
                    foreach ($newIssues as $issue) {
                        $this->dispatcher->dispatch(
                            new IssueFoundEvent($issue, $analyzer::class),
                            IssueFoundEvent::NAME,
                        );
                    }
                }

                $issuesFoundInModule = count($report->getIssues()) - $issuesBeforeModule;
                $this->dispatcher->dispatch(
                    new ModuleCompletedEvent(Module::from($module), $issuesFoundInModule),
                    ModuleCompletedEvent::NAME,
                );
            }

            $report->complete();

            $workflow->apply($context, AuditWorkflow::TRANSITION_COMPLETE);
            $io->comment(sprintf('Statut workflow : %s', $context->getStatus()));

        } catch (\Throwable $e) {
            $workflow->apply($context, AuditWorkflow::TRANSITION_FAIL);
            $io->error(sprintf('Erreur inattendue : %s', $e->getMessage()));

            $duration = microtime(true) - $startTime;
            $this->dispatcher->dispatch(
                new AnalysisCompletedEvent($report, $duration),
                AnalysisCompletedEvent::NAME,
            );

            return Command::FAILURE;
        }

        $io->newLine();

        $format   = $input->getOption('format');
        $reporter = $this->findReporter($format);

        if ($reporter === null) {
            $io->error(sprintf('Format de rapport inconnu : "%s"', $format));
            return Command::FAILURE;
        }

        $reporter->generate($report, $output, ['brief' => (bool) $input->getOption('brief')]);

        $duration = microtime(true) - $startTime;
        $this->dispatcher->dispatch(
            new AnalysisCompletedEvent($report, $duration),
            AnalysisCompletedEvent::NAME,
        );

        $exitCode = $this->handlePostAnalysis($report, $input, $io);

        if ($watchMode) {
            $this->watchLoop($io, $modules, $analyzersByModule, $projectContext, $report);
            return Command::SUCCESS;
        }

        return $exitCode;
    }

    /**
     * @param list<Module> $modules
     * @return array<string, list<AnalyzerInterface>>
     */
    private function groupAnalyzersByModule(array $modules): array
    {
        $grouped = [];

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
        // Map des options CLI vers les modules.
        $optionModuleMap = [
            'security' => Module::SECURITY,
            'architecture' => Module::ARCHITECTURE,
            'performance' => Module::PERFORMANCE,
            'doctrine' => Module::DOCTRINE,
            'messenger' => Module::MESSENGER,
            'api-platform' => Module::API_PLATFORM,
            'migration' => Module::MIGRATION,
            'twig' => Module::TWIG,
            'deployment' => Module::DEPLOYMENT,
            'tests' => Module::TESTS,
        ];

        foreach ($optionModuleMap as $option => $module) {
            if ($input->getOption($option)) {
                return [$module];
            }
        }

        // Par defaut : tous les modules.
        return Module::cases();
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

    /**
     * Logique post-analyse commune aux chemins cache et normal.
     * Gere la sauvegarde de baseline, le calcul du diff et le code de sortie.
     */
    private function handlePostAnalysis(AuditReport $report, InputInterface $input, SymfonyStyle $io): int
    {
        $baselineStorage = new BaselineStorage();

        // Sauvegarder la baseline si demande.
        $saveBaselinePath = $input->getOption('save-baseline');
        if (is_string($saveBaselinePath) && $saveBaselinePath !== '') {
            $baselineStorage->save($saveBaselinePath, $report);
            $io->newLine();
            $io->text(sprintf('<info>Baseline sauvegardee dans %s</info>', $saveBaselinePath));
        }

        // Comparer avec une baseline si demande.
        $diffPath = $input->getOption('diff');
        if (is_string($diffPath) && $diffPath !== '') {
            $previous = $baselineStorage->load($diffPath);
            if ($previous === null) {
                $io->error(sprintf('Impossible de charger la baseline : %s', $diffPath));
                return Command::FAILURE;
            }

            $diff = new AuditReportDiff($previous, $report);
            $this->displayDiff($io, $diff);

            return $this->computeDiffExitCode($diff);
        }

        // Sans --diff : exit code base sur tous les CRITICALs.
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        return count($criticals) > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Affiche le resultat de la comparaison entre deux rapports.
     */
    private function displayDiff(SymfonyStyle $io, AuditReportDiff $diff): void
    {
        $io->newLine();
        $io->section('Comparaison avec la baseline');

        if ($diff->isEmpty()) {
            $io->success('Aucun changement detecte.');
            return;
        }

        $fixed = $diff->getFixed();
        if (count($fixed) > 0) {
            $io->text(sprintf('<info>%d issue(s) corrigee(s) :</info>', count($fixed)));
            foreach ($fixed as $issue) {
                $io->text(sprintf(
                    '  <fg=green>[-]</> [%s] [%s] %s',
                    strtoupper($issue->getSeverity()->value),
                    $issue->getModule()->value,
                    $issue->getMessage(),
                ));
            }
            $io->newLine();
        }

        $introduced = $diff->getIntroduced();
        if (count($introduced) > 0) {
            $io->text(sprintf('<fg=red>%d issue(s) introduite(s) :</>', count($introduced)));
            foreach ($introduced as $issue) {
                $io->text(sprintf(
                    '  <fg=red>[+]</> [%s] [%s] %s',
                    strtoupper($issue->getSeverity()->value),
                    $issue->getModule()->value,
                    $issue->getMessage(),
                ));
            }
            $io->newLine();
        }

        $io->text(sprintf(
            'Bilan : <info>%d corrigee(s)</info>, <fg=red>%d introduite(s)</>',
            count($fixed),
            count($introduced),
        ));
    }

    /**
     * En mode --diff, seuls les CRITICALs nouvellement introduits declenchent un echec.
     * Les CRITICALs qui existaient deja dans la baseline ne bloquent pas.
     */
    private function computeDiffExitCode(AuditReportDiff $diff): int
    {
        foreach ($diff->getIntroduced() as $issue) {
            if ($issue->getSeverity() === Severity::CRITICAL) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Boucle de surveillance : relance l'audit a chaque modification de fichier.
     * Affiche uniquement le diff par rapport a l'analyse precedente.
     * S'arrete avec Ctrl+C.
     *
     * @param list<Module> $modules
     * @param array<string, list<AnalyzerInterface>> $analyzersByModule
     */
    private function watchLoop(
        SymfonyStyle $io,
        array $modules,
        array $analyzersByModule,
        ProjectContext $projectContext,
        AuditReport $previousReport,
    ): void {
        $watchDirs = [];
        foreach (['src', 'config', 'templates'] as $dir) {
            $path = $this->projectPath . '/' . $dir;
            if (is_dir($path)) {
                $watchDirs[] = $path;
            }
        }

        if (count($watchDirs) === 0) {
            $io->warning('Aucun repertoire a surveiller (src/, config/, templates/ absents).');
            return;
        }

        $watcher = new FileWatcher($watchDirs);

        $io->newLine();
        $io->text(sprintf(
            '<comment>Mode watch actif. Surveillance de : %s</comment>',
            implode(', ', array_map(fn (string $d): string => basename($d) . '/', $watchDirs)),
        ));
        $io->text('<comment>Ctrl+C pour arreter.</comment>');

        /** @phpstan-ignore-next-line Boucle infinie volontaire, interrompue par Ctrl+C. */
        while (true) {
            usleep(500_000); // 500ms entre chaque verification.

            $changedFiles = $watcher->detectChanges();
            if (count($changedFiles) === 0) {
                continue;
            }

            $io->newLine();
            $io->text(sprintf(
                '<info>%d fichier(s) modifie(s) :</info>',
                count($changedFiles),
            ));
            foreach ($changedFiles as $file) {
                $io->text(sprintf('  - %s', basename($file)));
            }
            $io->newLine();
            $io->text('Relance de l\'audit...');

            // Relancer l'analyse complete.
            $newReport = new AuditReport($this->projectPath, $modules);
            foreach ($analyzersByModule as $analyzers) {
                foreach ($analyzers as $analyzer) {
                    if ($analyzer->supports($projectContext)) {
                        $analyzer->analyze($newReport);
                    }
                }
            }
            $newReport->complete();

            // Afficher le diff.
            $diff = new AuditReportDiff($previousReport, $newReport);
            if ($diff->isEmpty()) {
                $io->text('<comment>Aucun changement dans les issues.</comment>');
            } else {
                $this->displayDiff($io, $diff);
            }

            $io->text(sprintf('Score : <info>%d/100</info>', $newReport->getScore()));

            $previousReport = $newReport;
        }
    }
}