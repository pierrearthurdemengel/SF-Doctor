<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Serializer;

use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Score\ScoreEngine;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use PierreArthur\SfDoctor\Model\Severity;

// Convertit un objet AuditReport en tableau PHP serialisable.
// Delègue la normalisation de chaque Issue au Serializer central
// via NormalizerAwareTrait, qui route vers IssueNormalizer.
final class AuditReportNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param AuditReport $object
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        $issues          = $object->getIssues();
        $criticalCount   = count($object->getIssuesBySeverity(Severity::CRITICAL));
        $warningCount    = count($object->getIssuesBySeverity(Severity::WARNING));
        $suggestionCount = count($object->getIssuesBySeverity(Severity::SUGGESTION));

        $normalizedIssues = array_map(
            fn($issue) => $this->normalizer->normalize($issue, $format, $context),
            $issues
        );

        $status = match (true) {
            $criticalCount > 0    => 'critical',
            count($issues) > 0    => 'warning',
            default               => 'ok',
        };

        $scoreEngine = new ScoreEngine();
        $dimensionScores = $scoreEngine->computeScores($object);
        $globalScore = $scoreEngine->computeGlobalScore($object);

        return [
            'meta' => [
                'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'project_path' => $object->getProjectPath(),
            ],
            'summary' => [
                'score'        => $object->getScore(),
                'global_score' => $globalScore,
                'status'       => $status,
                'issues_count' => [
                    'total'      => count($issues),
                    'critical'   => $criticalCount,
                    'warning'    => $warningCount,
                    'suggestion' => $suggestionCount,
                ],
                'scores_by_dimension' => $dimensionScores,
            ],
            'issues' => $normalizedIssues,
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof AuditReport;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [AuditReport::class => true];
    }
}