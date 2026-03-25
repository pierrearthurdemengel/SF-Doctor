<?php

// src/Analyzer/Configuration/HttpHeadersAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Configuration;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Context\ProjectContext;

/**
 * Verifie la presence des headers HTTP de securite dans la configuration Symfony.
 */
class HttpHeadersAnalyzer implements AnalyzerInterface
{
    public function __construct(private readonly ConfigReaderInterface $configReader)
    {
    }

    public function analyze(AuditReport $report): void
    {
        $config = $this->configReader->read('config/packages/framework.yaml');

        if ($config === null) {
            return;
        }

        $headers = $config['framework']['http_response']['headers'] ?? [];

        $this->checkXFrameOptions($headers, $report);
        $this->checkXContentTypeOptions($headers, $report);
        $this->checkContentSecurityPolicy($headers, $report);
    }

    public function getName(): string
    {
        return 'HTTP Headers Analyzer';
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
     * Verifie la presence du header X-Frame-Options.
     * Protege contre le clickjacking.
     *
     * @param array<string, mixed> $headers
     */
    private function checkXFrameOptions(array $headers, AuditReport $report): void
    {
        $key = $this->findHeader($headers, 'X-Frame-Options');

        if ($key === null) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'Header X-Frame-Options absent',
                detail: 'Sans ce header, le navigateur autorise l\'intégration de l\'application dans un iframe. Vecteur d\'attaque classique de clickjacking.',
                suggestion: 'Ajouter dans config/packages/framework.yaml : framework.http_response.headers.X-Frame-Options: SAMEORIGIN',
                file: 'config/packages/framework.yaml',
                businessImpact: 'Un attaquant peut superposer un iframe invisible sur votre page pour piéger les clics des utilisateurs.',
                fixCode: "framework:\n    http_response:\n        headers:\n            X-Frame-Options: SAMEORIGIN",
                docUrl: 'https://developer.mozilla.org/fr/docs/Web/HTTP/Headers/X-Frame-Options',
                estimatedFixMinutes: 5,
            ));
        }
    }

    /**
     * Verifie la presence du header X-Content-Type-Options.
     * Protege contre le MIME sniffing.
     *
     * @param array<string, mixed> $headers
     */
    private function checkXContentTypeOptions(array $headers, AuditReport $report): void
    {
        $key = $this->findHeader($headers, 'X-Content-Type-Options');

        if ($key === null) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'Header X-Content-Type-Options absent',
                detail: 'Sans ce header, le navigateur peut "deviner" le type d\'un fichier et l\'exécuter comme script même si le serveur déclare un autre Content-Type.',
                suggestion: 'Ajouter dans config/packages/framework.yaml : framework.http_response.headers.X-Content-Type-Options: nosniff',
                file: 'config/packages/framework.yaml',
                businessImpact: 'Un fichier uploadé malveillant peut être exécuté comme JavaScript si le navigateur reclassifie son type.',
                fixCode: "framework:\n    http_response:\n        headers:\n            X-Content-Type-Options: nosniff",
                docUrl: 'https://developer.mozilla.org/fr/docs/Web/HTTP/Headers/X-Content-Type-Options',
                estimatedFixMinutes: 5,
            ));
        }
    }

    /**
     * Verifie la presence d'une politique Content-Security-Policy.
     * Reduit la surface d'attaque XSS.
     *
     * @param array<string, mixed> $headers
     */
    private function checkContentSecurityPolicy(array $headers, AuditReport $report): void
    {
        $key = $this->findHeader($headers, 'Content-Security-Policy');

        if ($key === null) {
            $report->addIssue(new Issue(
                severity: Severity::SUGGESTION,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'Aucune politique Content-Security-Policy définie',
                detail: 'Une CSP réduit significativement la surface d\'attaque XSS en limitant les sources de scripts, styles et médias autorisées.',
                suggestion: 'Définir une CSP adaptée au projet. Commencer par une politique stricte et assouplir au besoin.',
                file: 'config/packages/framework.yaml',
                businessImpact: 'Sans CSP, toute injection XSS peut exécuter du JavaScript arbitraire dans le contexte de l\'application.',
                fixCode: "framework:\n    http_response:\n        headers:\n            Content-Security-Policy: \"default-src 'self'\"",
                docUrl: 'https://developer.mozilla.org/fr/docs/Web/HTTP/CSP',
                estimatedFixMinutes: 30,
            ));
        }
    }

    /**
     * Recherche un header par nom, insensible a la casse.
     * Retourne la cle trouvee dans le tableau ou null si absent.
     *
     * @param array<string, mixed> $headers
     */
    private function findHeader(array $headers, string $name): ?string
    {
        foreach (array_keys($headers) as $key) {
            if (strtolower((string) $key) === strtolower($name)) {
                return (string) $key;
            }
        }

        return null;
    }
}