<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Git\PreCommitHook;

final class PreCommitHookTest extends TestCase
{
    private string $tempDir;
    private PreCommitHook $hook;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_hook_test_' . uniqid();
        mkdir($this->tempDir . '/.git/hooks', 0755, true);
        $this->hook = new PreCommitHook();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testGenerateReturnsShellScript(): void
    {
        $script = $this->hook->generate();

        $this->assertStringContainsString('#!/bin/sh', $script);
        $this->assertStringContainsString('sf-doctor:pre-commit', $script);
        $this->assertStringContainsString('sf-doctor:audit', $script);
        $this->assertStringContainsString('--security', $script);
        $this->assertStringContainsString('--brief', $script);
    }

    public function testInstallCreatesHookFile(): void
    {
        $hookPath = $this->hook->install($this->tempDir);

        $this->assertNotNull($hookPath);
        $this->assertFileExists($hookPath);
        $this->assertStringContainsString('sf-doctor:pre-commit', file_get_contents($hookPath));
    }

    public function testInstallReturnsNullWithoutGitDir(): void
    {
        $noGitDir = sys_get_temp_dir() . '/sf_doctor_no_git_' . uniqid();
        mkdir($noGitDir, 0755, true);

        $hookPath = $this->hook->install($noGitDir);

        $this->assertNull($hookPath);

        rmdir($noGitDir);
    }

    public function testInstallDoesNotOverwriteForeignHook(): void
    {
        // Un hook pre-commit existant qui n'est pas de SF-Doctor.
        $existingHookPath = $this->tempDir . '/.git/hooks/pre-commit';
        file_put_contents($existingHookPath, "#!/bin/sh\necho 'custom hook'");

        $hookPath = $this->hook->install($this->tempDir);

        $this->assertNull($hookPath);
        // Le hook existant est preserve.
        $this->assertStringContainsString('custom hook', file_get_contents($existingHookPath));
    }

    public function testInstallOverwritesSfDoctorHook(): void
    {
        // Un hook SF-Doctor existant (mise a jour).
        $existingHookPath = $this->tempDir . '/.git/hooks/pre-commit';
        file_put_contents($existingHookPath, "#!/bin/sh\n# sf-doctor:pre-commit\nold version");

        $hookPath = $this->hook->install($this->tempDir);

        $this->assertNotNull($hookPath);
        $this->assertStringNotContainsString('old version', file_get_contents($hookPath));
    }

    public function testUninstallRemovesSfDoctorHook(): void
    {
        // Installer puis desinstaller.
        $this->hook->install($this->tempDir);
        $hookPath = $this->tempDir . '/.git/hooks/pre-commit';
        $this->assertFileExists($hookPath);

        $result = $this->hook->uninstall($this->tempDir);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($hookPath);
    }

    public function testUninstallRefusesToRemoveForeignHook(): void
    {
        $hookPath = $this->tempDir . '/.git/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/sh\necho 'custom hook'");

        $result = $this->hook->uninstall($this->tempDir);

        $this->assertFalse($result);
        $this->assertFileExists($hookPath);
    }

    public function testUninstallReturnsFalseWhenNoHook(): void
    {
        $result = $this->hook->uninstall($this->tempDir);

        $this->assertFalse($result);
    }

    public function testIsSfDoctorHookDetectsMarker(): void
    {
        $hookPath = $this->tempDir . '/.git/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/sh\n# sf-doctor:pre-commit\nsome content");

        $this->assertTrue($this->hook->isSfDoctorHook($hookPath));
    }

    public function testIsSfDoctorHookReturnsFalseForForeignHook(): void
    {
        $hookPath = $this->tempDir . '/.git/hooks/pre-commit';
        file_put_contents($hookPath, "#!/bin/sh\necho 'other hook'");

        $this->assertFalse($this->hook->isSfDoctorHook($hookPath));
    }

    public function testIsSfDoctorHookReturnsFalseForMissingFile(): void
    {
        $this->assertFalse($this->hook->isSfDoctorHook($this->tempDir . '/nonexistent'));
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
