<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Serializer;

use PierreArthur\SfDoctor\Model\Issue;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

// Convertit un objet Issue en tableau PHP serialisable.
// Gere les enums Severity et Module qui ne sont pas supportes
// nativement par les normalizers generiques du Serializer Symfony.
final class IssueNormalizer implements NormalizerInterface
{
    /**
     * @param Issue $object
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
        {
            return [
                'severity'   => strtolower($object->getSeverity()->name),
                'module'     => strtolower($object->getModule()->name),
                'analyzer'   => $object->getAnalyzer(),
                'message'    => $object->getMessage(),
                'detail'     => $object->getDetail(),
                'suggestion' => $object->getSuggestion(),
                'file'       => $object->getFile(),
                'line'       => $object->getLine(),
            ];
        }

    // Indique au Serializer que ce normalizer prend en charge uniquement les objets Issue.
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Issue;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        // Declare le type supporte pour permettre au Serializer de mettre en cache
        // les decisions de routing entre normalizers. Requis depuis Symfony 6.4.
        return [Issue::class => true];
    }
}