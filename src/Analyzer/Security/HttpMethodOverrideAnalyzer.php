<?php

// src/Analyzer/Security/HttpMethodOverrideAnalyzer.php

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
 * Detecte l'activation de http_method_override dans la configuration Symfony.
 *
 * Quand cette option est active, un champ _method dans le body ou un header
 * X-HTTP-Method-Override permet de transformer un POST en PUT/DELETE/PATCH.
 * Cela ouvre un vecteur de "verb tunneling" si le pare-feu ou le reverse proxy
 * filtre par methode HTTP.
 */
final class HttpMethodOverrideAnalyzer implements AnalyzerInterface
{
    /** Packages qui necessitent http_method_override pour les forms HTML (PUT/DELETE). */
    private const FRAMEWORKS_REQUIRING_OVERRIDE = [
        'sylius/sylius',
        'easycorp/easyadmin-bundle',
        'sonata-project/admin-bundle',
    ];

    public function __construct(
        private readonly ConfigReaderInterface $configReader,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $config = $this->configReader->read('config/packages/framework.yaml');

        if ($config === null) {
            return;
        }

        // Si le projet utilise un framework qui necessite http_method_override, ne pas flagger.
        if ($this->projectRequiresMethodOverride($report->getProjectPath())) {
            return;
        }

        // Si le projet utilise symfony/form, _method est attendu pour les forms HTML.
        $composerJson = $this->readComposerJson($report->getProjectPath());
        $deps = array_merge($composerJson['require'] ?? [], $composerJson['require-dev'] ?? []);
        if (isset($deps['symfony/form'])) {
            return;
        }

        $this->checkHttpMethodOverride($report, $config);
    }

    public function getName(): string
    {
        return 'HTTP Method Override Analyzer';
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function supports(ProjectContext $context): bool
    {
        return true;
    }

    /**
     * Verifie si framework.http_method_override est active.
     * Un POST avec _method=DELETE contourne les filtres par methode HTTP
     * au niveau du reverse proxy ou du pare-feu.
     *
     * @param array<mixed> $config
     */
    private function checkHttpMethodOverride(AuditReport $report, array $config): void
    {
        $httpMethodOverride = $config['framework']['http_method_override'] ?? null;

        if ($httpMethodOverride !== true) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: 'http_method_override est active dans framework.yaml',
            detail: "L'option framework.http_method_override permet de transformer un POST "
                . "en PUT, DELETE ou PATCH via un champ _method ou le header X-HTTP-Method-Override. "
                . "Si un pare-feu ou reverse proxy filtre par methode HTTP, ce mecanisme "
                . "permet de contourner ces restrictions (verb tunneling).",
            suggestion: "Desactiver http_method_override si le projet n'utilise pas de formulaires "
                . "HTML avec _method (API pure). Si des formulaires HTML necessitent PUT/DELETE, "
                . "s'assurer que le pare-feu inspecte aussi le body de la requete.",
            file: 'config/packages/framework.yaml',
            fixCode: "# Dans config/packages/framework.yaml :\nframework:\n    http_method_override: false",
            docUrl: 'https://symfony.com/doc/current/reference/configuration/framework.html#http-method-override',
            businessImpact: 'Un attaquant peut envoyer un POST avec _method=DELETE pour contourner '
                . 'les regles de pare-feu basees sur la methode HTTP. Les endpoints proteges '
                . 'par des restrictions sur DELETE ou PUT deviennent accessibles via POST.',
            estimatedFixMinutes: 10,
        ));
    }

    private function projectRequiresMethodOverride(string $projectPath): bool
    {
        $composerJson = $this->readComposerJson($projectPath);
        $deps = array_merge($composerJson['require'] ?? [], $composerJson['require-dev'] ?? []);

        foreach (self::FRAMEWORKS_REQUIRING_OVERRIDE as $pkg) {
            if (isset($deps[$pkg])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposerJson(string $projectPath): array
    {
        $path = $projectPath . '/composer.json';
        if (!file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }
}
