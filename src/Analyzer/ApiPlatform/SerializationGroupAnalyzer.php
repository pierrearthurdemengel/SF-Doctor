<?php

// src/Analyzer/ApiPlatform/SerializationGroupAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\ApiPlatform;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les problemes de serialisation dans les entites API Platform.
 *
 * Deux niveaux d'analyse :
 * 1. Entite avec #[ApiResource] mais aucun #[Groups] sur les proprietes (tout est expose)
 * 2. Proprietes sensibles (password, token, secret) sans #[Ignore]
 */
final class SerializationGroupAnalyzer implements AnalyzerInterface
{
    // Noms de proprietes considerees comme sensibles.
    private const SENSITIVE_PROPERTIES = [
        'password',
        'token',
        'secret',
    ];

    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $entityDir = $this->projectPath . '/src/Entity';

        if (!is_dir($entityDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($entityDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());

            if ($content === false) {
                continue;
            }

            if (!str_contains($content, '#[ApiResource')) {
                continue;
            }

            $realPath = str_replace('\\', '/', $file->getRealPath());
            $normalizedDir = str_replace('\\', '/', $entityDir);
            $relativePath = 'src/Entity/' . ltrim(
                str_replace($normalizedDir, '', $realPath),
                '/',
            );

            $this->checkMissingGroups($report, $content, $relativePath, $file->getFilename());
            $this->checkSensitiveProperties($report, $content, $relativePath, $file->getFilename());
        }
    }

    public function getName(): string
    {
        return 'Serialization Group Analyzer';
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
     * Detecte les entites API Platform sans aucun #[Groups] sur les proprietes.
     * Sans groupes de serialisation, toutes les proprietes de l'entite sont exposees
     * dans les reponses API, y compris les champs internes ou sensibles.
     */
    private function checkMissingGroups(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // Si au moins un #[Groups] est present, l'entite est partiellement protegee.
        if (str_contains($content, '#[Groups')) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "Aucun #[Groups] sur les proprietes de {$filename}",
            detail: "L'entite '{$filename}' est exposee via #[ApiResource] "
                . "mais aucune propriete ne porte d'attribut #[Groups(...)]. "
                . "Sans groupes de serialisation, toutes les proprietes "
                . "sont exposees dans les reponses API, y compris les champs internes.",
            suggestion: "Ajouter #[Groups(['read'])] sur les proprietes a exposer en lecture "
                . "et #[Groups(['write'])] sur celles modifiables. "
                . "Configurer normalizationContext et denormalizationContext sur #[ApiResource].",
            file: $relativePath,
            fixCode: "#[ApiResource(\n"
                . "    normalizationContext: ['groups' => ['read']],\n"
                . "    denormalizationContext: ['groups' => ['write']],\n"
                . ")]\n"
                . "class {$this->extractClassName($content)}\n"
                . "{\n"
                . "    #[Groups(['read'])]\n"
                . "    private ?string \$name = null;\n"
                . "}",
            docUrl: 'https://api-platform.com/docs/core/serialization/',
            businessImpact: 'Toutes les proprietes de l\'entite sont visibles dans l\'API. '
                . 'Des champs internes (id technique, dates systeme, relations) '
                . 'sont exposes involontairement, augmentant la surface d\'attaque.',
            estimatedFixMinutes: 30,
        ));
    }

    /**
     * Detecte les proprietes sensibles (password, token, secret) sans #[Ignore].
     * Ces proprietes ne devraient jamais apparaitre dans les reponses API.
     */
    private function checkSensitiveProperties(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        foreach (self::SENSITIVE_PROPERTIES as $propertyName) {
            $pattern = '/\$' . preg_quote($propertyName, '/') . '\b/';

            if (!preg_match($pattern, $content)) {
                continue;
            }

            // Verifie si la propriete est protegee par #[Ignore].
            if ($this->hasIgnoreAttribute($content, $propertyName)) {
                continue;
            }

            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::API_PLATFORM,
                analyzer: $this->getName(),
                message: "Propriete sensible '\${$propertyName}' sans #[Ignore] dans {$filename}",
                detail: "L'entite API Platform '{$filename}' contient une propriete '\${$propertyName}' "
                    . "sans attribut #[Ignore]. Cette donnee sensible peut etre exposee "
                    . "dans les reponses API si aucun groupe de serialisation ne la filtre.",
                suggestion: "Ajouter #[Ignore] sur la propriete '\${$propertyName}' "
                    . "pour l'exclure de toute serialisation API.",
                file: $relativePath,
                fixCode: "use Symfony\\Component\\Serializer\\Annotation\\Ignore;\n\n"
                    . "#[Ignore]\n"
                    . "private ?string \${$propertyName} = null;",
                docUrl: 'https://api-platform.com/docs/core/serialization/#ignoring-properties',
                businessImpact: "La propriete '{$propertyName}' contient des donnees sensibles "
                    . "qui peuvent etre exposees dans les reponses de l'API. "
                    . "Un attaquant peut recuperer des mots de passe, tokens ou secrets.",
                estimatedFixMinutes: 5,
            ));
        }
    }

    /**
     * Verifie si une propriete possede l'attribut #[Ignore] dans les lignes precedentes.
     */
    private function hasIgnoreAttribute(string $content, string $propertyName): bool
    {
        $lines = explode("\n", $content);
        $propertyPattern = '/\$' . preg_quote($propertyName, '/') . '\b/';

        foreach ($lines as $lineNumber => $line) {
            if (!preg_match($propertyPattern, $line)) {
                continue;
            }

            // Regarde les 5 lignes precedentes pour trouver #[Ignore].
            $lookback = max(0, $lineNumber - 5);
            $context = implode("\n", array_slice($lines, $lookback, $lineNumber - $lookback));

            if (preg_match('/#\[Ignore\]/', $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extrait le nom de la classe depuis le contenu du fichier.
     */
    private function extractClassName(string $content): string
    {
        if (preg_match('/\bclass\s+(\w+)/', $content, $matches)) {
            return $matches[1];
        }

        return 'Entity';
    }
}
