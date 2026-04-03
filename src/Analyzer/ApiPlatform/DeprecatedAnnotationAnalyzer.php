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
 * Detecte les annotations API Platform 2.x qui doivent etre migrees
 * vers les attributs PHP 8.1+ d'API Platform 3.x.
 *
 * Les annotations Doctrine (@ApiResource, @ApiFilter, etc.) sont depreciees
 * depuis API Platform 3.0 et ne seront plus supportees en 4.0.
 * La migration vers les attributs PHP natifs est requise.
 */
final class DeprecatedAnnotationAnalyzer implements AnalyzerInterface
{
    /**
     * Annotations depreciees et leur equivalent en attribut PHP 8.1+.
     * Cle = annotation, Valeur = [nom, remplacement].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const DEPRECATED_ANNOTATIONS = [
        '@ApiResource' => ['@ApiResource', '#[ApiResource]'],
        '@ApiFilter' => ['@ApiFilter', '#[ApiFilter]'],
        '@ApiProperty' => ['@ApiProperty', '#[ApiProperty]'],
        '@ApiSubresource' => ['@ApiSubresource', 'uriTemplate sur #[GetCollection] ou #[Get]'],
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

                if ($content === false) {
                    continue;
                }

                $realPath = str_replace('\\', '/', $file->getRealPath());
                $normalizedDir = str_replace('\\', '/', $dir);
                $relativePath = $relativePrefix . ltrim(
                    str_replace($normalizedDir, '', $realPath),
                    '/',
                );

                $this->checkDeprecatedAnnotations($report, $content, $relativePath, $file->getFilename());
            }
        }
    }

    public function getName(): string
    {
        return 'Deprecated Annotation Analyzer';
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
     * Parcourt les annotations depreciees connues et signale chaque occurrence.
     */
    private function checkDeprecatedAnnotations(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        $lines = explode("\n", $content);

        foreach (self::DEPRECATED_ANNOTATIONS as $annotation => [$name, $replacement]) {
            // Cherche l'annotation dans les commentaires PHPDoc (prefixee par * ou espace).
            $pattern = '/^\s*\*?\s*' . preg_quote($annotation, '/') . '\b/';

            foreach ($lines as $lineIndex => $line) {
                if (!preg_match($pattern, $line)) {
                    continue;
                }

                $lineNumber = $lineIndex + 1;

                if ($annotation === '@ApiSubresource') {
                    $report->addIssue(new Issue(
                        severity: Severity::WARNING,
                        module: Module::API_PLATFORM,
                        analyzer: $this->getName(),
                        message: "{$name} supprime en API Platform 3.x dans {$filename}:{$lineNumber}",
                        detail: "L'annotation {$name} a ete completement supprimee en API Platform 3.0. "
                            . "Les sous-ressources doivent etre declarees via uriTemplate "
                            . "sur les operations #[GetCollection] ou #[Get].",
                        suggestion: "Remplacer {$name} par une operation avec uriTemplate.",
                        file: $relativePath,
                        line: $lineNumber,
                        fixCode: "// Avant (API Platform 2.x)\n"
                            . "/**\n"
                            . " * {$name}\n"
                            . " */\n"
                            . "private Collection \$items;\n\n"
                            . "// Apres (API Platform 3.x)\n"
                            . "#[GetCollection(\n"
                            . "    uriTemplate: '/parents/{parentId}/items',\n"
                            . "    security: \"is_granted('ROLE_USER')\",\n"
                            . ")]",
                        docUrl: 'https://api-platform.com/docs/core/subresources/',
                        businessImpact: "Le code ne fonctionnera pas avec API Platform 3.x. "
                            . "La migration vers la version 3 est bloquee tant que "
                            . "les annotations depreciees ne sont pas remplacees.",
                        estimatedFixMinutes: 20,
                    ));
                } else {
                    $report->addIssue(new Issue(
                        severity: Severity::WARNING,
                        module: Module::API_PLATFORM,
                        analyzer: $this->getName(),
                        message: "Annotation depreciee {$name} dans {$filename}:{$lineNumber}",
                        detail: "L'annotation {$name} est depreciee depuis API Platform 3.0. "
                            . "Les annotations Doctrine seront supprimees en API Platform 4.0. "
                            . "L'equivalent en attribut PHP 8.1+ est {$replacement}.",
                        suggestion: "Remplacer {$name} par l'attribut PHP {$replacement}.",
                        file: $relativePath,
                        line: $lineNumber,
                        fixCode: "// Avant (annotation depreciee)\n"
                            . "/**\n"
                            . " * {$name}(...)\n"
                            . " */\n\n"
                            . "// Apres (attribut PHP 8.1+)\n"
                            . "{$replacement}(...)",
                        docUrl: 'https://api-platform.com/docs/core/upgrade-guide/',
                        businessImpact: "La migration vers API Platform 4.0 sera bloquee. "
                            . "Plus la dette technique s'accumule, plus la migration coutera cher.",
                        estimatedFixMinutes: 5,
                    ));
                }
            }
        }
    }
}
