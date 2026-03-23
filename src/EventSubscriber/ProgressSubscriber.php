<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\EventSubscriber;

use PierreArthur\SfDoctor\Event\AnalysisCompletedEvent;
use PierreArthur\SfDoctor\Event\AnalysisStartedEvent;
use PierreArthur\SfDoctor\Event\ModuleCompletedEvent;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Affiche la progression de l'analyse en console.
 * Ecoute les events du cycle de vie de l'analyse et écrit sur l'output injecté.
 */
final class ProgressSubscriber implements EventSubscriberInterface
{
    private OutputInterface $output;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AnalysisStartedEvent::NAME => 'onAnalysisStarted',
            ModuleCompletedEvent::NAME => 'onModuleCompleted',
            AnalysisCompletedEvent::NAME => 'onAnalysisCompleted',
        ];
    }

    public function onAnalysisStarted(AnalysisStartedEvent $event): void
    {
        $this->output->writeln(sprintf(
            '<info>Audit démarré</info> : %s (%d analyzer(s))',
            $event->getProjectPath(),
            $event->getAnalyzerCount(),
        ));
    }

    public function onModuleCompleted(ModuleCompletedEvent $event): void
    {
        $this->output->writeln(sprintf(
            '  <comment>[%s]</comment> %d issue(s)',
            $event->getModule()->value,
            $event->getIssueCount(),
        ));
    }

    public function onAnalysisCompleted(AnalysisCompletedEvent $event): void
    {
        $this->output->writeln(sprintf(
            '<info>Analyse terminée</info> en %.2fs - Score : %d/100',
            $event->getDuration(),
            $event->getReport()->getScore(),
        ));
    }
}