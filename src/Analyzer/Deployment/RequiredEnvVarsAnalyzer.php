<?php

// src/Analyzer/Deployment/RequiredEnvVarsAnalyzer.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Deployment;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Verifie que les variables d'environnement requises sont definies
 * et ne contiennent pas de valeurs placeholder.
 *
 * Lit .env pour lister les variables attendues, puis verifie dans
 * .env.prod que chacune a une valeur reelle (pas "changeme", "todo", etc.).
 */
final class RequiredEnvVarsAnalyzer implements AnalyzerInterface
{
    // Valeurs placeholder frequentes qui indiquent une configuration non terminee.
    private const PLACEHOLDER_VALUES = [
        'changeme',
        'change_me',
        'change-me',
        'todo',
        'xxx',
        'your_value_here',
        'replace_me',
        'fixme',
        'placeholder',
    ];

    // Variables systeme qui n'ont pas besoin d'etre redefinies dans .env.prod.
    private const SYSTEM_VARS = [
        'APP_ENV',
        'APP_DEBUG',
        'APP_SECRET',
    ];

    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function analyze(AuditReport $report): void
    {
        $envFile = $this->projectPath . '/.env';
        $envProdFile = $this->projectPath . '/.env.prod';

        $envContent = file_get_contents($envFile);
        if ($envContent === false) {
            return;
        }

        $baseVars = $this->parseEnvFile($envContent);

        // Charger les variables de production si le fichier existe.
        $prodVars = [];
        if (file_exists($envProdFile)) {
            $prodContent = file_get_contents($envProdFile);
            if ($prodContent !== false) {
                $prodVars = $this->parseEnvFile($prodContent);
            }
        }

        $isPaas = $this->isPaasHosting();

        $this->checkMissingProdVars($report, $baseVars, $prodVars, $isPaas);
        $this->checkPlaceholderValues($report, $baseVars, $prodVars, $isPaas);
    }

    public function getName(): string
    {
        return 'Required Env Vars Analyzer';
    }

    public function getModule(): Module
    {
        return Module::DEPLOYMENT;
    }

    public function supports(ProjectContext $context): bool
    {
        return file_exists($context->getProjectPath() . '/.env');
    }

    /**
     * Detecte les variables definies dans .env mais absentes de .env.prod.
     *
     * Si une variable est declaree dans .env (template de developpement)
     * mais n'a pas de surcharge dans .env.prod, elle gardera sa valeur
     * de developpement en production.
     *
     * @param array<string, string> $baseVars
     * @param array<string, string> $prodVars
     */
    /**
     * Detecte si le projet est deploye sur un PaaS (Scalingo, Heroku, etc.).
     * Ces plateformes injectent les variables d'environnement via leur dashboard,
     * ce qui rend normal l'absence de ces variables dans .env.prod.
     */
    private function isPaasHosting(): bool
    {
        return file_exists($this->projectPath . '/Procfile')
            || file_exists($this->projectPath . '/scalingo.json')
            || file_exists($this->projectPath . '/app.json');
    }

