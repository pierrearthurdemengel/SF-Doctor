<?php

// src/Analyzer/Twig/TwigRawFilterAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Twig;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les usages du filtre |raw dans les templates Twig.
 *
 * Le filtre |raw desactive l'echappement automatique de Twig,
 * ce qui expose le projet a des attaques XSS si la variable
 * contient des donnees utilisateur non sanitisees.
 */
final class TwigRawFilterAnalyzer implements AnalyzerInterface
{
    // Patterns indiquant que la variable provient d'un contexte utilisateur
    // (formulaire, requete, parametre).
    private const USER_INPUT_PATTERNS = [
        'form.',
        'app.request',
        'app.user',
        'input',
        'comment',
        'message',
        'body',
        'content',
        'description',
        'text',
    ];

    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $templateDir = $this->projectPath . '/templates';

        if (!is_dir($templateDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templateDir, \FilesystemIterator::SKIP_DOTS),
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

            $this->checkRawFilterUsage($report, $content, $relativePath, $file->getFilename());
        }
    }

    public function getName(): string
    {
        return 'Twig Raw Filter Analyzer';
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
     * Detecte les usages de |raw dans un template Twig.
     *
     * Distingue deux niveaux de risque :
     * - WARNING : usage general de |raw (risque XSS potentiel)
     * - CRITICAL : |raw applique a une variable provenant d'un contexte utilisateur
     */
    private function checkRawFilterUsage(
        AuditReport $report,
        string $content,
        string $relativePath,
        string $filename,
    ): void {
        $lines = explode("\n", $content);
        $rawUsageLines = [];
        $criticalLines = [];

        foreach ($lines as $lineNumber => $line) {
            // Detecter les usages de |raw (avec ou sans espaces).
            if (!preg_match('/\|\s*raw\b/', $line)) {
                continue;
            }

            $rawUsageLines[] = $lineNumber + 1;

            // Verifier si la variable provient d'un contexte utilisateur.
            foreach (self::USER_INPUT_PATTERNS as $pattern) {
                if (str_contains(strtolower($line), $pattern)) {
                    $criticalLines[] = $lineNumber + 1;
                    break;
                }
            }
        }

        // Signaler les usages critiques (variables utilisateur avec |raw).
        if (count($criticalLines) > 0) {
            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::TWIG,
                analyzer: $this->getName(),
                message: sprintf(
                    'Filtre |raw sur donnee utilisateur dans %s (ligne%s %s)',
                    $filename,
                    count($criticalLines) > 1 ? 's' : '',
                    implode(', ', $criticalLines),
                ),
                detail: sprintf(
                    'Le template "%s" utilise le filtre |raw sur une variable provenant '
                    . 'd\'un contexte utilisateur (formulaire, requete, saisie). '
                    . 'Cela desactive l\'echappement HTML et permet l\'injection de code JavaScript '
                    . 'malveillant (attaque XSS).',
                    $filename,
                ),
                suggestion: 'Supprimer le filtre |raw et laisser Twig echapper automatiquement la variable. '
                    . 'Si du HTML est necessaire, utiliser le filtre |sanitize ou un Twig Extension '
                    . 'qui applique un nettoyage strict (HtmlSanitizer).',
                file: $relativePath,
                fixCode: "{# Avant (dangereux) : #}\n{{ user.comment|raw }}\n\n"
                    . "{# Apres (securise avec HtmlSanitizer) : #}\n{{ user.comment|sanitize_html('default') }}",
                docUrl: 'https://symfony.com/doc/current/html_sanitizer.html',
                businessImpact: 'Une faille XSS permet a un attaquant d\'executer du JavaScript dans le navigateur '
                    . 'de n\'importe quel visiteur. Vol de session, redirection vers un site malveillant, '
                    . 'defacement du site. Impact RGPD si des donnees personnelles sont exfiltrees.',
                estimatedFixMinutes: 20,
            ));

            // Retirer les lignes critiques de la liste generale pour eviter les doublons.
            $rawUsageLines = array_diff($rawUsageLines, $criticalLines);
        }

        // Signaler les usages generaux de |raw (hors contexte utilisateur).
        if (count($rawUsageLines) > 0) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::TWIG,
                analyzer: $this->getName(),
                message: sprintf(
                    'Usage du filtre |raw dans %s (ligne%s %s)',
                    $filename,
                    count($rawUsageLines) > 1 ? 's' : '',
                    implode(', ', $rawUsageLines),
                ),
                detail: sprintf(
                    'Le template "%s" utilise le filtre |raw qui desactive l\'echappement automatique '
                    . 'de Twig. Meme si la variable ne semble pas provenir directement d\'une saisie '
                    . 'utilisateur, elle pourrait le devenir suite a une evolution du code.',
                    $filename,
                ),
                suggestion: 'Verifier que la variable rendue avec |raw ne contient jamais de donnee utilisateur. '
                    . 'Privilegier l\'echappement automatique de Twig ou utiliser HtmlSanitizer.',
                file: $relativePath,
                fixCode: "{# Avant : #}\n{{ variable|raw }}\n\n"
                    . "{# Apres (si du HTML est necessaire) : #}\n{{ variable|sanitize_html('default') }}",
                docUrl: 'https://twig.symfony.com/doc/3.x/filters/raw.html',
                businessImpact: 'Risque potentiel de faille XSS si la variable est alimentee par des donnees '
                    . 'utilisateur dans le futur. Revue de securite necessaire a chaque evolution.',
                estimatedFixMinutes: 10,
            ));
        }
    }
}
