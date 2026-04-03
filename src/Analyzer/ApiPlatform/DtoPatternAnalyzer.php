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
 * Detecte les entites Doctrine exposees directement comme ressources API Platform
 * sans couche DTO (Data Transfer Object).
 *
 * Exposer une entite Doctrine directement dans l'API couple le contrat API
 * au schema de base de donnees. Toute migration Doctrine modifie l'API publique.
 * Le pattern recommande est d'utiliser des classes input/output dediees.
 */
final class DtoPatternAnalyzer implements AnalyzerInterface
{
    // Seuil de proprietes au-dela duquel l'absence de DTO devient un WARNING.
    private const COMPLEX_ENTITY_THRESHOLD = 8;

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

                if ($content === false) {
                    continue;
                }

                // Verifie uniquement les fichiers qui sont a la fois entite Doctrine et ressource API.
                $isDoctrineEntity = str_contains($content, '#[ORM\\Entity')
                    || str_contains($content, '@ORM\Entity');

                $isApiResource = str_contains($content, '#[ApiResource');

                if (!$isDoctrineEntity || !$isApiResource) {
                    continue;
                }

                $realPath = str_replace('\\', '/', $file->getRealPath());
                $normalizedDir = str_replace('\\', '/', $dir);
                $relativePath = $relativePrefix . ltrim(
                    str_replace($normalizedDir, '', $realPath),
                    '/',
                );

                $this->checkDirectEntityExposure($report, $content, $relativePath, $file->getFilename());
            }
        }
    }

    public function getName(): string
    {
        return 'DTO Pattern Analyzer';
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
     * Verifie si une entite Doctrine est exposee directement sans DTO.
     * Deux niveaux de severite selon la complexite de l'entite.
     */
    private function checkDirectEntityExposure(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // Si input: ou output: est configure, un DTO est utilise.
        if (preg_match('/#\[ApiResource\b[^]]*\b(input|output)\s*:/s', $content)) {
            return;
        }

        // Verifie aussi la syntaxe avec operations: [new Get(output: ...)]
        if (preg_match('/\b(input|output)\s*:\s*\w+::class/', $content)) {
            return;
        }

        $className = $this->extractClassName($content);
        $propertyCount = $this->countProperties($content);

        if ($propertyCount > self::COMPLEX_ENTITY_THRESHOLD) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::API_PLATFORM,
                analyzer: $this->getName(),
                message: "Entite complexe '{$className}' ({$propertyCount} proprietes) exposee sans DTO",
                detail: "L'entite Doctrine '{$className}' possede {$propertyCount} proprietes "
                    . "et est exposee directement comme ressource API Platform sans classe "
                    . "input/output. Le contrat API est couple au schema de base de donnees : "
                    . "toute migration Doctrine modifie l'API publique. Le risque de regression "
                    . "augmente avec le nombre de proprietes.",
                suggestion: "Creer des classes DTO dediees (ex: {$className}Input, {$className}Output) "
                    . "et les configurer via les parametres input: et output: de #[ApiResource].",
                file: $relativePath,
                fixCode: "// Classe output dediee\n"
                    . "class {$className}Output {\n"
                    . "    public function __construct(\n"
                    . "        public readonly int \$id,\n"
                    . "        public readonly string \$name,\n"
                    . "    ) {}\n"
                    . "}\n\n"
                    . "#[ApiResource(\n"
                    . "    output: {$className}Output::class,\n"
                    . ")]",
                docUrl: 'https://api-platform.com/docs/core/dto/',
                businessImpact: "Le contrat API change a chaque migration de base de donnees. "
                    . "Les clients (frontend, mobile, partenaires) cassent sans prevenir. "
                    . "Avec {$propertyCount} proprietes, le risque de regression est eleve.",
                estimatedFixMinutes: 45,
            ));

            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::SUGGESTION,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "Entite '{$className}' exposee directement sans DTO",
            detail: "L'entite Doctrine '{$className}' est exposee comme ressource API Platform "
                . "sans classe input/output dediee. Sur un projet en croissance, "
                . "separer le contrat API du schema de base evite les regressions.",
            suggestion: "Envisager des classes DTO pour decouvrir le contrat API du schema Doctrine. "
                . "Configurer via input: et output: sur #[ApiResource].",
            file: $relativePath,
            fixCode: "#[ApiResource(\n"
                . "    output: {$className}Output::class,\n"
                . ")]",
            docUrl: 'https://api-platform.com/docs/core/dto/',
            businessImpact: "Le contrat API est couple au schema de base de donnees. "
                . "Une migration Doctrine peut casser les clients de l'API.",
            estimatedFixMinutes: 30,
        ));
    }

    private function extractClassName(string $content): string
    {
        if (preg_match('/\bclass\s+(\w+)/', $content, $matches)) {
            return $matches[1];
        }

        return 'Entity';
    }

    /**
     * Compte le nombre de proprietes declarees dans la classe.
     */
    private function countProperties(string $content): int
    {
        $count = preg_match_all('/\b(?:private|protected|public)\s+(?:readonly\s+)?(?:\??\w+\s+)?\$\w+/', $content);

        return $count !== false ? $count : 0;
    }
}
