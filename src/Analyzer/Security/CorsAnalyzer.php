<?php

// src/Analyzer/Security/CorsAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Security;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Verifie la configuration CORS (Cross-Origin Resource Sharing) via NelmioCorsBundle.
 *
 * Detecte les configurations dangereuses : allow_origin: ['*'] avec credentials,
 * wildcard sur des routes non publiques, et l'absence de configuration CORS
 * quand des routes API existent.
 */
final class CorsAnalyzer implements AnalyzerInterface
{
    public function __construct(private readonly ConfigReaderInterface $configReader)
    {
    }

    public function analyze(AuditReport $report): void
    {
        $corsConfig = $this->configReader->read('config/packages/nelmio_cors.yaml');

        if ($corsConfig === null) {
            $this->checkMissingCorsConfig($report);
            return;
        }

        $defaults = $corsConfig['nelmio_cors']['defaults'] ?? [];
        $paths = $corsConfig['nelmio_cors']['paths'] ?? [];

        // Verifie les defaults globaux.
        $this->checkWildcardWithCredentials($report, $defaults, 'defaults');
        $this->checkWildcardOnNonPublicRoutes($report, $defaults, 'defaults', null);

        // Verifie chaque path specifique.
        foreach ($paths as $pathPattern => $pathConfig) {
            if (!is_array($pathConfig)) {
                continue;
            }

            $this->checkWildcardWithCredentials($report, $pathConfig, "paths.{$pathPattern}");
            $this->checkWildcardOnNonPublicRoutes($report, $pathConfig, "paths.{$pathPattern}", $pathPattern);
        }
    }

    public function getName(): string
    {
        return 'CORS Analyzer';
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasNelmioCors();
    }

