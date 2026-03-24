<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Cache;

use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Issue;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;


/**
 * Gère la persistance des rapports d'audit sur le disque.
 * Le cache est identifié par un hash SHA256 des fichiers de configuration du projet.
 * Un hash identique signifie que les fichiers n'ont pas changé depuis le dernier audit.
 */
final class ResultCache implements ResultCacheInterface
{
    private string $cacheDir;

    public function __construct(string $cacheDir = '')
    {
        // Dossier de cache par défaut dans le répertoire temporaire du système.
        $this->cacheDir = $cacheDir !== '' ? $cacheDir : sys_get_temp_dir() . '/sf_doctor_cache';
    }

    /**
     * Calcule un hash SHA256 représentant l'état actuel des fichiers de config du projet.
     * Si le hash est identique au précédent audit, le cache est valide.
     */
    public function computeHash(string $projectPath): string
    {
        $configPath = $projectPath . '/config';

        if (!is_dir($configPath)) {
            // Pas de dossier config : on hash uniquement le chemin du projet.
            return hash('sha256', $projectPath);
        }

        // Collecter tous les fichiers YAML du dossier config récursivement.
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($configPath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && in_array($file->getExtension(), ['yaml', 'yml'], true)) {
                $files[] = $file->getRealPath();
            }
        }

        // Trier pour garantir un hash stable indépendamment de l'ordre de lecture du FS.
        sort($files);

        // Concaténer le contenu de tous les fichiers et hasher l'ensemble.
        $content = '';
        foreach ($files as $filePath) {
            $fileContent = file_get_contents($filePath);
            if ($fileContent !== false) {
                $content .= $fileContent;
            }
        }

        return hash('sha256', $content);
    }

    /**
     * Charge un rapport depuis le cache.
     * Retourne null si aucun cache n'existe pour ce hash.
     */
    public function load(string $hash): ?AuditReport
    {
        $cacheFile = $this->getCacheFilePath($hash);

        if (!file_exists($cacheFile)) {
            return null;
        }

        $json = file_get_contents($cacheFile);
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
     * Sauvegarde un rapport dans le cache.
     */
    public function save(string $hash, AuditReport $report): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $data = $this->serialize($report);
        file_put_contents(
            $this->getCacheFilePath($hash),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Retourne le chemin complet du fichier de cache pour un hash donné.
     */
    public function getCacheFilePath(string $hash): string
    {
        return $this->cacheDir . '/' . $hash . '.json';
    }

    /**
     * Sérialise un AuditReport en tableau associatif.
     *
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
            'cached_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Désérialise un tableau associatif en AuditReport.
     *
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