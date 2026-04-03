<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\ResourceNamingAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class ResourceNamingAnalyzerTest extends TestCase
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
        $analyzer = new ResourceNamingAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        self::assertCount(0, $report->getIssues());
    }

    public function testKebabCaseShortNameDoesNothing(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/BlogPost.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
#[ApiResource(shortName: \'blog-posts\')]
class BlogPost { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new ResourceNamingAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $shortNameIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'shortName'),
        );
        self::assertCount(0, $shortNameIssues);
    }

    public function testPascalCaseShortNameCreatesSuggestion(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/BlogPost.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
#[ApiResource(shortName: \'BlogPosts\')]
class BlogPost { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new ResourceNamingAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $shortNameIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'shortName'),
        );
        self::assertCount(1, $shortNameIssues);
        self::assertSame(Severity::SUGGESTION, array_values($shortNameIssues)[0]->getSeverity());
    }

    public function testUnderscoreShortNameCreatesSuggestion(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/BlogPost.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
#[ApiResource(shortName: \'blog_posts\')]
class BlogPost { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new ResourceNamingAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $shortNameIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'shortName'),
        );
        self::assertCount(1, $shortNameIssues);
    }

    public function testVerbPrefixInClassNameCreatesSuggestion(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/CreateUser.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
#[ApiResource]
class CreateUser { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new ResourceNamingAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $verbIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'verbe'),
        );
        self::assertCount(1, $verbIssues);
        self::assertSame(Severity::SUGGESTION, array_values($verbIssues)[0]->getSeverity());
    }

    public function testRegularClassNameDoesNothing(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
#[ApiResource]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new ResourceNamingAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $verbIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'verbe'),
        );
        self::assertCount(0, $verbIssues);
    }

    public function testRoutePrefixWithUnderscoreCreatesSuggestion(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
#[ApiResource(routePrefix: \'/api/my_resources\')]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new ResourceNamingAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $routeIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'routePrefix'),
        );
        self::assertCount(1, $routeIssues);
    }

    public function testRoutePrefixKebabCaseDoesNothing(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
#[ApiResource(routePrefix: \'/api/my-resources\')]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new ResourceNamingAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $routeIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'routePrefix'),
        );
        self::assertCount(0, $routeIssues);
    }

    public function testSupportsReturnsTrueWithApiPlatform(): void
    {
        $analyzer = new ResourceNamingAnalyzer($this->tmpDir);
        self::assertTrue($analyzer->supports(new ProjectContext('/fake', false, false, true, false, false, false, false, false, false, false, null)));
    }

    public function testGetNameReturnsExpectedName(): void
    {
        $analyzer = new ResourceNamingAnalyzer($this->tmpDir);
        self::assertSame('Resource Naming Analyzer', $analyzer->getName());
    }

    public function testModuleIsApiPlatform(): void
    {
        $analyzer = new ResourceNamingAnalyzer($this->tmpDir);
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
