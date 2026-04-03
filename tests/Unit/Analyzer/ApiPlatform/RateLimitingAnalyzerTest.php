<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\RateLimitingAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class RateLimitingAnalyzerTest extends TestCase
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
        $analyzer = new RateLimitingAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        self::assertCount(0, $report->getIssues());
    }

    public function testSensitiveEndpointWithoutRateLimitCreatesWarning(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/LoginResource.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
#[ApiResource]
#[Post]
class LoginResource { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new RateLimitingAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $warnings = array_filter($report->getIssues(), fn ($i) => $i->getSeverity() === Severity::WARNING);
        self::assertGreaterThanOrEqual(1, count($warnings));
        $sensitive = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'sensible'),
        );
        self::assertCount(1, $sensitive);
    }

    public function testSensitiveEndpointWithRateLimitDoesNothing(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/LoginResource.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use Symfony\Component\RateLimiter\RateLimiterFactory;
#[ApiResource]
#[Post]
class LoginResource { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new RateLimitingAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        self::assertCount(0, $report->getIssues());
    }

    public function testRegistrationEndpointDetected(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/RegistrationResource.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
#[ApiResource]
#[Post]
class RegistrationResource { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new RateLimitingAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $sensitive = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'sensible'),
        );
        self::assertCount(1, $sensitive);
    }

    public function testRegularResourceDoesNothing(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Product.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
#[ApiResource(security: "is_granted(\'ROLE_ADMIN\')")]
#[Post(security: "is_granted(\'ROLE_ADMIN\')")]
class Product { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new RateLimitingAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        self::assertCount(0, $report->getIssues());
    }

    public function testPublicPostWithoutRateLimitCreatesSuggestion(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Feedback.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
#[ApiResource]
#[Post]
class Feedback { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new RateLimitingAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $suggestions = array_filter($report->getIssues(), fn ($i) => $i->getSeverity() === Severity::SUGGESTION);
        self::assertGreaterThanOrEqual(1, count($suggestions));
    }

    public function testPostWithSecurityDoesNothing(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Order.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
#[ApiResource(security: "is_granted(\'ROLE_USER\')")]
#[Post]
class Order { private int $id; }
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new RateLimitingAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $publicPostIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'POST public'),
        );
        self::assertCount(0, $publicPostIssues);
    }

    public function testSupportsReturnsTrueWithApiPlatform(): void
    {
        $analyzer = new RateLimitingAnalyzer($this->tmpDir);
        self::assertTrue($analyzer->supports(new ProjectContext('/fake', false, false, true, false, false, false, false, false, false, false, null)));
    }

    public function testGetNameReturnsExpectedName(): void
    {
        $analyzer = new RateLimitingAnalyzer($this->tmpDir);
        self::assertSame('Rate Limiting Analyzer', $analyzer->getName());
    }

    public function testModuleIsApiPlatform(): void
    {
        $analyzer = new RateLimitingAnalyzer($this->tmpDir);
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
