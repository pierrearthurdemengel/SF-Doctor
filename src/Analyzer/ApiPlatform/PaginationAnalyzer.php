<?php

// src/Analyzer/ApiPlatform/PaginationAnalyzer.php

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
 * Analyse la configuration de pagination d'API Platform.
 *
 * Verifie les points critiques :
 * 1. Pagination desactivee globalement (toutes les collections retournees d'un coup)
 * 2. client_items_per_page actif sans maximum_items_per_page (risque de DoS)
 */
final class PaginationAnalyzer implements AnalyzerInterface
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

        $this->checkPaginationDisabled($report, $apiPlatformConfig);
        $this->checkClientItemsPerPage($report, $apiPlatformConfig);
    }

    public function getName(): string
    {
        return 'Pagination Analyzer';
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
     * Detecte la desactivation globale de la pagination.
     * Sans pagination, une requete sur une collection retourne TOUS les enregistrements,
     * ce qui peut saturer la memoire et le reseau.
     *
     * @param array<mixed> $apiPlatformConfig
     */
    private function checkPaginationDisabled(AuditReport $report, array $apiPlatformConfig): void
    {
        $paginationEnabled = $apiPlatformConfig['defaults']['pagination_enabled']
            ?? $apiPlatformConfig['collection']['pagination']['enabled']
            ?? null;

        if ($paginationEnabled !== false) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: 'Pagination desactivee globalement dans API Platform',
            detail: 'La configuration api_platform.yaml desactive la pagination par defaut. '
                . 'Toutes les collections retournent l\'integralite des enregistrements '
                . 'en une seule reponse, ce qui peut saturer la memoire du serveur '
                . 'et le reseau pour les tables volumineuses.',
            suggestion: 'Reactiver la pagination globalement et la desactiver au cas par cas '
                . 'sur les ressources qui le necessitent.',
            file: 'config/packages/api_platform.yaml',
            fixCode: "# config/packages/api_platform.yaml\napi_platform:\n"
                . "    defaults:\n"
                . "        pagination_enabled: true\n"
                . "        pagination_items_per_page: 30",
            docUrl: 'https://api-platform.com/docs/core/pagination/',
            businessImpact: 'Sans pagination, une collection de 100 000 enregistrements est retournee '
                . 'en une seule reponse. Cela peut provoquer un out-of-memory sur le serveur, '
                . 'un timeout pour le client ou une facture reseau excessive.',
            estimatedFixMinutes: 10,
        ));
    }

    /**
     * Detecte client_items_per_page actif sans maximum_items_per_page.
     * Quand le client peut choisir le nombre d'elements par page sans limite,
     * il peut demander items_per_page=999999 et provoquer un deni de service.
     *
     * @param array<mixed> $apiPlatformConfig
     */
    private function checkClientItemsPerPage(AuditReport $report, array $apiPlatformConfig): void
    {
        $clientItemsPerPage = $apiPlatformConfig['defaults']['pagination_client_items_per_page']
            ?? $apiPlatformConfig['collection']['pagination']['client_items_per_page']
            ?? null;

        if ($clientItemsPerPage !== true) {
            return;
        }

        $maximumItemsPerPage = $apiPlatformConfig['defaults']['pagination_maximum_items_per_page']
            ?? $apiPlatformConfig['collection']['pagination']['maximum_items_per_page']
            ?? null;

        if ($maximumItemsPerPage !== null) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: 'client_items_per_page actif sans maximum_items_per_page',
            detail: 'La configuration API Platform permet au client de choisir le nombre '
                . 'd\'elements par page (client_items_per_page: true) mais ne definit pas '
                . 'de maximum_items_per_page. Un client malveillant peut demander '
                . 'items_per_page=999999 pour surcharger le serveur.',
            suggestion: 'Definir maximum_items_per_page pour limiter le nombre d\'elements '
                . 'que le client peut demander par page.',
            file: 'config/packages/api_platform.yaml',
            fixCode: "# config/packages/api_platform.yaml\napi_platform:\n"
                . "    defaults:\n"
                . "        pagination_client_items_per_page: true\n"
                . "        pagination_maximum_items_per_page: 100",
            docUrl: 'https://api-platform.com/docs/core/pagination/#changing-the-number-of-items-per-page',
            businessImpact: 'Un attaquant peut envoyer une requete avec items_per_page=999999 '
                . 'pour forcer le serveur a charger et serialiser des centaines de milliers '
                . 'd\'enregistrements, provoquant un deni de service (DoS).',
            estimatedFixMinutes: 5,
        ));
    }
}
