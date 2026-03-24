<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Report;

use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Serializer\AuditReportNormalizer;
use Symfony\Component\Console\Output\OutputInterface;

// Formate le rapport d'audit en JSON pour une consommation par des outils CI/CD.
// Delègue la normalisation au AuditReportNormalizer via le Serializer Symfony.
final class JsonReporter implements ReporterInterface
{
    // AuditReportNormalizer est une dependance structurelle : elle est toujours
    // necessaire, independamment de l'execution. Elle reste dans le constructeur.
    public function __construct(
        private readonly AuditReportNormalizer $normalizer,
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public function generate(AuditReport $report, OutputInterface $output, array $context = []): void
    {
        $data = $this->normalizer->normalize($report);

        $output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function getFormat(): string
    {
        return 'json';
    }
}