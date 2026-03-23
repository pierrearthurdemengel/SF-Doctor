<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Event;

use PierreArthur\SfDoctor\Model\AuditReport;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Evénement dispatché à la fin d'une analyse.
 * Transporte le rapport complet et la durée d'exécution en secondes.
 */
final class AnalysisCompletedEvent extends Event
{
    public const NAME = 'sf_doctor.analysis_completed';

    public function __construct(
        private readonly AuditReport $report,
        private readonly float $duration,
    ) {
    }

    public function getReport(): AuditReport
    {
        return $this->report;
    }

    /**
     * Durée de l'analyse en secondes.
     */
    public function getDuration(): float
    {
        return $this->duration;
    }
}