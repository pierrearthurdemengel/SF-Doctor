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
 * Analyse les fichiers de log Symfony pour detecter les problemes recurrents.
 *
 * Lit les derniers 500 Ko de var/log/prod.log et var/log/dev.log
 * pour identifier les erreurs 500 recurrentes et les deprecations actives.
 */
final class LogAnalyzer implements AnalyzerInterface
{
    private const MAX_BYTES = 512_000; // 500 Ko

    public function __construct(
        private readonly string $projectPath,
    ) {
    }

    public function analyze(AuditReport $report): void
    {
        $logDir = $this->projectPath . '/var/log';

        if (!is_dir($logDir)) {
            return;
        }

        $this->checkProdLog($report, $logDir);
        $this->checkDevLog($report, $logDir);
    }

    public function getName(): string
    {
        return 'Log Analyzer';
    }

    public function getModule(): Module
    {
        return Module::DEPLOYMENT;
    }

    public function supports(ProjectContext $context): bool
    {
        return true;
    }

    private function checkProdLog(AuditReport $report, string $logDir): void
    {
        $prodLog = $logDir . '/prod.log';

        if (!file_exists($prodLog)) {
            return;
        }

        $content = $this->readTail($prodLog);
        if ($content === '') {
            return;
        }

        // Count 500 errors
        $errorCounts = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            if (!preg_match('/\.(CRITICAL|ERROR|EMERGENCY)/', $line)) {
                continue;
            }

            // Extract the error message (after the severity level)
            if (preg_match('/\.\w+:\s+(.+?)(?:\s+\[|\s*$)/', $line, $m)) {
                $errorMsg = trim($m[1]);
                // Normalize the message by removing variable parts
                $normalized = preg_replace('/\d+/', '#', $errorMsg) ?? $errorMsg;
                $errorCounts[$normalized] = ($errorCounts[$normalized] ?? 0) + 1;
            }
        }

        foreach ($errorCounts as $errorMsg => $count) {
            if ($count >= 10) {
                $report->addIssue(new Issue(
                    severity: Severity::CRITICAL,
                    module: Module::DEPLOYMENT,
                    analyzer: $this->getName(),
                    message: sprintf('Erreur recurrente dans prod.log (%d occurrences)', $count),
                    detail: "L'erreur suivante apparait {$count} fois dans les logs de production : \"{$errorMsg}\". "
                        . "Une erreur recurrente indique un probleme non corrige qui affecte les utilisateurs en continu.",
                    suggestion: "Investiguer et corriger la cause racine de cette erreur. "
                        . "Verifier les logs complets avec : tail -f var/log/prod.log | grep ERROR",
                    file: 'var/log/prod.log',
                    businessImpact: "Les utilisateurs rencontrent des erreurs repetees. "
                        . "Chaque occurrence peut correspondre a une requete echouee.",
                    fixCode: "tail -100 var/log/prod.log | grep -i error",
                    docUrl: 'https://symfony.com/doc/current/logging.html',
                    estimatedFixMinutes: 30,
                ));
            }
        }
    }

    private function checkDevLog(AuditReport $report, string $logDir): void
    {
        $devLog = $logDir . '/dev.log';

        if (!file_exists($devLog)) {
            return;
        }

        $content = $this->readTail($devLog);
        if ($content === '') {
            return;
        }

        // Count deprecations
        $deprecationCount = 0;
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            if (str_contains($line, 'User Deprecated') || str_contains($line, '.DEPRECATION')) {
                $deprecationCount++;
            }
        }

        if ($deprecationCount >= 100) {
            $report->addIssue(new Issue(
                severity: Severity::WARNING,
                module: Module::DEPLOYMENT,
                analyzer: $this->getName(),
                message: sprintf('%d deprecation(s) detectee(s) dans dev.log', $deprecationCount),
                detail: "Plus de {$deprecationCount} deprecations detectees dans les logs de developpement. "
                    . "Ces deprecations indiquent du code qui cessera de fonctionner dans une version "
                    . "future de Symfony. Elles doivent etre corrigees avant la migration.",
                suggestion: "Corriger les deprecations une par une en suivant les recommendations "
                    . "du message de deprecation. Utiliser le profiler Symfony pour les lister.",
                file: 'var/log/dev.log',
                businessImpact: "Les deprecations non corrigees bloquent la migration vers "
                    . "la prochaine version majeure de Symfony.",
                fixCode: "bin/console debug:container --deprecations",
                docUrl: 'https://symfony.com/doc/current/setup/upgrade_major.html',
                estimatedFixMinutes: 60,
            ));
        }
    }

    /**
     * Read the last MAX_BYTES of a file.
     */
    private function readTail(string $filePath): string
    {
        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize === 0) {
            return '';
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return '';
        }

        $offset = max(0, $fileSize - self::MAX_BYTES);
        fseek($handle, $offset);
        $content = fread($handle, self::MAX_BYTES);
        fclose($handle);

        return $content !== false ? $content : '';
    }
}
