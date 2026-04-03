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
 * Analyse la configuration globale d'API Platform dans api_platform.yaml.
 *
 * Detecte les erreurs de configuration qui affectent toute l'API :
 * format d'erreur par defaut, show_webby, configuration des formats,
 * et parametres de production manquants.
 */
final class GlobalApiPlatformConfigAnalyzer implements AnalyzerInterface
{
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

        if ($apiPlatformConfig === null) {
            return;
        }

        $this->checkShowWebby($report, $apiPlatformConfig);
        $this->checkErrorFormats($report, $apiPlatformConfig);
        $this->checkDefaultFormat($report, $apiPlatformConfig);
        $this->checkMappingPaths($report, $apiPlatformConfig);
    }

    public function getName(): string
    {
        return 'Global API Platform Config Analyzer';
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
     * Detecte show_webby actif en production.
     * Le "Webby" est la mascotte API Platform affichee sur la page d'accueil de l'API.
     * En production, cela revele que l'application utilise API Platform
     * et donne des informations a un attaquant sur la stack technique.
     */
    /** @param array<string, mixed> $apiPlatformConfig */
    private function checkShowWebby(AuditReport $report, array $apiPlatformConfig): void
    {
        // show_webby est true par defaut si non specifie.
        $showWebby = $apiPlatformConfig['show_webby'] ?? true;

        if ($showWebby !== true) {
            return;
        }

        // Si la cle n'existe pas du tout, show_webby est actif par defaut.
        if (!array_key_exists('show_webby', $apiPlatformConfig)) {
            $report->addIssue(new Issue(
                severity: Severity::SUGGESTION,
                module: Module::API_PLATFORM,
                analyzer: $this->getName(),
                message: "show_webby actif par defaut dans API Platform",
                detail: "La configuration API Platform n'a pas de cle show_webby. "
                    . "Par defaut, la mascotte Webby est affichee sur la page d'accueil de l'API, "
                    . "revelant la stack technique a un attaquant.",
                suggestion: "Ajouter show_webby: false dans la configuration API Platform en production.",
                file: 'config/packages/api_platform.yaml',
                fixCode: "# config/packages/api_platform.yaml\napi_platform:\n"
                    . "    show_webby: false",
                docUrl: 'https://api-platform.com/docs/core/configuration/',
                businessImpact: "Un attaquant sait que le projet utilise API Platform "
                    . "et peut cibler ses vulnerabilites connues.",
                estimatedFixMinutes: 2,
            ));

            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::SUGGESTION,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "show_webby: true dans la configuration API Platform",
            detail: "La configuration show_webby est explicitement activee. "
                . "La mascotte Webby apparait sur la page d'accueil de l'API "
                . "et revele la stack technique utilisee.",
            suggestion: "Passer show_webby: false pour masquer la stack technique en production.",
            file: 'config/packages/api_platform.yaml',
            fixCode: "# config/packages/api_platform.yaml\napi_platform:\n"
                . "    show_webby: false",
            docUrl: 'https://api-platform.com/docs/core/configuration/',
            businessImpact: "Information leakage : un attaquant sait que le projet utilise API Platform.",
            estimatedFixMinutes: 2,
        ));
    }

    /**
     * Detecte l'absence de configuration des formats d'erreur.
     * Sans error_formats explicite, les erreurs en production peuvent retourner
     * des stack traces HTML au lieu de reponses JSON structurees.
     */
    /** @param array<string, mixed> $apiPlatformConfig */
    private function checkErrorFormats(AuditReport $report, array $apiPlatformConfig): void
    {
        $errorFormats = $apiPlatformConfig['error_formats'] ?? null;

        if ($errorFormats !== null) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "Aucun error_formats configure dans API Platform",
            detail: "La configuration API Platform ne definit pas de error_formats. "
                . "Par defaut, les erreurs peuvent etre retournees en HTML, "
                . "ce qui est inutilisable par les clients API et peut exposer "
                . "des informations sensibles (stack traces).",
            suggestion: "Configurer error_formats pour garantir des reponses d'erreur JSON.",
            file: 'config/packages/api_platform.yaml',
            fixCode: "# config/packages/api_platform.yaml\napi_platform:\n"
                . "    error_formats:\n"
                . "        jsonproblem: ['application/problem+json']\n"
                . "        jsonld: ['application/ld+json']\n"
                . "        jsonapi: ['application/vnd.api+json']",
            docUrl: 'https://api-platform.com/docs/core/errors/',
            businessImpact: "Les clients API recoivent des erreurs HTML incomprehensibles "
                . "au lieu de reponses JSON structurees. Cela complique le debug "
                . "et peut exposer des stack traces en production.",
            estimatedFixMinutes: 5,
        ));
    }

    /**
     * Detecte un format par defaut HTML ou non adapte a une API.
     */
    /** @param array<string, mixed> $apiPlatformConfig */
    private function checkDefaultFormat(AuditReport $report, array $apiPlatformConfig): void
    {
        $formats = $apiPlatformConfig['formats'] ?? null;

        if ($formats === null) {
            return;
        }

        // Verifie si HTML est dans les formats acceptes.
        if (!is_array($formats)) {
            return;
        }

        if (!array_key_exists('html', $formats)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "Format HTML active dans les formats API Platform",
            detail: "Le format HTML est inclus dans la liste des formats API Platform. "
                . "Une API REST ne devrait pas servir de HTML. "
                . "Le format HTML est utile en developpement (documentation Swagger) "
                . "mais doit etre desactive en production.",
            suggestion: "Retirer le format HTML ou le restreindre a l'environnement de developpement.",
            file: 'config/packages/api_platform.yaml',
            fixCode: "# config/packages/api_platform.yaml\napi_platform:\n"
                . "    formats:\n"
                . "        jsonld: ['application/ld+json']\n"
                . "        json: ['application/json']",
            docUrl: 'https://api-platform.com/docs/core/content-negotiation/',
            businessImpact: "Les clients peuvent accidentellement recevoir du HTML au lieu de JSON. "
                . "Cela peut aussi exposer la documentation Swagger en production.",
            estimatedFixMinutes: 5,
        ));
    }

    /**
     * Detecte l'absence de configuration mapping pour les ressources API.
     * Avec API Platform 3.x, les paths doivent etre configures explicitement.
     */
    /** @param array<string, mixed> $apiPlatformConfig */
    private function checkMappingPaths(AuditReport $report, array $apiPlatformConfig): void
    {
        // mapping.paths doit inclure les repertoires contenant les ressources API.
        $mappingPaths = $apiPlatformConfig['mapping']['paths'] ?? null;

        if ($mappingPaths !== null) {
            return;
        }

        // Si pas de mapping.paths configure, API Platform utilise src/Entity par defaut.
        // Ce n'est pas un probleme en soi, mais sur un projet qui utilise src/ApiResource/
        // les ressources ne seront pas detectees.
        $report->addIssue(new Issue(
            severity: Severity::SUGGESTION,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "mapping.paths non configure dans API Platform",
            detail: "La configuration API Platform ne definit pas de mapping.paths explicite. "
                . "Par defaut, seul src/Entity est scanne. Si des ressources API "
                . "sont dans src/ApiResource ou d'autres repertoires, elles ne seront pas detectees.",
            suggestion: "Configurer mapping.paths pour inclure tous les repertoires de ressources.",
            file: 'config/packages/api_platform.yaml',
            fixCode: "# config/packages/api_platform.yaml\napi_platform:\n"
                . "    mapping:\n"
                . "        paths:\n"
                . "            - '%kernel.project_dir%/src/Entity'\n"
                . "            - '%kernel.project_dir%/src/ApiResource'",
            docUrl: 'https://api-platform.com/docs/core/configuration/',
            businessImpact: "Les ressources API definies hors de src/Entity ne sont pas chargees. "
                . "Des endpoints 404 sans explication apparaissent.",
            estimatedFixMinutes: 5,
        ));
    }
}