    /**
     * @param array<string, string> $baseVars
     * @param array<string, string> $prodVars
     */
    private function checkMissingProdVars(AuditReport $report, array $baseVars, array $prodVars, bool $isPaas): void
    {
        // Sur un PaaS, les variables sont injectees par la plateforme : pas d'alerte.
        if ($isPaas) {
            return;
        }

        $missingVars = [];

        foreach ($baseVars as $key => $value) {
            // Ignorer les variables systeme gerees separement.
            if (in_array($key, self::SYSTEM_VARS, true)) {
                continue;
            }

            if (!isset($prodVars[$key])) {
                $missingVars[] = $key;
            }
        }

        if (count($missingVars) === 0) {
            return;
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::DEPLOYMENT,
            analyzer: $this->getName(),
            message: sprintf(
                '%d variable%s d\'environnement sans valeur de production',
                count($missingVars),
                count($missingVars) > 1 ? 's' : '',
            ),
            detail: 'Les variables suivantes sont definies dans .env mais absentes de .env.prod : '
                . implode(', ', $missingVars) . '. '
                . 'En production, elles utiliseront les valeurs de developpement du fichier .env, '
                . 'ce qui peut exposer des credentials de test ou des URLs de staging.',
            suggestion: 'Ajouter ces variables dans .env.prod avec les valeurs de production appropriees. '
                . 'Utiliser le systeme de secrets Symfony (bin/console secrets:set) pour les valeurs sensibles.',
            file: '.env.prod',
            fixCode: "# Dans .env.prod :\n" . implode(
                "\n",
                array_map(fn (string $var): string => "{$var}=VALEUR_DE_PRODUCTION", $missingVars),
            ),
            docUrl: 'https://symfony.com/doc/current/configuration.html#configuration-based-on-environment-variables',
            businessImpact: 'Les valeurs de developpement en production peuvent exposer des serveurs de test, '
                . 'des credentials de sandbox ou des URLs internes. Un risque de fuite de donnees '
                . 'ou de connexion a un service non securise.',
            estimatedFixMinutes: 15,
        ));
    }

    /**
     * Detecte les valeurs placeholder dans .env et .env.prod.
     *
     * Les valeurs comme "changeme", "todo" ou "xxx" indiquent une configuration
     * non finalisee. En production, cela provoque des erreurs ou des failles.
     *
     * @param array<string, string> $baseVars
     * @param array<string, string> $prodVars
     */
    /**
     * @param array<string, string> $baseVars
     * @param array<string, string> $prodVars
     */
    private function checkPlaceholderValues(AuditReport $report, array $baseVars, array $prodVars, bool $isPaas): void
    {
        // Sur un PaaS, les placeholders dans .env sont normaux (valeurs de dev),
        // seuls les placeholders dans .env.prod sont problematiques.
        $varsToCheck = $isPaas ? $prodVars : array_merge($baseVars, $prodVars);
        $placeholderVars = [];

        foreach ($varsToCheck as $key => $value) {
            $normalizedValue = strtolower(trim($value));

            foreach (self::PLACEHOLDER_VALUES as $placeholder) {
                if ($normalizedValue === $placeholder) {
                    $placeholderVars[$key] = $value;
                    break;
                }
            }
        }

        if (count($placeholderVars) === 0) {
            return;
        }

        $varDetails = [];
        foreach ($placeholderVars as $key => $value) {
            $source = isset($prodVars[$key]) ? '.env.prod' : '.env';
            $varDetails[] = sprintf('%s="%s" (%s)', $key, $value, $source);
        }

        $report->addIssue(new Issue(
            severity: Severity::CRITICAL,
            module: Module::DEPLOYMENT,
            analyzer: $this->getName(),
            message: sprintf(
                '%d variable%s avec une valeur placeholder',
                count($placeholderVars),
                count($placeholderVars) > 1 ? 's' : '',
            ),
            detail: 'Les variables suivantes contiennent des valeurs placeholder non configurees : '
                . implode(', ', $varDetails) . '. '
                . 'Ces valeurs indiquent une configuration non finalisee et provoqueront '
                . 'des erreurs ou des comportements inattendus en production.',
            suggestion: 'Remplacer chaque valeur placeholder par la valeur de production reelle. '
                . 'Utiliser le systeme de secrets Symfony pour les donnees sensibles.',
            file: '.env / .env.prod',
            fixCode: "# Remplacer les placeholders par des valeurs reelles :\n" . implode(
                "\n",
                array_map(
                    fn (string $key): string => "{$key}=VALEUR_REELLE",
                    array_keys($placeholderVars),
                ),
            ),
            docUrl: 'https://symfony.com/doc/current/configuration/secrets.html',
            businessImpact: 'Une variable avec une valeur placeholder peut empecher le demarrage '
                . 'de l\'application, provoquer des erreurs silencieuses ou exposer '
                . 'des informations de configuration par defaut.',
            estimatedFixMinutes: 10,
        ));
    }

    /**
     * Parse un fichier .env et retourne les paires cle-valeur.
     *
     * Ignore les lignes vides, les commentaires et les lignes sans signe egal.
     *
     * @return array<string, string>
     */
    private function parseEnvFile(string $content): array
    {
        $vars = [];

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key = trim($key);
            $value = trim($value, " \t\"'");

            if ($key !== '') {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }
}
