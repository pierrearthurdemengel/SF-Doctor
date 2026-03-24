<?php

// src/Analyzer/Security/SecretsAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Security;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Context\ProjectContext;


/**
 * Verifie que APP_SECRET est configuré et suffisamment robuste.
 */
class SecretsAnalyzer implements AnalyzerInterface
{
    // Valeurs par défaut connues livrées dans les templates Symfony.
    private const DEFAULT_SECRETS = [
        'ThisTokenIsNotSoSecretChangeIt',
        'your_app_secret',
        'changeme',
        'change_me',
        'secret',
    ];

    // Longueur minimale recommandée pour APP_SECRET.
    private const MIN_SECRET_LENGTH = 32;

    public function __construct(private readonly string $projectPath)
    {
    }

    public function analyze(AuditReport $report): void
    {
        $envContent = $this->readEnvFile();

        if ($envContent === null) {
            return;
        }

        $appSecret = $this->extractValue($envContent, 'APP_SECRET');

        if ($appSecret === null) {
            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'APP_SECRET est absent du fichier .env',
                detail: 'Symfony ne peut pas signer les cookies de session et les tokens CSRF sans APP_SECRET.',
                suggestion: 'Ajouter APP_SECRET=$(php -r "echo bin2hex(random_bytes(32));") dans .env.prod.',
                file: $this->resolveEnvFile(),
                businessImpact: 'Sans APP_SECRET, Symfony utilise une valeur de fallback non documentée. Les sessions et les tokens CSRF sont imprévisibles.',
                fixCode: 'APP_SECRET=' . bin2hex(random_bytes(16)),
                docUrl: 'https://symfony.com/doc/current/reference/configuration/framework.html#secret',
                estimatedFixMinutes: 5,
            ));
            return;
        }

        // Vérifie si la valeur est une valeur par défaut connue.
        if (in_array($appSecret, self::DEFAULT_SECRETS, true)) {
            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'APP_SECRET utilise la valeur par défaut "' . $appSecret . '"',
                detail: 'Cette valeur est publique et référencée dans tous les templates Symfony. Elle permet de forger des tokens CSRF et des sessions valides.',
                suggestion: 'Générer un secret unique : php -r "echo bin2hex(random_bytes(32));"',
                file: $this->resolveEnvFile(),
                businessImpact: 'Un attaquant connaissant cette valeur peut forger des cookies de session et contourner la protection CSRF.',
                fixCode: 'APP_SECRET=' . bin2hex(random_bytes(16)),
                docUrl: 'https://symfony.com/doc/current/reference/configuration/framework.html#secret',
                estimatedFixMinutes: 5,
            ));
            return;
        }

        // Vérifie la longueur minimale.
        if (strlen($appSecret) < self::MIN_SECRET_LENGTH) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'APP_SECRET est trop court (' . strlen($appSecret) . ' caractères, minimum recommandé : ' . self::MIN_SECRET_LENGTH . ')',
                detail: 'Un secret court est vulnérable aux attaques par force brute.',
                suggestion: 'Regénérer avec : php -r "echo bin2hex(random_bytes(32));"',
                file: $this->resolveEnvFile(),
                businessImpact: 'Un secret court peut être deviné par force brute, compromettant la signature des tokens.',
                fixCode: 'APP_SECRET=' . bin2hex(random_bytes(16)),
                docUrl: 'https://symfony.com/doc/current/reference/configuration/framework.html#secret',
                estimatedFixMinutes: 5,
            ));
        }
    }

    public function getName(): string
    {
        return 'Secrets Analyzer';
    }

    public function getModule(): Module
    {
        return Module::SECURITY;
    }

    public function supports(ProjectContext $context): bool
    {
        return file_exists($context->getProjectPath() . '/.env.prod')
            || file_exists($context->getProjectPath() . '/.env');
    }

    /**
     * Lit .env.prod en priorité, fallback sur .env.
     * Retourne null si aucun fichier n'existe.
     */
    private function readEnvFile(): ?string
    {
        $prodFile = $this->projectPath . '/.env.prod';
        if (file_exists($prodFile)) {
            return file_get_contents($prodFile) ?: null;
        }

        $baseFile = $this->projectPath . '/.env';
        if (file_exists($baseFile)) {
            return file_get_contents($baseFile) ?: null;
        }

        return null;
    }

    /**
     * Retourne le chemin relatif du fichier .env utilisé.
     */
    private function resolveEnvFile(): string
    {
        if (file_exists($this->projectPath . '/.env.prod')) {
            return '.env.prod';
        }

        return '.env';
    }

    /**
     * Extrait la valeur d'une variable dans le contenu d'un fichier .env.
     * Gère les guillemets simples, doubles et les commentaires.
     * Retourne null si la variable est absente.
     */
    private function extractValue(string $content, string $key): ?string
    {
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);

            // Ignore les lignes vides et les commentaires.
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_starts_with($line, $key . '=')) {
                continue;
            }

            $value = substr($line, strlen($key) + 1);

            // Supprime les guillemets encadrants.
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            return $value;
        }

        return null;
    }
}