<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\HttpCacheAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class HttpCacheAnalyzerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
        mkdir($this->tmpDir . '/src/Entity', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function testNoApiResourceDoesNothing(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new HttpCacheAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        self::assertCount(0, $report->getIssues());
    }

    public function testResourceWithCacheHeadersDoesNothing(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
#[ApiResource(cacheHeaders: ["max_age" => 60])]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new HttpCacheAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        // No missing cache issue
        $cacheIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'cache HTTP'),
        );
        self::assertCount(0, $cacheIssues);
    }

    public function testResourceWithoutCacheCreatesSuggestion(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
#[ApiResource]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new HttpCacheAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $issues = $report->getIssues();
        self::assertGreaterThanOrEqual(1, count($issues));
        $cacheIssues = array_filter(
            $issues,
            fn ($i) => str_contains($i->getMessage(), 'cache HTTP'),
        );
        self::assertCount(1, $cacheIssues);
        self::assertSame(Severity::SUGGESTION, array_values($cacheIssues)[0]->getSeverity());
    }

    public function testReadOnlyResourceWithoutCacheCreatesSuggestion(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
#[ApiResource]
#[Get]
#[GetCollection]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new HttpCacheAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $cacheIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'cache HTTP'),
        );
        self::assertGreaterThanOrEqual(1, count($cacheIssues));
    }

    public function testPublicCacheOnProtectedResourceCreatesCritical(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
#[ApiResource(
    security: "is_granted(\'ROLE_USER\')",
    cacheHeaders: ["shared_max_age" => 3600],
)]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new HttpCacheAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $issues = $report->getIssues();
        $criticals = array_filter($issues, fn ($i) => $i->getSeverity() === Severity::CRITICAL);
        self::assertCount(1, $criticals);
        self::assertStringContainsString('Cache public', array_values($criticals)[0]->getMessage());
    }

    public function testPrivateCacheOnProtectedResourceDoesNothing(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
#[ApiResource(
    security: "is_granted(\'ROLE_USER\')",
    cacheHeaders: ["max_age" => 60],
)]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new HttpCacheAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $criticals = array_filter($report->getIssues(), fn ($i) => $i->getSeverity() === Severity::CRITICAL);
        self::assertCount(0, $criticals);
    }

    public function testSupportsReturnsTrueWithApiPlatform(): void
    {
        $analyzer = new HttpCacheAnalyzer($this->tmpDir);
        self::assertTrue($analyzer->supports(new ProjectContext('/fake', false, false, true, false, false, false, false, false, false, false, null)));
    }

    public function testGetNameReturnsExpectedName(): void
    {
        $analyzer = new HttpCacheAnalyzer($this->tmpDir);
        self::assertSame('HTTP Cache Analyzer', $analyzer->getName());
    }

    public function testModuleIsApiPlatform(): void
    {
        $analyzer = new HttpCacheAnalyzer($this->tmpDir);
        self::assertSame(Module::API_PLATFORM, $analyzer->getModule());
    }

    public function testEnrichmentFieldsOnCritical(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
#[ApiResource(
    security: "is_granted(\'ROLE_USER\')",
    cacheHeaders: ["shared_max_age" => 3600],
)]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new HttpCacheAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $criticals = array_values(array_filter($report->getIssues(), fn ($i) => $i->getSeverity() === Severity::CRITICAL));
        self::assertCount(1, $criticals);
        self::assertNotNull($criticals[0]->getFixCode());
        self::assertNotNull($criticals[0]->getDocUrl());
        self::assertNotNull($criticals[0]->getBusinessImpact());
        self::assertNotNull($criticals[0]->getEstimatedFixMinutes());
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) { rmdir($item->getRealPath()); }
            else { unlink($item->getRealPath()); }
        }
        rmdir($dir);
    }
}
