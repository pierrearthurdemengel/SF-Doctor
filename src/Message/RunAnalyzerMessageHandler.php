<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Message;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Traite un RunAnalyzerMessage en executant l'analyzer designe
 * sur le projet cible et en retournant les issues detectees.
 */
#[AsMessageHandler]
final class RunAnalyzerMessageHandler
{
    /**
     * @param iterable<AnalyzerInterface> $analyzers
     */
    public function __construct(
        private readonly iterable $analyzers,
    ) {
    }

    /**
     * Recherche l'analyzer correspondant a la classe demandee,
     * instancie un AuditReport et lance l'analyse.
     */
    public function __invoke(RunAnalyzerMessage $message): AuditReport
    {
        $analyzer = $this->findAnalyzer($message->analyzerClass);
        $report = new AuditReport($message->projectPath, $message->modules);

        if ($analyzer === null || !$analyzer->supports()) {
            return $report;
        }

        $analyzer->analyze($report);

        return $report;
    }

    /**
     * Parcourt les analyzers disponibles pour trouver celui
     * dont la classe correspond exactement au nom fourni.
     */
    private function findAnalyzer(string $analyzerClass): ?AnalyzerInterface
    {
        foreach ($this->analyzers as $analyzer) {
            if ($analyzer::class === $analyzerClass) {
                return $analyzer;
            }
        }

        return null;
    }
}