    /**
     * Detecte allow_origin: ['*'] combiné avec allow_credentials: true.
     *
     * Cette combinaison est critique : elle permet à n'importe quel site web
     * d'envoyer des requetes authentifiees (cookies, tokens) vers l'application.
     * Les navigateurs modernes bloquent cette combinaison, mais les anciennes versions
     * et certains clients HTTP ne le font pas.
     *
     * @param array<mixed> $config
     */
    private function checkWildcardWithCredentials(AuditReport $report, array $config, string $section): void
    {
        $allowOrigin = $config['allow_origin'] ?? [];
        $allowCredentials = $config['allow_credentials'] ?? false;

        if (!$this->hasWildcardOrigin($allowOrigin)) {
            return;
        }

        if ($allowCredentials !== true) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: "CORS : allow_origin: ['*'] avec allow_credentials: true ({$section})",
            detail: "La section '{$section}' de nelmio_cors.yaml autorise toutes les origines (wildcard '*') "
                . "en combinaison avec allow_credentials: true. Cette configuration permet à n'importe quel "
                . "site web d'envoyer des requetes authentifiées (cookies, Authorization headers) "
                . "vers votre application. C'est la configuration CORS la plus dangereuse possible.",
            suggestion: "Remplacer allow_origin: ['*'] par une liste explicite de domaines autorisés. "
                . "Ex: allow_origin: ['https://mon-frontend.com'].",
            file: 'config/packages/nelmio_cors.yaml',
            businessImpact: "N'importe quel site tiers peut effectuer des requetes authentifiées "
                . "au nom de vos utilisateurs. Un attaquant peut voler des données, "
                . "modifier des ressources ou exécuter des actions sensibles.",
            fixCode: "nelmio_cors:\n    defaults:\n        allow_origin: ['https://votre-domaine.com']\n        allow_credentials: true",
            docUrl: 'https://github.com/nelmio/NelmioCorsBundle#configuration',
            estimatedFixMinutes: 10,
        ));
    }

    /**
     * Detecte allow_origin: ['*'] sur des routes qui ne sont pas purement publiques.
     *
     * Un wildcard est acceptable pour des ressources publiques (CDN, API publique),
     * mais dangereux pour des routes qui manipulent des donnees utilisateur.
     *
     * @param array<mixed> $config
     */
    private function checkWildcardOnNonPublicRoutes(
        AuditReport $report,
        array $config,
        string $section,
        ?string $pathPattern,
    ): void {
        $allowOrigin = $config['allow_origin'] ?? [];
        $allowCredentials = $config['allow_credentials'] ?? false;

        if (!$this->hasWildcardOrigin($allowOrigin)) {
            return;
        }

        // Si allow_credentials est true, on a deja signale le probleme
        // dans checkWildcardWithCredentials(). Pas besoin de doubler.
        if ($allowCredentials === true) {
            return;
        }

        // Pour les defaults (sans path specifique), signaler le wildcard global.
        // Pour les paths specifiques, on verifie si c'est une route potentiellement sensible.
        $isPublicPath = $pathPattern !== null && $this->isLikelyPublicPath($pathPattern);

        if ($isPublicPath) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: "CORS : allow_origin: ['*'] sur des routes non publiques ({$section})",
            detail: "La section '{$section}' de nelmio_cors.yaml autorise toutes les origines (wildcard '*'). "
                . "Meme sans credentials, cela permet à tout site web de lire les réponses "
                . "de votre API. Si certaines données ne sont pas publiques, "
                . "elles deviennent accessibles depuis n'importe quel domaine.",
            suggestion: "Restreindre allow_origin aux domaines de confiance. "
                . "Si l'API est publique, documenter explicitement ce choix.",
            file: 'config/packages/nelmio_cors.yaml',
            businessImpact: "Les données retournées par l'API sont lisibles depuis n'importe quel site web. "
                . "Si des endpoints retournent des données non publiques, elles sont exposées.",
            fixCode: "nelmio_cors:\n    defaults:\n        allow_origin: ['https://votre-domaine.com']",
            docUrl: 'https://github.com/nelmio/NelmioCorsBundle#configuration',
            estimatedFixMinutes: 10,
        ));
    }

    /**
     * Detecte l'absence de configuration CORS alors que des routes API existent.
     * Verifie la presence de fichiers dans src/Controller/ contenant des routes /api.
     */
    private function checkMissingCorsConfig(AuditReport $report): void
    {
        // Verifie si un fichier de routing API existe.
        $hasApiRoutes = $this->configReader->exists('config/routes/api_platform.yaml')
            || $this->configReader->exists('config/packages/api_platform.yaml');

        if (!$hasApiRoutes) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::SUGGESTION,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: 'Routes API détectées mais aucune configuration CORS trouvée',
            detail: "Le projet contient des routes API (api_platform détecté) "
                . "mais aucun fichier config/packages/nelmio_cors.yaml n'a été trouvé. "
                . "Sans configuration CORS, les navigateurs bloqueront les requetes cross-origin "
                . "vers votre API depuis un frontend sur un domaine différent.",
            suggestion: "Installer NelmioCorsBundle : composer require nelmio/cors-bundle. "
                . "Puis configurer les origines autorisées dans config/packages/nelmio_cors.yaml.",
            file: 'config/packages/nelmio_cors.yaml',
            businessImpact: "Les applications frontend hébergées sur un domaine différent "
                . "ne peuvent pas communiquer avec l'API. Les requetes AJAX cross-origin "
                . "sont bloquées par les navigateurs.",
            fixCode: "# config/packages/nelmio_cors.yaml\nnelmio_cors:\n    defaults:\n        allow_origin: ['https://votre-frontend.com']\n        allow_methods: ['GET', 'POST', 'PUT', 'DELETE']\n        allow_headers: ['Content-Type', 'Authorization']\n        max_age: 3600\n    paths:\n        '^/api/':\n            allow_origin: ['https://votre-frontend.com']",
            docUrl: 'https://github.com/nelmio/NelmioCorsBundle',
            estimatedFixMinutes: 15,
        ));
    }

    /**
     * Verifie si allow_origin contient un wildcard '*'.
     *
     * @param mixed $allowOrigin
     */
    private function hasWildcardOrigin(mixed $allowOrigin): bool
    {
        if (!is_array($allowOrigin)) {
            return $allowOrigin === '*';
        }

        return in_array('*', $allowOrigin, true);
    }

    /**
     * Determine si un pattern de path est probablement une route publique.
     * Les routes d'assets, de fichiers statiques et de health check sont considerees publiques.
     */
    private function isLikelyPublicPath(string $pathPattern): bool
    {
        $publicPatterns = [
            '/assets',
            '/build',
            '/bundles',
            '/media',
            '/_health',
            '/public',
        ];

        foreach ($publicPatterns as $publicPattern) {
            if (stripos($pathPattern, $publicPattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
