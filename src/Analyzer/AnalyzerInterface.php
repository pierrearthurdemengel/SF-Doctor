<?php

// src/Analyzer/AnalyzerInterface.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer;

use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;

interface AnalyzerInterface
{
    public function analyze(AuditReport $report): void;

    public function getName(): string;

    public function getModule(): \PierreArthur\SfDoctor\Model\Module;

    /**
     * Indique si cet analyzer est applicable au projet audite.
     * Utilise le ProjectContext pour eviter les class_exists() disperses.
     */
    public function supports(ProjectContext $context): bool;
}