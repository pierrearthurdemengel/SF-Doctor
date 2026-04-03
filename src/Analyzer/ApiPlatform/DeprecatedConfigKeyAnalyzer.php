<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\ApiPlatform;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les cles de configuration API Platform depreciees en 3.x.
 *
 * API Platform 3.0 a renomme ou deplace plusieurs cles de configuration.
 * Les anciennes cles continuent de fonctionner mais generent des deprecation
 * notices et seront supprimees en 4.0.
 */
final class DeprecatedConfigKeyAnalyzer implements AnalyzerInterface
{
    /**
     * Cles depreciees en API Platform 3.x et leurs remplacements.
     * Structure : ancienne cle => [nouvelle cle, message].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const DEPRECATED_KEYS = [
        'collection' => [
            'defaults',
            "La cle 'collection' est depreciee depuis API Platform 3.0. "
                . "Les options de pagination et d'ordre sont desormais sous 'defaults'.",
        ],
        'item_operations' => [
            'operations (attribut PHP)',
            "La cle 'item_operations' en YAML est depreciee. Les operations doivent "
                . "etre configurees via les attributs PHP #[Get], #[Put], etc.",
        ],
        'collection_operations' => [
            'operations (attribut PHP)',
            "La cle 'collection_operations' en YAML est depreciee. Les operations doivent "
                . "etre configurees via les attributs PHP #[GetCollection], #[Post], etc.",
        ],
        'exception_to_status' => [
            'errors (Symfony ErrorHandler)',
            "La cle 'exception_to_status' est depreciee depuis API Platform 3.2. "
                . "Utiliser le systeme d'erreurs Symfony avec #[WithHttpStatus] a la place.",
        ],
        'allow_plain_identifiers' => [
            'N/A (supprime)',
            "La cle 'allow_plain_identifiers' est depreciee et sera supprimee en 4.0.",
        ],
    ];

    /**
     * Cles de configuration dangereuses qui ne devraient pas etre en production.
     *
     * @var list<string>
     */
    private const DANGEROUS_KEYS = [
        'enable_docs',
        'enable_entrypoint',
    ];

    public function __construct(
        private readonly ConfigReaderInterface $configReader,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $config = $this->configReader->read('config/packages/api_platform.yaml');

        if ($config === null) {
            return;
        }

        $apiPlatformConfig = $config['api_platform'] ?? null;

        if (!is_array($apiPlatformConfig)) {
            return;
        }

        $this->checkDeprecatedKeys($report, $apiPlatformConfig);
        $this->checkDocsInProduction($report, $apiPlatformConfig);
        $this->checkSwaggerVersions($report, $apiPlatformConfig);
    }

    public function getName(): string
    {
        return 'Deprecated Config Key Analyzer';
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
     * Detecte les cles de configuration depreciees dans api_platform.yaml.
     *
     * @param array<string, mixed> $apiPlatformConfig
     */
    private function checkDeprecatedKeys(AuditReport $report, array $apiPlatformConfig): void
    {
        foreach (self::DEPRECATED_KEYS as $key => [$replacement, $detail]) {
            if (!array_key_exists($key, $apiPlatformConfig)) {
                continue;
            }

            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::API_PLATFORM,
                analyzer: $this->getName(),
                message: "Cle de configuration depreciee '{$key}' dans api_platform.yaml",
                detail: $detail,
                suggestion: "Migrer vers la configuration '{$replacement}'.",
                file: 'config/packages/api_platform.yaml',
                fixCode: "# Cle depreciee a remplacer :\n"
                    . "# {$key}: ...  (supprime)\n"
                    . "# Remplacement : {$replacement}",
                docUrl: 'https://api-platform.com/docs/core/upgrade-guide/',
                businessImpact: "Deprecation notices dans les logs. La cle sera supprimee "
                    . "en API Platform 4.0, bloquant la montee de version.",
                estimatedFixMinutes: 15,
            ));
        }
    }

    /**
     * Detecte enable_docs: true explicite sans restriction par environnement.
     * La documentation Swagger/OpenAPI expose l'integralite du schema API
     * et facilite la reconnaissance par un attaquant.
     *
     * @param array<string, mixed> $apiPlatformConfig
     */
    private function checkDocsInProduction(AuditReport $report, array $apiPlatformConfig): void
    {
        foreach (self::DANGEROUS_KEYS as $key) {
            $value = $apiPlatformConfig[$key] ?? null;

            if ($value !== true) {
                continue;
            }

            $report->addIssue(new Issue(
                severity: Severity::SUGGESTION,
                module: Module::API_PLATFORM,
                analyzer: $this->getName(),
                message: "'{$key}: true' dans la configuration API Platform globale",
                detail: "La cle '{$key}' est explicitement activee dans la configuration globale. "
                    . "Si ce fichier est utilise en production, la documentation API et le point "
                    . "d'entree sont accessibles publiquement, exposant le schema complet de l'API.",
                suggestion: "Desactiver '{$key}' en production ou deplacer cette configuration "
                    . "dans config/packages/dev/api_platform.yaml.",
                file: 'config/packages/api_platform.yaml',
                fixCode: "# config/packages/prod/api_platform.yaml\n"
                    . "api_platform:\n"
                    . "    {$key}: false",
                docUrl: 'https://api-platform.com/docs/core/openapi/',
                businessImpact: "Un attaquant peut acceder a la documentation complete de l'API "
                    . "et cartographier tous les endpoints, parametres et schemas.",
                estimatedFixMinutes: 5,
            ));
        }
    }

    /**
     * Detecte les configurations swagger qui referencent des versions depreciees.
     *
     * @param array<string, mixed> $apiPlatformConfig
     */
    private function checkSwaggerVersions(AuditReport $report, array $apiPlatformConfig): void
    {
        $swaggerVersions = $apiPlatformConfig['swagger']['versions'] ?? null;

        if ($swaggerVersions === null) {
            return;
        }

        if (!is_array($swaggerVersions)) {
            return;
        }

        if (!in_array(2, $swaggerVersions, true)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "Swagger 2 encore active dans la configuration API Platform",
            detail: "La configuration swagger.versions inclut la version 2. "
                . "Swagger 2 (OpenAPI 2.0) est depreciee depuis 2017 au profit de OpenAPI 3.x. "
                . "Le support de Swagger 2 sera supprime en API Platform 4.0.",
            suggestion: "Retirer la version 2 de swagger.versions et ne garder que OpenAPI 3.",
            file: 'config/packages/api_platform.yaml',
            fixCode: "# config/packages/api_platform.yaml\n"
                . "api_platform:\n"
                . "    swagger:\n"
                . "        versions: [3]",
            docUrl: 'https://api-platform.com/docs/core/openapi/',
            businessImpact: "La documentation Swagger 2 est incompatible avec les outils modernes "
                . "(Postman, Stoplight, etc.) et ne supporte pas les schemas avances.",
            estimatedFixMinutes: 10,
        ));
    }
}
