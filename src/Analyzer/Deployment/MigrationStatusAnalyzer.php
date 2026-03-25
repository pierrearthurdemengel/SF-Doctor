<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Analyzer\Deployment;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Detecte les migrations Doctrine non jouees.
 *
 * Parcourt le dossier migrations/ et compare avec le fichier de status
 * pour identifier les migrations en attente. Un deploy avec des migrations
 * non jouees provoque des erreurs SQL a la premiere requete.
 */
final class MigrationStatusAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly string $projectPath,
    ) {
    }

    public function analyze(AuditReport $report): void
    {
        $migrationsDir = $this->projectPath . '/migrations';

        if (!is_dir($migrationsDir)) {
            return;
        }

        $migrationFiles = $this->findMigrationFiles($migrationsDir);

        if (empty($migrationFiles)) {
            return;
        }

        // Check 1 : Verifie que doctrine/doctrine-migrations-bundle est dans composer.json
        $composerFile = $this->projectPath . '/composer.json';
        if (!file_exists($composerFile)) {
            return;
        }

        $composerContent = file_get_contents($composerFile);
        if ($composerContent === false) {
            return;
        }

        $composer = json_decode($composerContent, true);
        if (!is_array($composer)) {
            return;
        }

        $allDeps = array_merge(
            $composer['require'] ?? [],
            $composer['require-dev'] ?? [],
        );

        if (!isset($allDeps['doctrine/doctrine-migrations-bundle'])) {
            return;
        }

        // Check for a tracked migrations version file
        $executedMigrations = $this->findExecutedMigrations();

        $pendingMigrations = [];
        foreach ($migrationFiles as $migrationFile) {
            $version = $this->extractVersion($migrationFile);
            if ($version !== null && !in_array($version, $executedMigrations, true)) {
                $pendingMigrations[] = $migrationFile;
            }
        }

        // If we can't determine executed migrations, check for old migration files
        if (empty($executedMigrations) && $migrationFiles !== []) {
            $oldMigrations = $this->findOldMigrations($migrationFiles);

            if (count($oldMigrations) > 0) {
                $report->addIssue(new Issue(
                    severity: Severity::WARNING,
                    module: Module::DEPLOYMENT,
                    analyzer: $this->getName(),
                    message: sprintf('%d migration(s) de plus de 7 jours detectee(s)', count($oldMigrations)),
                    detail: 'Des fichiers de migration datant de plus de 7 jours existent dans le dossier migrations/. '
                        . 'Si ces migrations n\'ont pas ete executees, le schema de la base de donnees est desynchronise.',
                    suggestion: 'Verifier l\'etat des migrations avec bin/console doctrine:migrations:status '
                        . 'et executer les migrations en attente.',
                    file: 'migrations/',
                    fixCode: 'bin/console doctrine:migrations:migrate --no-interaction',
                    docUrl: 'https://www.doctrine-project.org/projects/doctrine-migrations/en/stable/reference/introduction.html',
                    businessImpact: 'Des migrations non jouees provoquent des erreurs SQL a la premiere requete '
                        . 'sur les nouvelles colonnes ou tables.',
                    estimatedFixMinutes: 15,
                ));
            }
        }

        if (!empty($pendingMigrations)) {
            $report->addIssue(new Issue(
                severity: Severity::CRITICAL,
                module: Module::DEPLOYMENT,
                analyzer: $this->getName(),
                message: sprintf('%d migration(s) non jouee(s) detectee(s)', count($pendingMigrations)),
                detail: 'Des migrations Doctrine n\'ont pas ete executees. Le schema de la base '
                    . 'de donnees est desynchronise avec le code. La premiere requete SQL '
                    . 'utilisant une nouvelle colonne ou table provoquera une erreur 500.',
                suggestion: 'Executer les migrations en attente avec bin/console doctrine:migrations:migrate.',
                file: 'migrations/',
                fixCode: 'bin/console doctrine:migrations:migrate --no-interaction',
                docUrl: 'https://www.doctrine-project.org/projects/doctrine-migrations/en/stable/reference/introduction.html',
                businessImpact: 'Le site web retournera des erreurs 500 sur toutes les pages '
                    . 'qui accedent aux nouvelles colonnes ou tables.',
                estimatedFixMinutes: 10,
            ));
        }
    }

    public function getName(): string
    {
        return 'Migration Status Analyzer';
    }

    public function getModule(): Module
    {
        return Module::DEPLOYMENT;
    }

    public function supports(ProjectContext $context): bool
    {
        return $context->hasDoctrineOrm();
    }

    /**
     * @return list<string>
     */
    private function findMigrationFiles(string $dir): array
    {
        $files = [];
        $items = scandir($dir);
        if ($items === false) {
            return [];
        }

        foreach ($items as $item) {
            if (str_starts_with($item, 'Version') && str_ends_with($item, '.php')) {
                $files[] = $item;
            }
        }

        return $files;
    }

    /**
     * Try to find executed migrations from a tracking file.
     *
     * @return list<string>
     */
    private function findExecutedMigrations(): array
    {
        // Try to read from doctrine_migration_versions table dump or status file
        $statusFile = $this->projectPath . '/var/migrations_status.json';
        if (!file_exists($statusFile)) {
            return [];
        }

        $content = file_get_contents($statusFile);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        return $data['executed'] ?? [];
    }

    private function extractVersion(string $filename): ?string
    {
        if (preg_match('/Version(\d+)\.php/', $filename, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Find migration files older than 7 days.
     *
     * @param list<string> $files
     * @return list<string>
     */
    private function findOldMigrations(array $files): array
    {
        $old = [];
        $threshold = time() - (7 * 24 * 3600);

        foreach ($files as $file) {
            // Extract timestamp from Version filename (e.g., Version20250315120000)
            if (preg_match('/Version(\d{14})\.php/', $file, $m)) {
                $timestamp = \DateTimeImmutable::createFromFormat('YmdHis', $m[1]);
                if ($timestamp !== false && $timestamp->getTimestamp() < $threshold) {
                    $old[] = $file;
                }
            }
        }

        return $old;
    }
}
