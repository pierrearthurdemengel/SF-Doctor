<?php

// tests/Unit/Context/ProjectContextDetectorTest.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Context\ProjectContextDetector;

class ProjectContextDetectorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sf-doctor-context-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['composer.json', 'composer.lock'] as $file) {
            $path = $this->tmpDir . '/' . $file;
            if (file_exists($path)) {
                unlink($path);
            }
        }
        rmdir($this->tmpDir);
    }

    private function writeComposerJson(array $require, array $requireDev = []): void
    {
        file_put_contents(
            $this->tmpDir . '/composer.json',
            json_encode(['require' => $require, 'require-dev' => $requireDev])
        );
    }

    private function writeComposerLock(string $packageName, string $version): void
    {
        file_put_contents(
            $this->tmpDir . '/composer.lock',
            json_encode([
                'packages' => [
                    ['name' => $packageName, 'version' => $version],
                ],
                'packages-dev' => [],
            ])
        );
    }

    // --- Pas de composer.json ---

    public function testReturnsEmptyContextWhenNoComposerJson(): void
    {
        $detector = new ProjectContextDetector($this->tmpDir);
        $context = $detector->detect();

        $this->assertFalse($context->hasDoctrineOrm());
        $this->assertFalse($context->hasMessenger());
        $this->assertFalse($context->hasSecurityBundle());
        $this->assertNull($context->getSymfonyVersion());
    }

    // --- Detection des packages ---

    public function testDetectsDoctrineOrm(): void
    {
        $this->writeComposerJson(['doctrine/orm' => '^2.15']);

        $context = (new ProjectContextDetector($this->tmpDir))->detect();

        $this->assertTrue($context->hasDoctrineOrm());
    }

    public function testDetectsDoctrineBundleAsDoctrineOrm(): void
    {
        $this->writeComposerJson(['doctrine/doctrine-bundle' => '^2.11']);

        $context = (new ProjectContextDetector($this->tmpDir))->detect();

        $this->assertTrue($context->hasDoctrineOrm());
    }

    public function testDetectsMessenger(): void
    {
        $this->writeComposerJson(['symfony/messenger' => '^6.4']);

        $context = (new ProjectContextDetector($this->tmpDir))->detect();

        $this->assertTrue($context->hasMessenger());
    }

    public function testDetectsSecurityBundle(): void
    {
        $this->writeComposerJson(['symfony/security-bundle' => '^6.4']);

        $context = (new ProjectContextDetector($this->tmpDir))->detect();

        $this->assertTrue($context->hasSecurityBundle());
    }

    public function testDetectsMailer(): void
    {
        $this->writeComposerJson([], ['symfony/mailer' => '^6.4']);

        $context = (new ProjectContextDetector($this->tmpDir))->detect();

        $this->assertTrue($context->hasMailer());
    }

    public function testDetectsApiPlatformPack(): void
    {
        $this->writeComposerJson(['api-platform/api-pack' => '^1.5']);

        $context = (new ProjectContextDetector($this->tmpDir))->detect();

        $this->assertTrue($context->hasApiPlatform());
    }

    // --- Version Symfony ---

    public function testDetectsSymfonyVersionFromLock(): void
    {
        $this->writeComposerJson(['symfony/http-kernel' => '^6.4']);
        $this->writeComposerLock('symfony/http-kernel', 'v6.4.12');

        $context = (new ProjectContextDetector($this->tmpDir))->detect();

        $this->assertSame('6.4.12', $context->getSymfonyVersion());
    }

    public function testReturnsNullVersionWhenNoLock(): void
    {
        $this->writeComposerJson(['symfony/http-kernel' => '^6.4']);

        $context = (new ProjectContextDetector($this->tmpDir))->detect();

        $this->assertNull($context->getSymfonyVersion());
    }

    // --- isSymfonyAtLeast ---

    public function testIsSymfonyAtLeast(): void
    {
        $this->writeComposerJson([]);
        $this->writeComposerLock('symfony/http-kernel', 'v7.1.3');

        $context = (new ProjectContextDetector($this->tmpDir))->detect();

        $this->assertTrue($context->isSymfonyAtLeast('7.0'));
        $this->assertTrue($context->isSymfonyAtLeast('7.1.3'));
        $this->assertFalse($context->isSymfonyAtLeast('7.2'));
    }

    public function testIsSymfonyAtLeastReturnsFalseWhenVersionUnknown(): void
    {
        $this->writeComposerJson([]);

        $context = (new ProjectContextDetector($this->tmpDir))->detect();

        $this->assertFalse($context->isSymfonyAtLeast('6.4'));
    }
}