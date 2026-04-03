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
 * Detecte l'absence de configuration de cache HTTP sur les ressources API Platform.
 *
 * API Platform supporte nativement les headers Cache-Control, ETag et Expires
 * via les attributs #[ApiResource(cacheHeaders:)] et #[GetCollection(cacheHeaders:)].
 * Sans cache HTTP, chaque requete GET execute une requete SQL complete,
 * meme si les donnees n'ont pas change.
 */
final class HttpCacheAnalyzer implements AnalyzerInterface
{
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

                $this->checkMissingCacheHeaders($report, $content, $relativePath, $file->getFilename());
                $this->checkPublicCacheOnPrivateResource($report, $content, $relativePath, $file->getFilename());
            }
        }
    }

    public function getName(): string
    {
        return 'HTTP Cache Analyzer';
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
     * Detecte les ressources en lecture seule sans aucune directive de cache HTTP.
     * Les GET sur des donnees stables (referentiels, catalogues) beneficient
     * enormement d'un cache HTTP meme court (60s) pour reduire la charge serveur.
     */
    private function checkMissingCacheHeaders(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        // Si la ressource configure deja des headers de cache, pas de probleme.
        if (str_contains($content, 'cacheHeaders')
            || str_contains($content, 'Cache-Control')
            || str_contains($content, 'stale_while_revalidate')
            || str_contains($content, 'max_age')) {
            return;
        }

        // Ne signale que les ressources avec des operations de lecture.
        $hasGetOperation = str_contains($content, '#[Get')
            || str_contains($content, '#[GetCollection');

        // Si pas d'operations explicites, les operations par defaut incluent GET.
        if (!$hasGetOperation && !preg_match('/operations\s*:\s*\[/', $content)) {
            $hasGetOperation = true;
        }

        if (!$hasGetOperation) {
            return;
        }

        $className = $this->extractClassName($content);

        $report->addIssue(new Issue(
            severity: Severity::SUGGESTION,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "Aucun cache HTTP configure sur la ressource {$filename}",
            detail: "La ressource '{$className}' ne configure aucune directive de cache HTTP "
                . "(cacheHeaders, Cache-Control, ETag). Chaque requete GET execute "
                . "systematiquement une requete SQL, meme si les donnees n'ont pas change.",
            suggestion: "Ajouter cacheHeaders sur #[ApiResource] ou sur les operations GET "
                . "individuelles pour reduire la charge serveur.",
            file: $relativePath,
            fixCode: "#[ApiResource(\n"
                . "    cacheHeaders: [\n"
                . "        'max_age' => 60,\n"
                . "        'shared_max_age' => 3600,\n"
                . "        'vary' => ['Authorization', 'Accept-Language'],\n"
                . "    ],\n"
                . ")]",
            docUrl: 'https://api-platform.com/docs/core/performance/#enabling-the-built-in-http-cache-invalidation-system',
            businessImpact: "Charge serveur et base de donnees inutile sur les requetes GET repetitives. "
                . "Sur un referentiel ou catalogue, le cache HTTP peut diviser la charge par 10.",
            estimatedFixMinutes: 10,
        ));
    }

    /**
     * Detecte les ressources avec cache public (shared_max_age ou public)
     * mais qui ont une directive security:.
     * Un cache public (CDN/reverse-proxy) stocke la reponse pour tous les utilisateurs,
     * y compris les donnees auxquelles l'utilisateur n'a pas acces.
     */
    private function checkPublicCacheOnPrivateResource(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        $hasPublicCache = str_contains($content, 'shared_max_age')
            || preg_match("/['\"]public['\"]\s*=>\s*true/", $content);

        if (!$hasPublicCache) {
            return;
        }

        $hasSecurity = str_contains($content, 'security:')
            || str_contains($content, "security'")
            || str_contains($content, 'security"');

        if (!$hasSecurity) {
            return;
        }

        $className = $this->extractClassName($content);

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::API_PLATFORM,
            analyzer: $this->getName(),
            message: "Cache public sur une ressource protegee dans {$filename}",
            detail: "La ressource '{$className}' combine un cache public (shared_max_age ou public: true) "
                . "avec une directive security:. Un reverse-proxy ou CDN va mettre en cache "
                . "la reponse du premier utilisateur et la servir a tous les suivants, "
                . "y compris ceux qui n'ont pas les droits d'acces.",
            suggestion: "Utiliser max_age (cache prive navigateur) au lieu de shared_max_age, "
                . "ou ajouter Vary: Authorization pour isoler les caches par utilisateur.",
            file: $relativePath,
            fixCode: "#[ApiResource(\n"
                . "    security: \"is_granted('ROLE_USER')\",\n"
                . "    cacheHeaders: [\n"
                . "        'max_age' => 60,        // cache prive (navigateur uniquement)\n"
                . "        'vary' => ['Authorization'],  // isole par token\n"
                . "    ],\n"
                . ")]",
            docUrl: 'https://api-platform.com/docs/core/performance/',
            businessImpact: "Fuite de donnees : un utilisateur non autorise recoit les donnees "
                . "d'un utilisateur autorise via le cache partage. Violation RGPD potentielle.",
            estimatedFixMinutes: 15,
        ));
    }

    private function extractClassName(string $content): string
    {
        if (preg_match('/\bclass\s+(\w+)/', $content, $matches)) {
            return $matches[1];
        }

        return 'Resource';
    }
}
