<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Event;

use PierreArthur\SfDoctor\Model\Module;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Evénement dispatché quand tous les analyzers d'un module ont terminé.
 * Transporte le module concerné et le nombre d'issues trouvées dans ce module.
 */
final class ModuleCompletedEvent extends Event
{
    public const NAME = 'sf_doctor.module_completed';

    public function __construct(
        private readonly Module $module,
        private readonly int $issueCount,
    ) {
    }

    public function getModule(): Module
    {
        return $this->module;
    }

    public function getIssueCount(): int
    {
        return $this->issueCount;
    }
}