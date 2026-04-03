<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\CircularReferenceAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class CircularReferenceAnalyzerTest extends TestCase
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
        $analyzer = new CircularReferenceAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        self::assertCount(0, $report->getIssues());
    }

    public function testBidirectionalWithMaxDepthDoesNothing(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Category.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\MaxDepth;

#[ApiResource]
class Category {
    #[ORM\OneToMany(mappedBy: "category", targetEntity: Product::class)]
    #[Groups(["read"])]
    #[MaxDepth(1)]
    private $products;
}
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new CircularReferenceAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $bidirIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'bidirectionnelle'),
        );
        self::assertCount(0, $bidirIssues);
    }

    public function testBidirectionalWithoutProtectionCreatesWarning(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Category.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource]
class Category {
    #[ORM\OneToMany(mappedBy: "category", targetEntity: Product::class)]
    #[Groups(["read"])]
    private $products;
}
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new CircularReferenceAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $warnings = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'bidirectionnelle'),
        );
        self::assertCount(1, $warnings);
        self::assertSame(Severity::WARNING, array_values($warnings)[0]->getSeverity());
    }

    public function testBidirectionalWithIgnoreDoesNothing(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Category.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\Ignore;

#[ApiResource]
class Category {
    #[ORM\OneToMany(mappedBy: "category", targetEntity: Product::class)]
    #[Groups(["read"])]
    #[Ignore]
    private $products;
}
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new CircularReferenceAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $bidirIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'bidirectionnelle'),
        );
        self::assertCount(0, $bidirIssues);
    }

    public function testBidirectionalWithEnableMaxDepthDoesNothing(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Category.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource(normalizationContext: ["groups" => ["read"], "enable_max_depth" => true])]
class Category {
    #[ORM\OneToMany(mappedBy: "category", targetEntity: Product::class)]
    #[Groups(["read"])]
    private $products;
}
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new CircularReferenceAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $bidirIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'bidirectionnelle'),
        );
        self::assertCount(0, $bidirIssues);
    }

    public function testMaxDepthWithoutEnableMaxDepthCreatesWarning(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Category.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\MaxDepth;

#[ApiResource(normalizationContext: ["groups" => ["read"]])]
class Category {
    #[ORM\OneToMany(mappedBy: "category", targetEntity: Product::class)]
    #[Groups(["read"])]
    #[MaxDepth(1)]
    private $products;
}
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new CircularReferenceAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $maxDepthIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'enable_max_depth'),
        );
        self::assertCount(1, $maxDepthIssues);
        self::assertSame(Severity::WARNING, array_values($maxDepthIssues)[0]->getSeverity());
    }

    public function testMaxDepthWithEnableMaxDepthDoesNothing(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Category.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\MaxDepth;

#[ApiResource(normalizationContext: ["groups" => ["read"], "enable_max_depth" => true])]
class Category {
    #[ORM\OneToMany(mappedBy: "category", targetEntity: Product::class)]
    #[Groups(["read"])]
    #[MaxDepth(1)]
    private $products;
}
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new CircularReferenceAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $maxDepthIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'enable_max_depth'),
        );
        self::assertCount(0, $maxDepthIssues);
    }

    public function testBidirectionalWithoutGroupsDoesNothing(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Category.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;

#[ApiResource]
class Category {
    #[ORM\OneToMany(mappedBy: "category", targetEntity: Product::class)]
    private $products;
}
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new CircularReferenceAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $bidirIssues = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'bidirectionnelle'),
        );
        self::assertCount(0, $bidirIssues);
    }

    public function testSupportsReturnsTrueWithApiPlatform(): void
    {
        $analyzer = new CircularReferenceAnalyzer($this->tmpDir);
        self::assertTrue($analyzer->supports(new ProjectContext('/fake', false, false, true, false, false, false, false, false, false, false, null)));
    }

    public function testGetNameReturnsExpectedName(): void
    {
        $analyzer = new CircularReferenceAnalyzer($this->tmpDir);
        self::assertSame('Circular Reference Analyzer', $analyzer->getName());
    }

    public function testModuleIsApiPlatform(): void
    {
        $analyzer = new CircularReferenceAnalyzer($this->tmpDir);
        self::assertSame(Module::API_PLATFORM, $analyzer->getModule());
    }

    public function testEnrichmentFieldsOnWarning(): void
    {
        file_put_contents($this->tmpDir . '/src/Entity/Category.php', '<?php
namespace App\Entity;
use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource]
class Category {
    #[ORM\OneToMany(mappedBy: "category", targetEntity: Product::class)]
    #[Groups(["read"])]
    private $products;
}
');
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new CircularReferenceAnalyzer($this->tmpDir);
        $analyzer->analyze($report);
        $warnings = array_values(array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'bidirectionnelle'),
        ));
        self::assertCount(1, $warnings);
        self::assertNotNull($warnings[0]->getFixCode());
        self::assertNotNull($warnings[0]->getDocUrl());
        self::assertNotNull($warnings[0]->getBusinessImpact());
        self::assertNotNull($warnings[0]->getEstimatedFixMinutes());
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
