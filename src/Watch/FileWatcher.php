<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Watch;

/**
 * Surveille un ensemble de repertoires et detecte les fichiers modifies.
 *
 * Fonctionne par polling : a chaque appel de detectChanges(), compare
 * les dates de modification avec le snapshot precedent.
 * Approche portable (pas de dependance systeme comme inotify).
 */
final class FileWatcher
{
    /**
     * Snapshot des dates de modification : chemin -> timestamp.
     *
     * @var array<string, int>
     */
    private array $snapshot = [];

    /**
     * @param list<string> $directories Repertoires a surveiller
     * @param list<string> $extensions  Extensions de fichiers a inclure (ex: ['php', 'yaml', 'twig'])
     */
    public function __construct(
        private readonly array $directories,
        private readonly array $extensions = ['php', 'yaml', 'yml', 'twig', 'env'],
    ) {
        $this->snapshot = $this->scan();
    }

    /**
     * Detecte les fichiers modifies, ajoutes ou supprimes depuis le dernier appel.
     * Met a jour le snapshot interne apres chaque appel.
     *
     * @return list<string> Chemins des fichiers qui ont change
     */
    public function detectChanges(): array
    {
        $current = $this->scan();
        $changed = [];

        // Fichiers modifies ou ajoutes.
        foreach ($current as $path => $mtime) {
            if (!isset($this->snapshot[$path]) || $this->snapshot[$path] !== $mtime) {
                $changed[] = $path;
            }
        }

        // Fichiers supprimes.
        foreach ($this->snapshot as $path => $mtime) {
            if (!isset($current[$path])) {
                $changed[] = $path;
            }
        }

        $this->snapshot = $current;

        return $changed;
    }

    /**
     * Parcourt les repertoires surveilles et collecte les dates de modification.
     *
     * @return array<string, int>
     */
    private function scan(): array
    {
        $files = [];

        foreach ($this->directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->isDir()) {
                    continue;
                }

                if (!in_array($file->getExtension(), $this->extensions, true)) {
                    continue;
                }

                $realPath = $file->getRealPath();
                if ($realPath !== false) {
                    $files[$realPath] = $file->getMTime();
                }
            }
        }

        return $files;
    }
}
