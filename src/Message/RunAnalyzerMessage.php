<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Message;

use PierreArthur\SfDoctor\Model\Module;

/**
 * Message declenche pour executer un analyzer de maniere asynchrone.
 *
 * Contient uniquement des donnees serializables : le nom de la classe
 * de l'analyzer et le chemin du projet a auditer.
 */
final readonly class RunAnalyzerMessage
{
    /**
     * @param list<Module> $modules
     */
    public function __construct(
        public string $analyzerClass,
        public string $projectPath,
        public array $modules,
    ) {
    }
}