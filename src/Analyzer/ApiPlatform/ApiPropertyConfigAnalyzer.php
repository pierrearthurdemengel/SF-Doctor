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
 * Detecte les problemes de configuration au niveau des proprietes API Platform.
 *
 * Analyse les identifiants, les types de cles primaires et les
 * configurations #[ApiProperty] incoherentes. Les identifiants
 * auto-increment exposes dans l'URL sont un vecteur d'attaque IDOR classique.
 */
final class ApiPropertyConfigAnalyzer implements AnalyzerInterface
{
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

                $this->checkAutoIncrementIdentifier($report, $content, $relativePath, $file->getFilename());
                $this->checkWritableIdentifier($report, $content, $relativePath, $file->getFilename());
            }
        }
    }

    public function getName(): string
    {
        return 'API Property Config Analyzer';
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
     * Detecte les ressources API Platform utilisant un identifiant auto-increment
     * sans identifiant alternatif (UUID, ULID, slug).
     *
     * Les IDs sequentiels dans l'URL (/api/users/1, /api/users/2) permettent :
     * - L'enumeration de toutes les ressources par iteration
     * - L'estimation du nombre total d'enregistrements
     * - Les attaques IDOR facilitees (deviner l'ID suivant)
     */
    private function checkAutoIncrementIdentifier(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // Detecte #[ORM\GeneratedValue] indiquant un ID auto-increment.
        $hasAutoIncrement = str_contains($content, '#[ORM\\GeneratedValue')
            || str_contains($content, 'GeneratedValue(strategy:');

        if (!$hasAutoIncrement) {
            return;
        }

        // Verifie si un identifiant alternatif est configure.
        $hasUuid = str_contains($content, 'Uuid')
            || str_contains($content, 'Ulid')
            || str_contains($content, 'uuid')
            || str_contains($content, 'ulid');

        // Verifie si un #[ApiProperty(identifier: true)] pointe vers un autre champ.
        $hasCustomIdentifier = preg_match(
            '/#\[ApiProperty\s*\([^)]*identifier\s*:\s*true[^)]*\)\s*\]\s*\n\s*(?:private|public|protected)\s+\S+\s+\$(?!id\b)/',
            $content,
        );

        if ($hasUuid || $hasCustomIdentifier) {
            return;
        }

        $className = $this->extractClassName($content);

        $report->addIssue(new Issue(
            severity: Severity::SUGGESTION,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "Identifiant auto-increment expose dans l'API dans {$filename}",
            detail: "La ressource '{$className}' utilise un identifiant auto-increment (int) "
                . "dans ses URLs API (/api/" . strtolower($className) . "s/1). "
                . "Les IDs sequentiels permettent l'enumeration de toutes les ressources "
                . "et facilitent les attaques IDOR.",
            suggestion: "Ajouter une propriete UUID comme identifiant API avec "
                . "#[ApiProperty(identifier: true)] tout en gardant l'auto-increment "
                . "comme cle primaire interne.",
            file: $relativePath,
            fixCode: "use Symfony\\Component\\Uid\\Uuid;\n\n"
                . "#[ORM\\Column(type: 'uuid', unique: true)]\n"
                . "#[ApiProperty(identifier: true)]\n"
                . "private Uuid \$uuid;\n\n"
                . "public function __construct()\n"
                . "{\n"
                . "    \$this->uuid = Uuid::v7();\n"
                . "}",
            docUrl: 'https://api-platform.com/docs/core/identifiers/',
            businessImpact: "Un attaquant peut enumerer tous les enregistrements en incrementant l'ID. "
                . "Il peut aussi estimer le volume de donnees (nombre de clients, commandes, etc.). "
                . "Les UUID v7 sont ordonnes chronologiquement et non predictibles.",
            estimatedFixMinutes: 30,
        ));
    }

    /**
     * Detecte les identifiants modifiables via l'API.
     * Un identifiant modifiable casse les references externes (bookmarks, liens)
     * et peut creer des collisions.
     */
    private function checkWritableIdentifier(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        $lines = explode("\n", $content);

        foreach ($lines as $lineIndex => $line) {
            // Cherche les proprietes marquees comme identifiant API.
            if (!preg_match('/#\[ORM\\\\Id\b/', $line) && !str_contains($line, '#[ORM\Id')) {
                continue;
            }

            // Regarde les lignes autour pour detecter le contexte de la propriete.
            $contextStart = max(0, $lineIndex - 3);
            $contextEnd = min(count($lines) - 1, $lineIndex + 5);
            $context = implode("\n", array_slice($lines, $contextStart, $contextEnd - $contextStart + 1));

            // Verifie si l'identifiant est dans un groupe d'ecriture.
            if (!preg_match('/#\[Groups\s*\(\s*\[([^\]]*)\]\s*\)\s*\]/', $context, $groupMatch)) {
                continue;
            }

            $groups = $groupMatch[1];

            if (str_contains($groups, 'write') || str_contains($groups, 'input') || str_contains($groups, 'create')) {
                $className = $this->extractClassName($content);

                $report->addIssue(new Issue(
                    severity: Severity::WARNING,
                    module: Module::API_PLATFORM,
                    analyzer: $this->getName(),
                    message: "Identifiant dans un groupe d'ecriture dans {$filename}",
                    detail: "L'identifiant de la ressource '{$className}' est inclus dans "
                        . "un groupe de serialisation d'ecriture. Le client peut potentiellement "
                        . "modifier l'identifiant d'une ressource existante, ce qui casse "
                        . "les references externes et peut creer des collisions.",
                    suggestion: "Retirer l'identifiant du groupe d'ecriture ou ajouter "
                        . "#[ApiProperty(writable: false)] sur la propriete.",
                    file: $relativePath,
                    line: $lineIndex + 1,
                    fixCode: "#[ORM\\Id]\n"
                        . "#[Groups(['read'])]  // lecture seule, pas de 'write'\n"
                        . "private ?int \$id = null;",
                    docUrl: 'https://api-platform.com/docs/core/serialization/',
                    businessImpact: "La modification d'un identifiant casse tous les liens externes "
                        . "(bookmarks, references d'autres systemes, caches). "
                        . "Peut creer des collisions avec des enregistrements existants.",
                    estimatedFixMinutes: 10,
                ));

                break;
            }
        }
    }

    private function extractClassName(string $content): string
    {
        if (preg_match('/\bclass\s+(\w+)/', $content, $matches)) {
            return $matches[1];
        }

        return 'Entity';
    }
}
