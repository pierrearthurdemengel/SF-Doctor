<?php

// src/Analyzer/Migration/PhpVersionAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Migration;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Verifie que la contrainte PHP dans composer.json est compatible
 * avec les exigences de Symfony 7 et Symfony 8.
 *
 * Symfony 7 requiert PHP >= 8.2. Symfony 8 requiert egalement PHP >= 8.2.
 * Un projet avec une contrainte PHP trop basse ne pourra pas migrer.
 */
final class PhpVersionAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $composerFile = $this->projectPath . '/composer.json';
        $content = file_get_contents($composerFile);

        if ($content === false) {
            return;
        }

        $composer = json_decode($content, true);

        if (!is_array($composer) || !isset($composer['require']['php'])) {
            return;
        }

        $phpConstraint = $composer['require']['php'];

        $this->checkPhpTooOld($report, $phpConstraint);
        $this->checkPhpBlocksSymfony8($report, $phpConstraint);
    }

    public function getName(): string
    {
        return 'PHP Version Analyzer';
    }

    public function getModule(): Module
    {
        return Module::MIGRATION;
    }

    public function supports(ProjectContext $context): bool
    {
        return file_exists($context->getProjectPath() . '/composer.json');
    }

    /**
     * Detecte une contrainte PHP inferieure a 8.2.
     *
     * Si la version maximale autorisee est inferieure a 8.2,
     * le projet ne peut pas tourner sur Symfony 7 ni Symfony 8.
     */
    private function checkPhpTooOld(AuditReport $report, string $constraint): void
    {
        // Extraire la version minimale de la contrainte.
        $minVersion = $this->extractMinVersion($constraint);

        if ($minVersion === null) {
            return;
        }

        // Si la version minimale est >= 8.2, pas de probleme.
        if (version_compare($minVersion, '8.2', '>=')) {
            return;
        }

        // Verifier si c'est strictement inferieur a 8.2 (ex: ^7.4, ^8.0, ^8.1 sans || ^8.2).
        // Si la contrainte ne permet PAS 8.2, c'est critique.
        if (!$this->constraintAllows82($constraint)) {
            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::MIGRATION,
                analyzer: $this->getName(),
                message: sprintf('Contrainte PHP trop basse : %s', $constraint),
                detail: sprintf(
                    'La contrainte PHP "%s" dans composer.json ne permet pas PHP 8.2. '
                    . 'Symfony 7 et Symfony 8 requierent PHP >= 8.2. '
                    . 'Le projet ne peut pas migrer vers ces versions sans mettre a jour '
                    . 'la contrainte PHP et le runtime du serveur.',
                    $constraint,
                ),
                suggestion: 'Mettre a jour la contrainte PHP dans composer.json vers ">=8.2" '
                    . 'et verifier que le serveur de production utilise PHP 8.2 ou superieur.',
                file: 'composer.json',
                fixCode: "\"require\": {\n    \"php\": \">=8.2\"\n}",
                docUrl: 'https://symfony.com/releases/7.0',
                businessImpact: 'Le projet est bloque sur une version de PHP et Symfony qui finira '
                    . 'par ne plus recevoir de correctifs de securite. La migration sera de plus '
                    . 'en plus couteuse avec le temps.',
                estimatedFixMinutes: 60,
            ));
        }
    }

    /**
     * Detecte les contraintes ^8.0 ou ^8.1 qui bloquent Symfony 8.
     *
     * Ces contraintes autorisent PHP 8.x mais pas necessairement >= 8.2.
     * Le caret (^) sur 8.0 autorise 8.0, 8.1, 8.2, etc. mais le message
     * d'avertissement est pertinent car la version minimale declaree est trop basse.
     */
    private function checkPhpBlocksSymfony8(AuditReport $report, string $constraint): void
    {
        // Detecter specifiquement ^8.0 ou ^8.1 comme contrainte principale.
        if (!preg_match('/\^8\.[01]\b/', $constraint)) {
            return;
        }

        // Si la contrainte inclut deja >= 8.2 ou ^8.2, pas de probleme.
        if (preg_match('/\^8\.[2-9]/', $constraint) || preg_match('/>=\s*8\.2/', $constraint)) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::WARNING,
            module: Module::MIGRATION,
            analyzer: $this->getName(),
            message: sprintf('Contrainte PHP "%s" - version minimale trop basse pour Symfony 8', $constraint),
            detail: sprintf(
                'La contrainte "%s" permet techniquement PHP 8.2+ grace au caret, '
                . 'mais la version minimale declaree est inferieure a 8.2. '
                . 'Cela signifie que composer install pourrait fonctionner sur PHP 8.0 ou 8.1 '
                . 'alors que Symfony 8 ne le supporte pas. Le CI et les environnements de dev '
                . 'pourraient utiliser une version trop ancienne sans le detecter.',
                $constraint,
            ),
            suggestion: 'Relever la contrainte PHP a ">=8.2" pour garantir que tous les environnements '
                . 'utilisent une version compatible avec Symfony 8.',
            file: 'composer.json',
            fixCode: "\"require\": {\n    \"php\": \">=8.2\"\n}",
            docUrl: 'https://symfony.com/releases/7.0',
            businessImpact: 'Les developpeurs pourraient travailler sur PHP 8.1 et decouvrir '
                . 'des incompatibilites uniquement lors du deploiement. Risque de regressions '
                . 'tardives et couteuses.',
            estimatedFixMinutes: 15,
        ));
    }

    /**
     * Extrait la version minimale d'une contrainte Composer.
     *
     * Gere les cas courants : ^8.2, >=8.2, ~8.2, 8.2.*.
     * Retourne null si la contrainte n'est pas analysable.
     */
    private function extractMinVersion(string $constraint): ?string
    {
        // Extraire le premier numero de version trouve.
        if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $constraint, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Verifie si une contrainte Composer autorise PHP 8.2.
     *
     * Analyse simplifiee couvrant les cas courants.
     */
    private function constraintAllows82(string $constraint): bool
    {
        // ^8.0 ou ^8.1 autorisent 8.2 (caret = meme version majeure).
        if (preg_match('/\^8\.\d+/', $constraint)) {
            return true;
        }

        // >=8.2 ou >=8.0 autorisent 8.2.
        if (preg_match('/>=\s*8\.[0-2]/', $constraint)) {
            return true;
        }

        // ~8.0 ou ~8.1 autorisent 8.2 (tilde = meme version majeure).
        if (preg_match('/~8\.\d+/', $constraint)) {
            return true;
        }

        // Contrainte avec || contenant 8.2+.
        if (str_contains($constraint, '||') || str_contains($constraint, '|')) {
            $parts = preg_split('/\s*\|\|?\s*/', $constraint);
            if ($parts !== false) {
                foreach ($parts as $part) {
                    if ($this->constraintAllows82(trim($part))) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
