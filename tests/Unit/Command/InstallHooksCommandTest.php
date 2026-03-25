<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Command\InstallHooksCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class InstallHooksCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_install_hooks_test_' . uniqid();
        mkdir($this->tempDir . '/.git/hooks', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testInstallHookSuccess(): void
    {
        $tester = $this->createCommandTester($this->tempDir);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('installe', $tester->getDisplay());
        $this->assertFileExists($this->tempDir . '/.git/hooks/pre-commit');
    }

    public function testInstallHookFailsWithoutGitDir(): void
    {
        $noGitDir = sys_get_temp_dir() . '/sf_doctor_no_git_cmd_' . uniqid();
        mkdir($noGitDir, 0755, true);

        $tester = $this->createCommandTester($noGitDir);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Git', $tester->getDisplay());

        rmdir($noGitDir);
    }

    public function testInstallHookRefusesForeignHook(): void
    {
        file_put_contents($this->tempDir . '/.git/hooks/pre-commit', "#!/bin/sh\necho 'foreign'");

        $tester = $this->createCommandTester($this->tempDir);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('existe deja', $tester->getDisplay());
    }

    public function testUninstallHookSuccess(): void
    {
        // Installer d'abord.
        $tester = $this->createCommandTester($this->tempDir);
        $tester->execute([]);
        $this->assertFileExists($this->tempDir . '/.git/hooks/pre-commit');

        // Desinstaller.
        $tester->execute(['--uninstall' => true]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('desinstalle', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->tempDir . '/.git/hooks/pre-commit');
    }

    public function testUninstallWithNoHookShowsWarning(): void
    {
        $tester = $this->createCommandTester($this->tempDir);
        $tester->execute(['--uninstall' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Aucun hook', $tester->getDisplay());
    }

    private function createCommandTester(string $projectPath): CommandTester
    {
        $command = new InstallHooksCommand($projectPath);

        $application = new Application('SF Doctor', 'test');
        $application->add($command);

        return new CommandTester($application->find('sf-doctor:install-hooks'));
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
