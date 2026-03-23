<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Evénement dispatché au démarrage d'une analyse.
 * Transporte le chemin du projet audité et le nombre d'analyzers qui vont s'exécuter.
 */
final class AnalysisStartedEvent extends Event
{
    public const NAME = 'sf_doctor.analysis_started';

    public function __construct(
        private readonly string $projectPath,
        private readonly int $analyzerCount,
    ) {
    }

    public function getProjectPath(): string
    {
        return $this->projectPath;
    }

    public function getAnalyzerCount(): int
    {
        return $this->analyzerCount;
    }
}