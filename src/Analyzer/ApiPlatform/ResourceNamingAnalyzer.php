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
 * Verifie les conventions de nommage REST sur les ressources API Platform.
 *
 * Les bonnes pratiques REST imposent :
 * - URLs en kebab-case (minuscules, tirets)
 * - Noms de collection au pluriel (/users, pas /user)
 * - Pas de verbes dans les URLs (/orders, pas /createOrder)
 *
 * API Platform genere par defaut des shortNames en PascalCase et des
 * routes en snake_case, ce qui ne respecte pas les conventions REST.
 */
final class ResourceNamingAnalyzer implements AnalyzerInterface
{
    /**
     * Prefixes de verbes CRUD qui ne devraient pas apparaitre dans les noms de ressources.
     *
     * @var list<string>
     */
    private const VERB_PREFIXES = [
        'Create',
        'Update',
        'Delete',
        'Get',
        'List',
        'Fetch',
        'Find',
        'Remove',
        'Add',
        'Edit',
        'Save',
        'Send',
        'Process',
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

                $this->checkShortNameConvention($report, $content, $relativePath, $file->getFilename());
                $this->checkVerbInResourceName($report, $content, $relativePath, $file->getFilename());
                $this->checkRoutePrefix($report, $content, $relativePath, $file->getFilename());
            }
        }
    }

    public function getName(): string
    {
        return 'Resource Naming Analyzer';
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
     * Detecte les shortNames contenant des majuscules, underscores ou CamelCase.
     * Les URLs REST doivent etre en kebab-case : /api/blog-posts, pas /api/BlogPosts.
     */
    private function checkShortNameConvention(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // Extrait le shortName s'il est configure.
        if (!preg_match("/shortName\s*:\s*['\"]([^'\"]+)['\"]/", $content, $match)) {
            return;
        }

        $shortName = $match[1];

        // Verifie si le shortName contient des majuscules ou underscores.
        if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $shortName)) {
            return;
        }

        $kebabSuggestion = $this->toKebabCase($shortName);

        $report->addIssue(new Issue(
            severity: Severity::SUGGESTION,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "shortName '{$shortName}' non conforme aux conventions REST dans {$filename}",
            detail: "Le shortName '{$shortName}' genere des URLs non conformes aux conventions REST. "
                . "Les URLs REST doivent etre en kebab-case (minuscules separees par des tirets). "
                . "Le shortName actuel produira /api/{$shortName} au lieu de /api/{$kebabSuggestion}.",
            suggestion: "Utiliser shortName: '{$kebabSuggestion}' pour des URLs REST conformes.",
            file: $relativePath,
            fixCode: "#[ApiResource(\n"
                . "    shortName: '{$kebabSuggestion}',\n"
                . ")]",
            docUrl: 'https://api-platform.com/docs/core/operations/#shortname',
            businessImpact: "URLs incohérentes entre ressources. Les clients API doivent gerer "
                . "des conventions de nommage mixtes (CamelCase, snake_case, kebab-case).",
            estimatedFixMinutes: 5,
        ));
    }

    /**
     * Detecte les noms de ressources commencant par un verbe CRUD.
     * Une ressource REST represente un nom (substantif), pas une action.
     * /api/create-user est incorrect, /api/users avec POST est correct.
     */
    private function checkVerbInResourceName(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        $className = $this->extractClassName($content);

        foreach (self::VERB_PREFIXES as $verb) {
            if (!str_starts_with($className, $verb)) {
                continue;
            }

            $remaining = substr($className, strlen($verb));

            if ($remaining === '' || !ctype_upper($remaining[0])) {
                continue;
            }

            $report->addIssue(new Issue(
                severity: Severity::SUGGESTION,
                module: Module::API_PLATFORM,
                analyzer: $this->getName(),
                message: "Nom de ressource '{$className}' contient le verbe '{$verb}' dans {$filename}",
                detail: "La ressource '{$className}' commence par le verbe '{$verb}'. "
                    . "En REST, les ressources sont des noms (substantifs), pas des actions. "
                    . "L'action est portee par la methode HTTP (GET, POST, PUT, DELETE), "
                    . "pas par l'URL.",
                suggestion: "Renommer la ressource en utilisant uniquement le substantif. "
                    . "Ex: '{$remaining}' au lieu de '{$className}', avec l'operation appropriee.",
                file: $relativePath,
                fixCode: "// Avant : {$className} avec une seule operation\n"
                    . "// Apres : {$remaining} avec les operations REST standard\n"
                    . "#[ApiResource(\n"
                    . "    shortName: '" . $this->toKebabCase($remaining) . "',\n"
                    . ")]",
                docUrl: 'https://restfulapi.net/resource-naming/',
                businessImpact: "L'API ne suit pas les conventions REST. Les developpeurs "
                    . "qui consomment l'API doivent deviner les conventions de chaque endpoint.",
                estimatedFixMinutes: 15,
            ));

            return;
        }
    }

    /**
     * Detecte les routePrefix avec des conventions de nommage non standard.
     */
    private function checkRoutePrefix(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        if (!preg_match("/routePrefix\s*:\s*['\"]([^'\"]+)['\"]/", $content, $match)) {
            return;
        }

        $prefix = $match[1];

        // Verifie que le prefix est en kebab-case et commence par /.
        if (preg_match('#^/[a-z][a-z0-9]*(/[a-z][a-z0-9]*(-[a-z0-9]+)*)*$#', $prefix)) {
            return;
        }

        // Verifie les problemes courants.
        if (str_contains($prefix, '_')) {
            $report->addIssue(new Issue(
                severity: Severity::SUGGESTION,
                module: Module::API_PLATFORM,
                analyzer: $this->getName(),
                message: "routePrefix avec underscores dans {$filename}",
                detail: "Le routePrefix '{$prefix}' utilise des underscores. "
                    . "Les conventions REST recommandent le kebab-case pour les URLs.",
                suggestion: "Remplacer les underscores par des tirets dans le routePrefix.",
                file: $relativePath,
                fixCode: "#[ApiResource(\n"
                    . "    routePrefix: '" . str_replace('_', '-', $prefix) . "',\n"
                    . ")]",
                docUrl: 'https://api-platform.com/docs/core/operations/#prefixing-all-routes-of-all-operations',
                businessImpact: "Incoherence dans les conventions d'URL de l'API.",
                estimatedFixMinutes: 5,
            ));
        }
    }

    private function toKebabCase(string $input): string
    {
        // CamelCase/PascalCase vers kebab-case.
        $result = preg_replace('/([a-z])([A-Z])/', '$1-$2', $input);
        $result = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', (string) $result);

        return strtolower(str_replace('_', '-', (string) $result));
    }

    private function extractClassName(string $content): string
    {
        if (preg_match('/\bclass\s+(\w+)/', $content, $matches)) {
            return $matches[1];
        }

        return 'Resource';
    }
}
