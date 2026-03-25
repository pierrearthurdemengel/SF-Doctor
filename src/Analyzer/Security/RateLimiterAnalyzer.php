<?php

// src/Analyzer/Security/RateLimiterAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Security;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use Symfony\Component\Finder\Finder;

/**
 * Verifie la presence d'un rate limiter sur les routes sensibles (login, API).
 *
 * Detecte les routes de login et d'API dans les controleurs et verifie
 * qu'un rate limiter est configure dans framework.yaml pour limiter
 * les tentatives de brute force.
 */
final class RateLimiterAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
        private readonly ConfigReaderInterface $configReader,
    ) {
    }

    public function analyze(AuditReport $report): void
    {
        $hasRateLimiter = $this->hasRateLimiterConfig();
        $loginRoutes = $this->findRoutesByPattern(['login']);
        $apiRoutes = $this->findRoutesByPattern(['api']);

        $this->checkLoginRoutes($report, $loginRoutes, $hasRateLimiter);
        $this->checkApiRoutes($report, $apiRoutes, $hasRateLimiter);
    }

    public function getName(): string
    {
        return 'Rate Limiter Analyzer';
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function supports(ProjectContext $context): bool
    {
        // Toujours applicable : le rate limiting est pertinent pour tout projet.
        return true;
    }

    /**
     * Verifie si une configuration rate_limiter existe dans framework.yaml.
     */
    private function hasRateLimiterConfig(): bool
    {
        $config = $this->configReader->read('config/packages/framework.yaml');

        if ($config === null) {
            return false;
        }

        $rateLimiter = $config['framework']['rate_limiter'] ?? null;

        return $rateLimiter !== null && is_array($rateLimiter) && count($rateLimiter) > 0;
    }

    /**
     * Recherche les fichiers controleurs contenant des routes correspondant aux patterns donnes.
     * Retourne une liste de noms de fichiers.
     *
     * @param list<string> $patterns
     * @return list<array{file: string, route: string}>
     */
    private function findRoutesByPattern(array $patterns): array
    {
        $controllerDir = $this->projectPath . '/src/Controller';

        if (!is_dir($controllerDir)) {
            return [];
        }

        $finder = new Finder();
        $finder->files()->name('*.php')->in($controllerDir);

        if (!$finder->hasResults()) {
            return [];
        }

        $matches = [];

        foreach ($finder as $file) {
            $content = $file->getContents();

            // Recherche les attributs #[Route('...')] contenant un des patterns.
            if (!preg_match_all('/#\[Route\([\'"]([^\'"]+)[\'"]/i', $content, $routeMatches)) {
                continue;
            }

            foreach ($routeMatches[1] as $route) {
                foreach ($patterns as $pattern) {
                    if (stripos($route, $pattern) !== false) {
                        $matches[] = [
                            'file' => 'src/Controller/' . $file->getRelativePathname(),
                            'route' => $route,
                        ];
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Verifie que les routes de login sont protegees par un rate limiter.
     * Sans rate limiting, un attaquant peut tester des millions de combinaisons
     * identifiant/mot de passe par brute force.
     *
     * @param list<array{file: string, route: string}> $loginRoutes
     */
    private function checkLoginRoutes(AuditReport $report, array $loginRoutes, bool $hasRateLimiter): void
    {
        if (empty($loginRoutes)) {
            return;
        }

        if ($hasRateLimiter) {
            return;
        }

        // Au moins une route de login detectee mais pas de rate limiter configure.
        $routeList = implode(', ', array_map(fn (array $r) => $r['route'], $loginRoutes));
        $firstFile = $loginRoutes[0]['file'];

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: 'Route de login détectée sans rate limiter configuré',
            detail: "Route(s) de login trouvée(s) : {$routeList}. "
                . "Aucune configuration framework.rate_limiter n'a été détectée dans framework.yaml. "
                . "Sans rate limiting, un attaquant peut effectuer des tentatives de brute force "
                . "sans limitation sur le formulaire de connexion.",
            suggestion: "Configurer un rate limiter dans config/packages/framework.yaml "
                . "et l'appliquer au firewall de login via login_throttling dans security.yaml.",
            file: $firstFile,
            businessImpact: "Les comptes utilisateurs sont vulnérables aux attaques par force brute. "
                . "Un attaquant peut tester des milliers de mots de passe par minute "
                . "sans etre bloqué.",
            fixCode: "# config/packages/framework.yaml\nframework:\n    rate_limiter:\n        login:\n            policy: sliding_window\n            limit: 5\n            interval: '1 minute'",
            docUrl: 'https://symfony.com/doc/current/rate_limiter.html',
            estimatedFixMinutes: 15,
        ));
    }

    /**
     * Verifie que les routes API sont protegees par un rate limiter.
     * Sans protection, les endpoints API sont vulnerables au scraping et aux abus.
     *
     * @param list<array{file: string, route: string}> $apiRoutes
     */
    private function checkApiRoutes(AuditReport $report, array $apiRoutes, bool $hasRateLimiter): void
    {
        if (empty($apiRoutes)) {
            return;
        }

        if ($hasRateLimiter) {
            return;
        }

        $routeList = implode(', ', array_map(fn (array $r) => $r['route'], $apiRoutes));
        $firstFile = $apiRoutes[0]['file'];

        $report->addIssue(new Issue(
            severity: Severity::SUGGESTION,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: 'Routes API détectées sans rate limiter configuré',
            detail: "Route(s) API trouvée(s) : {$routeList}. "
                . "Aucune configuration framework.rate_limiter n'a été détectée dans framework.yaml. "
                . "Sans rate limiting, les endpoints API sont exposés au scraping, "
                . "aux abus et aux attaques par déni de service.",
            suggestion: "Configurer un rate limiter dans config/packages/framework.yaml "
                . "et appliquer un #[RateLimit] ou un EventSubscriber sur les routes API.",
            file: $firstFile,
            businessImpact: "Les endpoints API peuvent etre sollicités sans limite, "
                . "ce qui expose l'application au scraping de données, à la surcharge "
                . "serveur et aux abus automatisés.",
            fixCode: "# config/packages/framework.yaml\nframework:\n    rate_limiter:\n        api:\n            policy: sliding_window\n            limit: 60\n            interval: '1 minute'",
            docUrl: 'https://symfony.com/doc/current/rate_limiter.html',
            estimatedFixMinutes: 20,
        ));
    }
}
