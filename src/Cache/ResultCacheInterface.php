<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Cache;

use PierreArthur\SfDoctor\Model\AuditReport;

/**
 * Contrat pour la persistance des rapports d'audit.
 */
interface ResultCacheInterface
{
    public function computeHash(string $projectPath): string;

    public function load(string $hash): ?AuditReport;

    public function save(string $hash, AuditReport $report): void;
}