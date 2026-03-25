<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Twig;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les usages de l'attribut srcdoc sur les iframes.
 *
 * Source : blog "Hardening Symfony" jan. 2026 - srcdoc retire des attributs
 * autorises par defaut dans HtmlSanitizer. L'attribut srcdoc permet d'injecter
 * du HTML arbitraire dans un iframe sans restriction CSP.
 */
final class TwigSrcdocAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
        private readonly ConfigReaderInterface $configReader,
    ) {
    }

    public function analyze(AuditReport $report): void
    {
        $this->checkTemplates($report);
        $this->checkHtmlSanitizerConfig($report);
    }

    public function getName(): string
    {
        return 'Twig Srcdoc Analyzer';
    }

    public function getModule(): Module
    {
        return Module::TWIG;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasTwig();
    }

    /**
     * Parcourt templates/ pour detecter les iframes avec srcdoc sans sandbox.
     */
    private function checkTemplates(AuditReport $report): void
    {
        $templateDir = $this->projectPath . '/templates';

        if (!is_dir($templateDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templateDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                continue;
            }

            $relativePath = str_replace(
                $this->projectPath . '/',
                '',
                str_replace('\\', '/', $file->getRealPath()),
            );

            $lines = explode("\n", $content);

            foreach ($lines as $lineNumber => $line) {
                if (!preg_match('/\bsrcdoc\s*=/i', $line)) {
                    continue;
                }

                // Check if the same iframe tag has sandbox attribute
                $hasSandbox = (bool) preg_match('/\bsandbox\b/i', $line);

                // Also check surrounding lines for multiline iframe
                if (!$hasSandbox) {
                    $start = max(0, $lineNumber - 2);
                    $end = min(count($lines) - 1, $lineNumber + 2);
                    $context = implode("\n", array_slice($lines, $start, $end - $start + 1));
                    $hasSandbox = (bool) preg_match('/\bsandbox\b/i', $context);
                }

                if ($hasSandbox) {
                    continue;
                }

                $report->addIssue(new Issue(
                    severity: Severity::CRITICAL,
                    module: Module::TWIG,
                    analyzer: $this->getName(),
                    message: sprintf('iframe avec srcdoc sans sandbox dans %s (ligne %d)', $file->getFilename(), $lineNumber + 1),
                    detail: "L'attribut srcdoc permet d'injecter du HTML arbitraire dans un iframe "
                        . "sans restriction CSP. Sans l'attribut sandbox, l'iframe a acces "
                        . "au DOM parent, aux cookies et peut executer du JavaScript.",
                    suggestion: "Ajouter l'attribut sandbox sur l'iframe, ou remplacer srcdoc par src "
                        . "pointant vers une route dediee avec les headers de securite appropries.",
                    file: $relativePath,
                    line: $lineNumber + 1,
                    fixCode: '<iframe srcdoc="..." sandbox="allow-scripts"></iframe>',
                    docUrl: 'https://developer.mozilla.org/en-US/docs/Web/HTML/Element/iframe#attr-sandbox',
                    businessImpact: "Un attaquant peut exploiter un srcdoc non sandboxe pour executer "
                        . "du JavaScript dans le contexte de la page parente, voler des sessions "
                        . "ou exfiltrer des donnees.",
                    estimatedFixMinutes: 10,
                ));
            }
        }
    }

    /**
     * Verifie si HtmlSanitizer autorise srcdoc dans sa configuration.
     */
    private function checkHtmlSanitizerConfig(AuditReport $report): void
    {
        $config = $this->configReader->read('config/packages/html_sanitizer.yaml');

        if ($config === null) {
            return;
        }

        $content = json_encode($config);

        if ($content === false || !str_contains(strtolower($content), 'srcdoc')) {
            return;
        }

        // srcdoc found in HtmlSanitizer config
        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::TWIG,
            analyzer: $this->getName(),
            message: 'HtmlSanitizer configure avec srcdoc autorise',
            detail: "La configuration HtmlSanitizer autorise l'attribut srcdoc. "
                . "Depuis Symfony 7.4, srcdoc a ete retire des attributs autorises par defaut "
                . "en raison du risque d'injection de contenu arbitraire dans un iframe.",
            suggestion: "Retirer srcdoc de la liste des attributs autorises dans HtmlSanitizer "
                . "ou s'assurer que l'attribut sandbox est systematiquement applique.",
            file: 'config/packages/html_sanitizer.yaml',
            fixCode: "# Retirer srcdoc de la liste allow_attributes\nframework:\n    html_sanitizer:\n        sanitizers:\n            default:\n                allow_attributes:\n                    # Ne pas inclure srcdoc",
            docUrl: 'https://symfony.com/doc/current/html_sanitizer.html',
            businessImpact: "Le sanitizer laisse passer du contenu potentiellement dangereux "
                . "dans les iframes, exposant les utilisateurs a des attaques XSS.",
            estimatedFixMinutes: 10,
        ));
    }
}
