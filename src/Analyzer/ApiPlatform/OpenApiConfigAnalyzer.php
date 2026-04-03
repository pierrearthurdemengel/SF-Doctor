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
 * Detecte les lacunes de documentation OpenAPI dans les ressources API Platform.
 *
 * Une API sans documentation OpenAPI (Swagger) est une API non maintenable.
 * Les developpeurs front ne savent pas quoi envoyer, les partenaires
 * ne peuvent pas integrer, et les tests automatises sont difficiles a ecrire.
 * Une ressource sans description dans la spec OpenAPI est une shadow API.
 */
final class OpenApiConfigAnalyzer implements AnalyzerInterface
{
    // Operations API Platform dont la documentation est verifiee.
    private const DOCUMENTED_OPERATIONS = [
        'Get',
        'GetCollection',
        'Post',
        'Put',
        'Patch',
        'Delete',
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

                $this->checkResourceDescription($report, $content, $relativePath, $file->getFilename());
                $this->checkOperationDocumentation($report, $content, $relativePath, $file->getFilename());
            }
        }
    }

    public function getName(): string
    {
        return 'OpenAPI Config Analyzer';
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
     * Detecte les ressources #[ApiResource] sans description.
     * La description apparait dans la spec OpenAPI et aide les consommateurs de l'API.
     */
    private function checkResourceDescription(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        if (!preg_match('/#\[ApiResource\b([^]]*)\]/s', $content, $match)) {
            return;
        }

        $apiResourceBlock = $match[0];
        $className = $this->extractClassName($content);

        if (str_contains($apiResourceBlock, 'description')) {
            return;
        }

        // Verifie aussi la presence d'un commentaire PHPDoc @description
        // ou d'un attribut OpenAPI\Operation avec description.
        if (str_contains($content, '#[OA\\') || str_contains($content, 'OpenApi\\')) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::SUGGESTION,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "#[ApiResource] sans description dans {$filename}",
            detail: "La ressource '{$className}' n'a pas de description dans son attribut "
                . "#[ApiResource]. La specification OpenAPI generee ne contiendra pas "
                . "de description pour cette ressource, ce qui rend la documentation "
                . "API incomplete pour les consommateurs.",
            suggestion: "Ajouter un parametre description: sur #[ApiResource] pour documenter "
                . "le role de cette ressource dans l'API.",
            file: $relativePath,
            fixCode: "#[ApiResource(\n"
                . "    description: 'Gestion des " . strtolower($className) . "s.',\n"
                . ")]",
            docUrl: 'https://api-platform.com/docs/core/openapi/',
            businessImpact: "Les developpeurs front et les partenaires ne comprennent pas "
                . "le role de cette ressource dans l'API. L'integration est plus lente.",
            estimatedFixMinutes: 5,
        ));
    }

    /**
     * Detecte les operations API Platform sans description ni summary.
     * Chaque endpoint doit etre documente pour etre exploitable.
     */
    private function checkOperationDocumentation(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        $undocumentedOperations = [];

        foreach (self::DOCUMENTED_OPERATIONS as $operation) {
            $pattern = '/#\[' . $operation . '\b([^]]*)\]/s';

            if (!preg_match_all($pattern, $content, $matches)) {
                continue;
            }

            foreach ($matches[0] as $operationBlock) {
                $hasDoc = str_contains($operationBlock, 'description')
                    || str_contains($operationBlock, 'summary')
                    || str_contains($operationBlock, 'openapi');

                if (!$hasDoc) {
                    $undocumentedOperations[] = $operation;
                }
            }
        }

        if (empty($undocumentedOperations)) {
            return;
        }

        $className = $this->extractClassName($content);
        $operationList = implode(', ', array_unique($undocumentedOperations));

        $report->addIssue(new Issue(
            severity: Severity::SUGGESTION,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: count($undocumentedOperations) . " operation(s) sans documentation dans {$filename}",
            detail: "Les operations [{$operationList}] de la ressource '{$className}' "
                . "n'ont pas de description ou summary. La spec OpenAPI sera generee "
                . "avec des endpoints sans explication, rendant l'API difficile a integrer.",
            suggestion: "Ajouter description: ou summary: sur chaque operation pour documenter "
                . "le comportement de l'endpoint dans la spec OpenAPI.",
            file: $relativePath,
            fixCode: "#[Get(\n"
                . "    description: 'Recupere un(e) " . strtolower($className) . " par son identifiant.',\n"
                . ")]\n"
                . "#[GetCollection(\n"
                . "    description: 'Liste les " . strtolower($className) . "s avec pagination.',\n"
                . ")]",
            docUrl: 'https://api-platform.com/docs/core/openapi/',
            businessImpact: "Les consommateurs de l'API ne comprennent pas ce que font les endpoints. "
                . "L'integration par les equipes front et les partenaires est plus lente et plus fragile.",
            estimatedFixMinutes: 10,
        ));
    }

    private function extractClassName(string $content): string
    {
        if (preg_match('/\bclass\s+(\w+)/', $content, $matches)) {
            return $matches[1];
        }

        return 'Entity';
    }
}
