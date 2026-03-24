<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Configuration;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Context\ProjectContext;

/**
 * Verifie que le projet n'est pas deploye avec APP_DEBUG ou APP_ENV=dev.
 * Lit .env.prod en priorite, puis .env si .env.prod est absent.
 */
class DebugModeAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
    ) {}

    public function getName(): string
    {
        return 'debug_mode';
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

    public function analyze(AuditReport $report): void
    {
        $vars = $this->loadEnvVars();

        $this->checkAppEnv($vars, $report);
        $this->checkAppDebug($vars, $report);
    }

    /**
     * @return array<string, string>
     */
    private function loadEnvVars(): array
    {
        $prodFile = $this->projectPath . '/.env.prod';
        $baseFile = $this->projectPath . '/.env';

        $file = file_exists($prodFile) ? $prodFile : $baseFile;

        $content = file_get_contents($file);

        if ($content === false) {
            return [];
        }

        return $this->parseEnvFile($content);
    }

    /**
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

            $vars[trim($key)] = trim($value, " \t\"'");
        }

        return $vars;
    }

    /**
     * @param array<string, string> $vars
     */
    private function checkAppEnv(array $vars, AuditReport $report): void
    {
        if (!isset($vars['APP_ENV'])) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'APP_ENV is not defined in .env.prod or .env.',
                detail: 'Symfony defaults to "dev" if APP_ENV is not set.',
                suggestion: 'Add APP_ENV=prod to your .env.prod file.',
                file: '.env.prod / .env',
                fixCode: "# Dans .env.prod :\nAPP_ENV=prod",
                docUrl: 'https://symfony.com/doc/current/configuration.html#configuration-environments',
                businessImpact: 'Sans APP_ENV=prod, Symfony démarre en mode "dev". '
                    . 'Le profiler, les logs détaillés et les messages d\'erreur complets '
                    . 'sont exposés à n\'importe quel visiteur.',
                estimatedFixMinutes: 5,
            ));

            return;
        }

        if ($vars['APP_ENV'] !== 'prod') {
            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: sprintf('APP_ENV is set to "%s" instead of "prod".', $vars['APP_ENV']),
                detail: 'Running with APP_ENV=dev in production exposes debug tools and disables caches.',
                suggestion: 'Set APP_ENV=prod in your .env.prod file.',
                file: '.env.prod / .env',
                fixCode: "# Dans .env.prod :\nAPP_ENV=prod",
                docUrl: 'https://symfony.com/doc/current/configuration.html#configuration-environments',
                businessImpact: 'Le mode dev expose la Symfony Toolbar, le Profiler et les traces '
                    . 'd\'exception complètes. Un attaquant peut lire les requêtes SQL, '
                    . 'les variables de session et les paramètres de configuration.',
                estimatedFixMinutes: 5,
            ));
        }
    }

    /**
     * @param array<string, string> $vars
     */
    private function checkAppDebug(array $vars, AuditReport $report): void
    {
        if (!isset($vars['APP_DEBUG'])) {
            return;
        }

        if (in_array($vars['APP_DEBUG'], ['true', '1'], true)) {
            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::SECURITY,
                analyzer: $this->getName(),
                message: 'APP_DEBUG is set to true.',
                detail: 'Debug mode enabled in production exposes full stack traces and internal configuration.',
                suggestion: 'Set APP_DEBUG=false in your .env.prod file.',
                file: '.env.prod / .env',
                fixCode: "# Dans .env.prod :\nAPP_DEBUG=false",
                docUrl: 'https://symfony.com/doc/current/configuration/front_controllers_and_kernel.html#debug-mode',
                businessImpact: 'Les stack traces complètes exposent la structure interne du projet, '
                    . 'les chemins absolus, les noms de classes et parfois des tokens ou mots de passe '
                    . 'présents dans les paramètres. Blackfire mesure une dégradation de performance '
                    . 'de 40 à 70% avec APP_DEBUG=true.',
                estimatedFixMinutes: 5,
            ));
        }
    }
}