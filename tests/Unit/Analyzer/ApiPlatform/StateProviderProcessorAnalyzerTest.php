<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\StateProviderProcessorAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class StateProviderProcessorAnalyzerTest extends TestCase
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
        $analyzer = new StateProviderProcessorAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        self::assertCount(0, $report->getIssues());
    }

    public function testValidProcessorDoesNothing(): void
    {
        // Create the processor file so it exists
        mkdir($this->tmpDir . '/src/State', 0777, true);
        file_put_contents($this->tmpDir . '/src/State/ProductProcessor.php', '<?php
namespace App\State;
class ProductProcessor {}
');
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\ProductProcessor;
#[ApiResource(processor: ProductProcessor::class)]
#[Post]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new StateProviderProcessorAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        self::assertCount(0, $report->getIssues());
    }

    public function testMissingProcessorCreatesCritical(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\NonExistentProcessor;
#[ApiResource(processor: NonExistentProcessor::class)]
#[Post]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new StateProviderProcessorAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertSame(Severity::CRITICAL, $issues[0]->getSeverity());
        self::assertStringContainsString('processor', $issues[0]->getMessage());
    }

    public function testMissingProviderCreatesCritical(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use App\State\NonExistentProvider;
#[ApiResource(provider: NonExistentProvider::class)]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new StateProviderProcessorAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertSame(Severity::CRITICAL, $issues[0]->getSeverity());
        self::assertStringContainsString('provider', $issues[0]->getMessage());
    }

    public function testProcessorOnReadOnlyResourceCreatesWarning(): void
    {
        mkdir($this->tmpDir . '/src/State', 0777, true);
        file_put_contents($this->tmpDir . '/src/State/ProductProcessor.php', '<?php
namespace App\State;
class ProductProcessor {}
');
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\State\ProductProcessor;
#[ApiResource(processor: ProductProcessor::class, operations: [new Get(), new GetCollection()])]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new StateProviderProcessorAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertSame(Severity::WARNING, $issues[0]->getSeverity());
        self::assertStringContainsString('lecture seule', $issues[0]->getMessage());
    }

    public function testProcessorOnWriteResourceDoesNothing(): void
    {
        mkdir($this->tmpDir . '/src/State', 0777, true);
        file_put_contents($this->tmpDir . '/src/State/ProductProcessor.php', '<?php
namespace App\State;
class ProductProcessor {}
');
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\ProductProcessor;
#[ApiResource(processor: ProductProcessor::class, operations: [new Post()])]
#[Post]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new StateProviderProcessorAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        // Should have 0 issues for the read-only check (may have other issues)
        $readOnlyIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'lecture seule'),
        );
        self::assertCount(0, $readOnlyIssues);
    }

    public function testSupportsReturnsTrueWithApiPlatform(): void
    {
        $analyzer = new StateProviderProcessorAnalyzer($this->tmpDir);
        $context = new ProjectContext('/fake', false, false, true, false, false, false, false, false, false, false, null);
        self::assertTrue($analyzer->supports($context));
    }

    public function testGetNameReturnsExpectedName(): void
    {
        $analyzer = new StateProviderProcessorAnalyzer($this->tmpDir);
        self::assertSame('State Provider/Processor Analyzer', $analyzer->getName());
    }

    public function testEnrichmentFieldsArePresent(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use App\State\MissingProcessor;
#[ApiResource(processor: MissingProcessor::class)]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new StateProviderProcessorAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $issues = $report->getIssues();
        self::assertGreaterThanOrEqual(1, count($issues));
        $issue = $issues[0];
        self::assertNotNull($issue->getFixCode());
        self::assertNotNull($issue->getDocUrl());
        self::assertNotNull($issue->getBusinessImpact());
        self::assertNotNull($issue->getEstimatedFixMinutes());
    }

    public function testModuleIsApiPlatform(): void
    {
        $analyzer = new StateProviderProcessorAnalyzer($this->tmpDir);
        self::assertSame(Module::API_PLATFORM, $analyzer->getModule());
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
