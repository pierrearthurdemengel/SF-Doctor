<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Workflow;

/**
 * Sujet du workflow d'audit.
 * Porte l'etat courant de l'analyse (marking).
 */
final class AuditContext
{
    // Etat initial : l'audit n'a pas encore demarre.
    private string $status = AuditWorkflow::STATUS_PENDING;

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
}