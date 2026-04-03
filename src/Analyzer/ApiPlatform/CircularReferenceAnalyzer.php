<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\ApiPlatform;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les relations bidirectionnelles Doctrine exposees via API Platform
 * sans protection contre les references circulaires.
 *
 * Une entite A referencant B qui referencant A provoque une boucle infinie
 * lors de la serialisation JSON. Le serializer Symfony leve une
 * CircularReferenceException (500) ou genere une reponse tronquee.
 * Les protections : #[MaxDepth], #[Ignore], ou un normalization context
 * avec enable_max_depth: true.
 */
final class CircularReferenceAnalyzer implements AnalyzerInterface
{
    /**
     * Annotations/attributs Doctrine indiquant une relation bidirectionnelle.
     *
     * @var list<string>
     */
    private const BIDIRECTIONAL_MARKERS = [
        'mappedBy',
        'inversedBy',
    ];

    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $directories = [
            $this->projectPath . '/src/Entity' => 'src/Entity/',
            $this->projectPath . '/src/ApiResource' => 'src/ApiResource/',
        ];

        foreach ($directories as $dir => $relativePrefix) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $content = file_get_contents($file->getRealPath());

                if ($content === false || !str_contains($content, '#[ApiResource')) {
                    continue;
                }

                $realPath = str_replace('\\', '/', $file->getRealPath());
                $normalizedDir = str_replace('\\', '/', $dir);
                $relativePath = $relativePrefix . ltrim(
                    str_replace($normalizedDir, '', $realPath),
                    '/',
                );

                $this->checkBidirectionalWithoutProtection($report, $content, $relativePath, $file->getFilename());
                $this->checkMaxDepthWithoutContext($report, $content, $relativePath, $file->getFilename());
            }
        }
    }

    public function getName(): string
    {
        return 'Circular Reference Analyzer';
    }

    public function getModule(): Module
    {
        return Module::API_PLATFORM;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasApiPlatform();
    }

    /**
     * Detecte les proprietes avec mappedBy/inversedBy (relation bidirectionnelle)
     * incluses dans un groupe de serialisation sans #[MaxDepth] ni #[Ignore].
     */
    private function checkBidirectionalWithoutProtection(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // Si enable_max_depth est configure globalement, la protection est en place.
        if (str_contains($content, 'enable_max_depth')) {
            return;
        }

        $lines = explode("\n", $content);
        $lineCount = count($lines);

        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];

            // Cherche les relations bidirectionnelles (mappedBy ou inversedBy).
            $hasBidirectional = false;

            foreach (self::BIDIRECTIONAL_MARKERS as $marker) {
                if (str_contains($line, $marker)) {
                    $hasBidirectional = true;
                    break;
                }
            }

            if (!$hasBidirectional) {
                continue;
            }

            // Regarde le contexte autour (5 lignes avant, 5 lignes apres).
            $contextStart = max(0, $i - 5);
            $contextEnd = min($lineCount - 1, $i + 5);
            $context = implode("\n", array_slice($lines, $contextStart, $contextEnd - $contextStart + 1));

            // Verifie si la propriete est exposee via #[Groups].
            if (!str_contains($context, '#[Groups')) {
                continue;
            }

            // Verifie si #[MaxDepth] ou #[Ignore] protege la propriete.
            if (str_contains($context, '#[MaxDepth') || str_contains($context, '#[Ignore')) {
                continue;
            }

            // Extrait le nom de la propriete.
            $propertyName = $this->extractPropertyName($lines, $i);
            $className = $this->extractClassName($content);

            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::API_PLATFORM,
                analyzer: $this->getName(),
                message: "Relation bidirectionnelle sans #[MaxDepth] dans {$filename}",
                detail: "La propriete " . ($propertyName ? "'\${$propertyName}'" : '') . " de '{$className}' "
                    . "est une relation bidirectionnelle (mappedBy/inversedBy) exposee via "
                    . "#[Groups] sans protection #[MaxDepth] ou #[Ignore]. "
                    . "La serialisation JSON peut entrer dans une boucle infinie "
                    . "et provoquer une erreur 500 (CircularReferenceException).",
                suggestion: "Ajouter #[MaxDepth(1)] sur la propriete ou activer "
                    . "enable_max_depth: true dans le normalizationContext.",
                file: $relativePath,
                line: $i + 1,
                fixCode: "use Symfony\\Component\\Serializer\\Annotation\\MaxDepth;\n\n"
                    . "#[ORM\\OneToMany(mappedBy: 'parent', targetEntity: Child::class)]\n"
                    . "#[Groups(['read'])]\n"
                    . "#[MaxDepth(1)]  // Limite la profondeur de serialisation\n"
                    . "private Collection \$children;",
                docUrl: 'https://api-platform.com/docs/core/serialization/#embedding-relations',
                businessImpact: "Erreur 500 intermittente sur les endpoints qui retournent cette entite. "
                    . "Le bug n'apparait que quand la relation inverse est chargee (lazy loading), "
                    . "rendant le probleme difficile a reproduire en tests unitaires.",
                estimatedFixMinutes: 10,
            ));
        }
    }

    /**
     * Detecte les #[MaxDepth] sans enable_max_depth: true dans le contexte.
     * #[MaxDepth] est silencieusement ignore si enable_max_depth n'est pas active
     * dans le normalizationContext. C'est un piege frequent.
     */
    private function checkMaxDepthWithoutContext(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        if (!str_contains($content, '#[MaxDepth')) {
            return;
        }

        if (str_contains($content, 'enable_max_depth')) {
            return;
        }

        $className = $this->extractClassName($content);

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "#[MaxDepth] sans enable_max_depth dans {$filename}",
            detail: "La ressource '{$className}' utilise #[MaxDepth] mais le normalizationContext "
                . "ne contient pas enable_max_depth: true. L'attribut #[MaxDepth] est "
                . "silencieusement ignore par le serializer Symfony sans ce parametre.",
            suggestion: "Ajouter enable_max_depth: true dans le normalizationContext de #[ApiResource].",
            file: $relativePath,
            fixCode: "#[ApiResource(\n"
                . "    normalizationContext: [\n"
                . "        'groups' => ['read'],\n"
                . "        'enable_max_depth' => true,\n"
                . "    ],\n"
                . ")]",
            docUrl: 'https://symfony.com/doc/current/serializer.html#handling-serialization-depth',
            businessImpact: "#[MaxDepth] n'a aucun effet, la protection contre les references "
                . "circulaires est desactivee. Les erreurs 500 persistent malgre la correction apparente.",
            estimatedFixMinutes: 5,
        ));
    }

    /**
     * Extrait le nom de propriete apres une ligne d'annotation Doctrine.
     *
     * @param list<string> $lines
     */
    private function extractPropertyName(array $lines, int $fromIndex): ?string
    {
        $count = count($lines);

        for ($i = $fromIndex + 1; $i < min($count, $fromIndex + 6); $i++) {
            if (preg_match('/(?:private|protected|public)\s+\S+\s+\$(\w+)/', $lines[$i], $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function extractClassName(string $content): string
    {
        if (preg_match('/\bclass\s+(\w+)/', $content, $matches)) {
            return $matches[1];
        }

        return 'Resource';
    }
}
