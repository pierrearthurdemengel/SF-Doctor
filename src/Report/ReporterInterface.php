<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Report;

use PierreArthur\SfDoctor\Model\AuditReport;
use Symfony\Component\Console\Output\OutputInterface;

interface ReporterInterface
{
    // Genere le rapport dans le format specifique de cette implementation.
    // OutputInterface est passe en parametre car il n'est disponible
    // qu'au moment de l'execution de la commande, pas au boot du container.
    // $context permet de passer des options d'execution sans modifier le contrat.
    // Ex: ['brief' => true] pour un affichage reduit dans ConsoleReporter.
    /**
     * @param array<string, mixed> $context
     */
    public function generate(AuditReport $report, OutputInterface $output, array $context = []): void;

    // Retourne l'identifiant du format supporte par ce reporter.
    // Ex: "console", "json", "pdf"
    // Utilise par la commande CLI pour router vers le bon reporter.
    public function getFormat(): string;
}