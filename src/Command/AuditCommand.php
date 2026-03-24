<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Command;

use PierreArthur\SfDoctor\Context\ProjectContextDetector;
use PierreArthur\SfDoctor\Cache\ResultCacheInterface;
use PierreArthur\SfDoctor\Config\ParameterResolverInterface;
use PierreArthur\SfDoctor\Event\AnalysisCompletedEvent;
use PierreArthur\SfDoctor\Event\AnalysisStartedEvent;
use PierreArthur\SfDoctor\Event\IssueFoundEvent;
use PierreArthur\SfDoctor\Event\ModuleCompletedEvent;
use PierreArthur\SfDoctor\EventSubscriber\CacheSubscriber;
use PierreArthur\SfDoctor\EventSubscriber\ProgressSubscriber;
use PierreArthur\SfDoctor\Message\RunAnalyzerMessage;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Report\ReporterInterface;
use PierreArthur\SfDoctor\Workflow\AuditContext;
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
            ->addOption('all', null, InputOption::VALUE_NONE, 'Tous les modules (défaut si aucun module spécifié)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format de sortie (console, json)', 'console')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Exécuter les analyzers via Messenger (nécessite un bus configuré)')
            ->addOption('brief', null, InputOption::VALUE_NONE, 'Affichage condensé : message et fichier uniquement, sans enrichissement')
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
        $hash         = $this->cache->computeHash($this->projectPath);
        $cachedReport = $this->cache->load($hash);

        if ($cachedReport !== null) {
            $io->text('<comment>Rapport chargé depuis le cache.</comment>');
            $io->newLine();

            $format   = $input->getOption('format');
            $reporter = $this->findReporter($format);

            if ($reporter === null) {
                $io->error(sprintf('Format de rapport inconnu : "%s"', $format));
                return Command::FAILURE;
            }

            $reporter->generate($cachedReport, $output, ['brief' => (bool) $input->getOption('brief')]);

            $criticals = $cachedReport->getIssuesBySeverity(Severity::CRITICAL);
            return count($criticals) > 0 ? Command::FAILURE : Command::SUCCESS;
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

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        if (count($criticals) > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
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

    private function findReporter(string $format): ?ReporterInterface
    {
        foreach ($this->reporters as $reporter) {
            if ($reporter->getFormat() === $format) {
                return $reporter;
            }
        }
        return null;
    }
}