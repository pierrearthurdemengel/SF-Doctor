<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Watch;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Watch\FileWatcher;

final class FileWatcherTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_watcher_test_' . uniqid();
        mkdir($this->tempDir . '/src', 0755, true);
        mkdir($this->tempDir . '/config', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testNoChangesOnFirstCall(): void
    {
        file_put_contents($this->tempDir . '/src/Controller.php', '<?php class Controller {}');

        $watcher = new FileWatcher([$this->tempDir . '/src']);

        // Premier appel apres construction : aucun changement.
        $changes = $watcher->detectChanges();

        $this->assertSame([], $changes);
    }

    public function testDetectsModifiedFile(): void
    {
        $filePath = $this->tempDir . '/src/Service.php';
        file_put_contents($filePath, '<?php class Service {}');

        $watcher = new FileWatcher([$this->tempDir . '/src']);
        $watcher->detectChanges(); // Premier appel, baseline.

        // Modifier le fichier (forcer un mtime different).
        sleep(1);
        file_put_contents($filePath, '<?php class Service { public function run() {} }');

        $changes = $watcher->detectChanges();

        $this->assertCount(1, $changes);
        $this->assertSame(realpath($filePath), $changes[0]);
    }

    public function testDetectsNewFile(): void
    {
        file_put_contents($this->tempDir . '/src/Existing.php', '<?php class Existing {}');

        $watcher = new FileWatcher([$this->tempDir . '/src']);
        $watcher->detectChanges();

        // Ajouter un nouveau fichier.
        file_put_contents($this->tempDir . '/src/NewClass.php', '<?php class NewClass {}');

        $changes = $watcher->detectChanges();

        $this->assertCount(1, $changes);
        $this->assertStringContainsString('NewClass.php', $changes[0]);
    }

    public function testDetectsDeletedFile(): void
    {
        $filePath = $this->tempDir . '/src/ToDelete.php';
        file_put_contents($filePath, '<?php class ToDelete {}');

        $watcher = new FileWatcher([$this->tempDir . '/src']);
        $watcher->detectChanges();

        unlink($filePath);

        $changes = $watcher->detectChanges();

        $this->assertCount(1, $changes);
        $this->assertStringContainsString('ToDelete.php', $changes[0]);
    }

    public function testIgnoresUnwatchedExtensions(): void
    {
        $watcher = new FileWatcher([$this->tempDir . '/src'], ['php']);
        $watcher->detectChanges();

        // Ajouter un fichier .txt (non surveille).
        file_put_contents($this->tempDir . '/src/notes.txt', 'notes');

        $changes = $watcher->detectChanges();

        $this->assertSame([], $changes);
    }

    public function testWatchesMultipleDirectories(): void
    {
        $watcher = new FileWatcher([
            $this->tempDir . '/src',
            $this->tempDir . '/config',
        ]);
        $watcher->detectChanges();

        file_put_contents($this->tempDir . '/src/New.php', '<?php class New_ {}');
        file_put_contents($this->tempDir . '/config/services.yaml', 'services: ~');

        $changes = $watcher->detectChanges();

        $this->assertCount(2, $changes);
    }

    public function testSkipsNonExistentDirectory(): void
    {
        // Un repertoire qui n'existe pas ne doit pas provoquer d'erreur.
        $watcher = new FileWatcher([
            $this->tempDir . '/src',
            $this->tempDir . '/inexistant',
        ]);

        $changes = $watcher->detectChanges();

        $this->assertSame([], $changes);
    }

    public function testSecondCallWithoutChangesReturnsEmpty(): void
    {
        file_put_contents($this->tempDir . '/src/Stable.php', '<?php class Stable {}');

        $watcher = new FileWatcher([$this->tempDir . '/src']);
        $watcher->detectChanges();

        // Deuxieme appel sans modification.
        $changes = $watcher->detectChanges();

        $this->assertSame([], $changes);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }
}
