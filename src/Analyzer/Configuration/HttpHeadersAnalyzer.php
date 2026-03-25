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
use Symfony\Component\Finder\Finder;

/**
 * Verifie la presence des headers HTTP de securite dans la configuration Symfony.
 *
 * Cherche dans framework.yaml ET dans les EventSubscribers qui posent
 * les headers via KernelEvents::RESPONSE (approche recommandee par Symfony).
 */
class HttpHeadersAnalyzer implements AnalyzerInterface
{
    private string $projectPath = '';

    public function __construct(private readonly ConfigReaderInterface $configReader)
    {
    }

    public function analyze(AuditReport $report): void
    {
        $this->projectPath = $report->getProjectPath();

        $config = $this->configReader->read('config/packages/framework.yaml');

        $headers = $config['framework']['http_response']['headers'] ?? [];

        $this->checkHeader($headers, $report, 'X-Frame-Options', Severity::WARNING,
            'Sans ce header, le navigateur autorise l\'intégration de l\'application dans un iframe. Vecteur d\'attaque classique de clickjacking.',
            'Ajouter dans config/packages/framework.yaml : framework.http_response.headers.X-Frame-Options: SAMEORIGIN',
            'Un attaquant peut superposer un iframe invisible sur votre page pour piéger les clics des utilisateurs.',
            "framework:\n    http_response:\n        headers:\n            X-Frame-Options: SAMEORIGIN",
            'https://developer.mozilla.org/fr/docs/Web/HTTP/Headers/X-Frame-Options',
            5,
        );

        $this->checkHeader($headers, $report, 'X-Content-Type-Options', Severity::WARNING,
            'Sans ce header, le navigateur peut "deviner" le type d\'un fichier et l\'exécuter comme script même si le serveur déclare un autre Content-Type.',
            'Ajouter dans config/packages/framework.yaml : framework.http_response.headers.X-Content-Type-Options: nosniff',
            'Un fichier uploadé malveillant peut être exécuté comme JavaScript si le navigateur reclassifie son type.',
            "framework:\n    http_response:\n        headers:\n            X-Content-Type-Options: nosniff",
            'https://developer.mozilla.org/fr/docs/Web/HTTP/Headers/X-Content-Type-Options',
            5,
        );

        $this->checkHeader($headers, $report, 'Content-Security-Policy', Severity::SUGGESTION,
            'Une CSP réduit significativement la surface d\'attaque XSS en limitant les sources de scripts, styles et médias autorisées.',
            'Définir une CSP adaptée au projet. Commencer par une politique stricte et assouplir au besoin.',
            'Sans CSP, toute injection XSS peut exécuter du JavaScript arbitraire dans le contexte de l\'application.',
            "framework:\n    http_response:\n        headers:\n            Content-Security-Policy: \"default-src 'self'\"",
            'https://developer.mozilla.org/fr/docs/Web/HTTP/CSP',
            30,
        );
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
     * Verifie un header specifique dans la config YAML et les EventSubscribers.
     *
     * @param array<string, mixed> $headers
     */
    private function checkHeader(
        array $headers,
        AuditReport $report,
        string $headerName,
        Severity $severity,
        string $detail,
        string $suggestion,
        string $businessImpact,
        string $fixCode,
        string $docUrl,
        int $estimatedFixMinutes,
    ): void {
        // 1. Check dans la config framework.yaml
        if ($this->findHeader($headers, $headerName) !== null) {
            return;
        }

        // 2. Check dans les EventSubscribers (approche recommandee par Symfony)
        if ($this->headerSetInEventSubscriber($headerName)) {
            return;
        }

        // 3. Check dans NelmioSecurityBundle config
        if ($this->headerSetInNelmioSecurity($headerName)) {
            return;
        }

        $message = $headerName === 'Content-Security-Policy'
            ? 'Aucune politique Content-Security-Policy définie'
            : sprintf('Header %s absent', $headerName);

        $report->addIssue(new Issue(
            severity: $severity,
            module: Module::SECURITY,
            analyzer: $this->getName(),
            message: $message,
            detail: $detail,
            suggestion: $suggestion,
            file: 'config/packages/framework.yaml',
            businessImpact: $businessImpact,
            fixCode: $fixCode,
            docUrl: $docUrl,
            estimatedFixMinutes: $estimatedFixMinutes,
        ));
    }

    /**
     * Recherche un header par nom, insensible a la casse.
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

    /**
     * Verifie si un EventSubscriber pose le header via KernelEvents::RESPONSE.
     */
    private function headerSetInEventSubscriber(string $headerName): bool
    {
        $srcDir = $this->projectPath . '/src';
        if (!is_dir($srcDir)) {
            return false;
        }

        $finder = new Finder();
        $finder->files()->name('*.php')->in($srcDir);

        foreach ($finder as $file) {
            $content = $file->getContents();
            if ((str_contains($content, 'KernelEvents::RESPONSE') || str_contains($content, 'ResponseEvent'))
                && str_contains($content, $headerName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifie si NelmioSecurityBundle configure le header.
     */
    private function headerSetInNelmioSecurity(string $headerName): bool
    {
        $config = $this->configReader->read('config/packages/nelmio_security.yaml');
        if ($config === null) {
            return false;
        }

        $nelmio = $config['nelmio_security'] ?? [];

        return match (strtolower($headerName)) {
            'x-frame-options' => isset($nelmio['clickjacking']),
            'x-content-type-options' => isset($nelmio['content_type']['nosniff']) && $nelmio['content_type']['nosniff'] === true,
            'content-security-policy' => isset($nelmio['csp']),
            default => false,
        };
    }
}