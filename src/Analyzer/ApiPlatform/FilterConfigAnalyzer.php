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
 * Detecte les configurations de filtres API Platform dangereuses ou manquantes.
 *
 * Les filtres API Platform permettent au client de filtrer les collections
 * via des parametres de query string. Une mauvaise configuration expose
 * des vecteurs d'attaque : enumeration de donnees sensibles via SearchFilter,
 * decouverte de structure via OrderFilter non restreint.
 */
final class FilterConfigAnalyzer implements AnalyzerInterface
{
    // Proprietes sur lesquelles un SearchFilter partial est dangereux.
    private const SENSITIVE_FILTER_PROPERTIES = [
        'password',
        'token',
        'secret',
        'apiKey',
        'api_key',
        'email',
        'phone',
        'ssn',
        'creditCard',
        'credit_card',
        'salt',
        'hash',
        'plainPassword',
        'plain_password',
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

                $this->checkSensitiveSearchFilter($report, $content, $relativePath, $file->getFilename());
                $this->checkUnrestrictedOrderFilter($report, $content, $relativePath, $file->getFilename());
                $this->checkMissingFilters($report, $content, $relativePath, $file->getFilename());
            }
        }
    }

    public function getName(): string
    {
        return 'Filter Config Analyzer';
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
     * Detecte les SearchFilter avec strategy 'partial' sur des proprietes sensibles.
     * Un attaquant peut enumerer des valeurs en testant des prefixes successifs :
     * /api/users?email=a, /api/users?email=ab, /api/users?email=abc...
     */
    private function checkSensitiveSearchFilter(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // Detecte #[ApiFilter(SearchFilter::class, properties: ['email' => 'partial'])]
        if (!preg_match_all('/#\[ApiFilter\s*\(\s*SearchFilter::class[^]]*\]/s', $content, $filterMatches)) {
            return;
        }

        foreach ($filterMatches[0] as $filterBlock) {
            // Verifie si le filtre utilise la strategy 'partial', 'start', ou 'word_start'.
            if (!preg_match('/(?:partial|start|word_start)/', $filterBlock)) {
                continue;
            }

            foreach (self::SENSITIVE_FILTER_PROPERTIES as $sensitiveField) {
                $fieldPattern = '/[\'"]' . preg_quote($sensitiveField, '/') . '[\'"]\s*=>\s*[\'"](?:partial|start|word_start)/';

                if (!preg_match($fieldPattern, $filterBlock)) {
                    continue;
                }

                $report->addIssue(new Issue(
                    severity: Severity::CRITICAL,
                    module: Module::API_PLATFORM,
                    analyzer: $this->getName(),
                    message: "SearchFilter partial sur champ sensible '{$sensitiveField}' dans {$filename}",
                    detail: "Le filtre SearchFilter avec strategy 'partial' sur la propriete "
                        . "'{$sensitiveField}' permet a un attaquant d'enumerer les valeurs "
                        . "par recherche incrementale (ex: ?{$sensitiveField}=a, ?{$sensitiveField}=ab, etc.). "
                        . "C'est un vecteur d'attaque classique sur les API REST.",
                    suggestion: "Retirer le SearchFilter de la propriete '{$sensitiveField}', "
                        . "ou utiliser la strategy 'exact' si le filtre est necessaire. "
                        . "Combiner avec un rate limiter sur l'endpoint.",
                    file: $relativePath,
                    fixCode: "// Remplacer partial par exact sur les champs sensibles\n"
                        . "#[ApiFilter(SearchFilter::class, properties: [\n"
                        . "    '{$sensitiveField}' => 'exact',  // jamais 'partial' sur un champ sensible\n"
                        . "])]",
                    docUrl: 'https://api-platform.com/docs/core/filters/#search-filter',
                    businessImpact: "Un attaquant peut decouvrir toutes les valeurs du champ "
                        . "'{$sensitiveField}' par recherche incrementale. Sur un champ email, "
                        . "cela permet d'extraire la liste complete des utilisateurs.",
                    estimatedFixMinutes: 10,
                ));
            }
        }
    }

    /**
     * Detecte les OrderFilter sans restriction de proprietes.
     * Sans restriction, le client peut trier par n'importe quelle colonne,
     * y compris des colonnes internes non indexees (impact performance)
     * et des colonnes sensibles (fuite d'information par tri).
     */
    private function checkUnrestrictedOrderFilter(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // Detecte #[ApiFilter(OrderFilter::class)] sans proprietes specifiees.
        if (!preg_match_all('/#\[ApiFilter\s*\(\s*OrderFilter::class[^]]*\]/s', $content, $matches)) {
            return;
        }

        foreach ($matches[0] as $filterBlock) {
            // Si properties est defini, le filtre est restreint.
            if (str_contains($filterBlock, 'properties')) {
                continue;
            }

            $className = $this->extractClassName($content);

            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::API_PLATFORM,
                analyzer: $this->getName(),
                message: "OrderFilter sans restriction de proprietes dans {$filename}",
                detail: "Le filtre OrderFilter sur '{$className}' n'a pas de liste explicite "
                    . "de proprietes. Le client peut trier par n'importe quelle colonne, "
                    . "y compris des colonnes non indexees (requete lente) "
                    . "ou des colonnes internes (fuite d'information).",
                suggestion: "Specifier la liste des proprietes triables via le parametre properties.",
                file: $relativePath,
                fixCode: "#[ApiFilter(OrderFilter::class, properties: [\n"
                    . "    'name',\n"
                    . "    'createdAt',\n"
                    . "])]",
                docUrl: 'https://api-platform.com/docs/core/filters/#order-filter-sorting',
                businessImpact: "Le tri sur une colonne non indexee ralentit la requete SQL. "
                    . "Sur une table volumineuse, cela peut provoquer un timeout. "
                    . "Le tri par colonne interne revele la structure de la base.",
                estimatedFixMinutes: 10,
            ));
        }
    }

    /**
     * Detecte les ressources API Platform sans aucun filtre.
     * Une collection sans filtre oblige le client a charger toutes les donnees
     * et filtrer cote front, ce qui est inefficace et peu ergonomique.
     */
    private function checkMissingFilters(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // Si au moins un filtre est present, pas de probleme.
        if (str_contains($content, '#[ApiFilter') || str_contains($content, '@ApiFilter')) {
            return;
        }

        // Verifie que la ressource a des operations de collection (GetCollection ou pas d'operations specifiees).
        $hasCollectionOperation = str_contains($content, 'GetCollection')
            || !preg_match('/operations\s*:\s*\[/', $content);

        if (!$hasCollectionOperation) {
            return;
        }

        $className = $this->extractClassName($content);

        $report->addIssue(new Issue(
            severity: Severity::SUGGESTION,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "Aucun filtre configure sur la ressource '{$className}'",
            detail: "La ressource '{$className}' n'a aucun #[ApiFilter] configure. "
                . "Les clients doivent charger la collection entiere pour trouver "
                . "un element specifique, ce qui est inefficace sur les grandes collections.",
            suggestion: "Ajouter au minimum un SearchFilter sur les proprietes de recherche "
                . "principales et un OrderFilter pour le tri.",
            file: $relativePath,
            fixCode: "use ApiPlatform\\Doctrine\\Orm\\Filter\\SearchFilter;\n"
                . "use ApiPlatform\\Doctrine\\Orm\\Filter\\OrderFilter;\n\n"
                . "#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial'])]\n"
                . "#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]",
            docUrl: 'https://api-platform.com/docs/core/filters/',
            businessImpact: "Les developpeurs front doivent paginer manuellement "
                . "et filtrer en memoire, ce qui degrade l'experience utilisateur "
                . "et augmente la consommation reseau.",
            estimatedFixMinutes: 15,
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
