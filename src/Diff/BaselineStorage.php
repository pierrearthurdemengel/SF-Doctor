<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Diff;

use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Persiste et restaure un rapport d'audit au format JSON.
 * Utilise pour sauvegarder une baseline (rapport de reference)
 * et la recharger lors d'un audit ulterieur avec --diff.
 */
final class BaselineStorage
{
    /**
     * Charge un rapport depuis un fichier JSON.
     * Retourne null si le fichier n'existe pas ou n'est pas lisible.
     */
    public function load(string $path): ?AuditReport
    {
        if (!file_exists($path) || !is_readable($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        return $this->deserialize($data);
    }

    /**
     * Sauvegarde un rapport dans un fichier JSON.
     * Cree les repertoires parents si necessaire.
     */
    public function save(string $path, AuditReport $report): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($this->serialize($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(AuditReport $report): array
    {
        return [
            'project_path' => $report->getProjectPath(),
            'modules' => array_map(fn (Module $m): string => $m->value, $report->getModules()),
            'score' => $report->getScore(),
            'issues' => array_map(fn (Issue $issue): array => [
                'severity' => $issue->getSeverity()->value,
                'module' => $issue->getModule()->value,
                'analyzer' => $issue->getAnalyzer(),
                'message' => $issue->getMessage(),
                'detail' => $issue->getDetail(),
                'suggestion' => $issue->getSuggestion(),
                'file' => $issue->getFile(),
                'line' => $issue->getLine(),
            ], $report->getIssues()),
            'saved_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function deserialize(array $data): AuditReport
    {
        $modules = array_map(
            fn (string $value): Module => Module::from($value),
            $data['modules'] ?? [],
        );

        $report = new AuditReport(
            projectPath: $data['project_path'] ?? '',
            modules: $modules,
        );

        foreach ($data['issues'] ?? [] as $issueData) {
            $report->addIssue(new Issue(
                severity: Severity::from($issueData['severity']),
                module: Module::from($issueData['module']),
                analyzer: $issueData['analyzer'],
                message: $issueData['message'],
                detail: $issueData['detail'],
                suggestion: $issueData['suggestion'],
                file: $issueData['file'] ?? null,
                line: $issueData['line'] ?? null,
            ));
        }

        $report->complete();

        return $report;
    }
}
