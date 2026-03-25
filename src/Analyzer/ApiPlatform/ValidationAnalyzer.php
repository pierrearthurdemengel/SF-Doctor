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
 * Verifie que les ressources API Platform avec operations POST/PUT
 * ont des contraintes de validation (#[Assert\*]).
 *
 * Sans validation, les entites sont persistees directement avec les donnees
 * envoyees par le client, sans aucun controle d'integrite.
 */
final class ValidationAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
    ) {
    }

    public function analyze(AuditReport $report): void
    {
        $dirs = [
            $this->projectPath . '/src/Entity',
            $this->projectPath . '/src/ApiResource',
        ];

        foreach ($dirs as $dir) {
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

                // Only process files with #[ApiResource]
                if (!str_contains($content, '#[ApiResource') && !str_contains($content, 'ApiResource(')) {
                    continue;
                }

                $relativePath = str_replace('\\', '/', str_replace(
                    str_replace('\\', '/', $this->projectPath) . '/',
                    '',
                    str_replace('\\', '/', $file->getRealPath()),
                ));

                $this->checkWriteOperationsWithoutValidation($report, $content, $relativePath);
                $this->checkNotNullColumnsWithoutNotBlank($report, $content, $relativePath);
            }
        }
    }

    public function getName(): string
    {
        return 'API Platform Validation Analyzer';
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
     * Detecte les ressources API Platform avec operations POST/PUT
     * mais sans aucune contrainte Assert dans le fichier.
     */
    private function checkWriteOperationsWithoutValidation(
        AuditReport $report,
        string $content,
        string $file,
    ): void {
        $hasWriteOperation = str_contains($content, '#[Post')
            || str_contains($content, '#[Put')
            || str_contains($content, '#[Patch')
            || preg_match('/operations\s*:\s*\[/', $content);

        if (!$hasWriteOperation) {
            return;
        }

        $hasAssert = str_contains($content, '#[Assert\\')
            || str_contains($content, '@Assert\\')
            || str_contains($content, 'use Symfony\Component\Validator\Constraints');

        if ($hasAssert) {
            return;
        }

        if (!preg_match('/\bclass\s+(\w+)/', $content, $m)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: sprintf("Resource '%s' avec operation d'ecriture sans validation", $m[1]),
            detail: "La ressource API Platform '{$m[1]}' possede des operations d'ecriture "
                . "(POST, PUT, PATCH) mais aucune contrainte de validation (#[Assert\\*]) "
                . "n'est definie. Les donnees envoyees par le client seront persistees "
                . "sans aucun controle d'integrite.",
            suggestion: "Ajouter des contraintes de validation sur les proprietes de la ressource "
                . "avec les attributs #[Assert\\NotBlank], #[Assert\\Email], #[Assert\\Length], etc.",
            file: $file,
            businessImpact: "Les donnees invalides peuvent etre persistees en base, corrompant "
                . "les donnees metier et provoquant des erreurs dans les traitements en aval.",
            fixCode: "use Symfony\\Component\\Validator\\Constraints as Assert;\n\n"
                . "#[Assert\\NotBlank]\n"
                . "#[Assert\\Length(max: 255)]\n"
                . "private string \$name;",
            docUrl: 'https://api-platform.com/docs/core/validation/',
            estimatedFixMinutes: 20,
        ));
    }

    /**
     * Detecte les colonnes NOT NULL (nullable: false) sans #[Assert\NotBlank].
     */
    private function checkNotNullColumnsWithoutNotBlank(
        AuditReport $report,
        string $content,
        string $file,
    ): void {
        $lines = explode("\n", $content);
        $pendingColumn = false;
        $columnIsNotNull = false;
        $propertyName = null;

        foreach ($lines as $line) {
            // Detect ORM\Column with nullable: false or without nullable (default NOT NULL)
            if (preg_match('/#\[ORM\\\\Column/', $line)) {
                $pendingColumn = true;
                $columnIsNotNull = !str_contains($line, 'nullable: true') && !str_contains($line, 'nullable:true');
                continue;
            }

            // Detect the property declaration after the Column annotation
            if ($pendingColumn && preg_match('/(?:private|public|protected)\s+\w+\s+\$(\w+)/', $line, $m)) {
                $propertyName = $m[1];
                $pendingColumn = false;

                if (!$columnIsNotNull) {
                    continue;
                }

                // Check if this property has #[Assert\NotBlank] nearby (within prev 5 lines)
                $lineIndex = array_search($line, $lines, true);
                if ($lineIndex === false) {
                    continue;
                }

                $contextStart = max(0, (int) $lineIndex - 5);
                $context = implode("\n", array_slice($lines, $contextStart, (int) $lineIndex - $contextStart + 1));

                if (str_contains($context, 'Assert\\NotBlank') || str_contains($context, 'Assert\\NotNull')) {
                    continue;
                }

                // Skip id fields
                if ($propertyName === 'id') {
                    continue;
                }

                $report->addIssue(new Issue(
                    severity: Severity::WARNING,
                    module: Module::API_PLATFORM,
                    analyzer: $this->getName(),
                    message: sprintf("Champ obligatoire '%s' sans #[Assert\\NotBlank]", $propertyName),
                    detail: "La propriete '\${$propertyName}' est NOT NULL en base de donnees "
                        . "mais n'a pas de contrainte #[Assert\\NotBlank] ou #[Assert\\NotNull]. "
                        . "L'API retournera une erreur 500 (SQL constraint violation) "
                        . "au lieu d'une erreur 422 avec un message de validation clair.",
                    suggestion: "Ajouter #[Assert\\NotBlank] sur cette propriete pour retourner "
                        . "une erreur de validation explicite au client.",
                    file: $file,
                    businessImpact: "Les clients de l'API recoivent une erreur 500 incomprehensible "
                        . "au lieu d'une erreur 422 avec le champ en erreur. Mauvaise experience "
                        . "developpeur et difficulte de debug.",
                    fixCode: "#[Assert\\NotBlank(message: 'Le champ {$propertyName} est obligatoire')]\n"
                        . "private string \${$propertyName};",
                    docUrl: 'https://symfony.com/doc/current/validation.html#constraints',
                    estimatedFixMinutes: 5,
                ));
            }
        }
    }
}
