<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\ValidationAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class ValidationAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_validation_' . uniqid();
        mkdir($this->tempDir . '/src/Entity', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::API_PLATFORM]);
    }

    // --- Test 1 : Resource avec POST sans Assert => WARNING ---

    public function testPostWithoutValidationCreatesWarning(): void
    {
        file_put_contents(
            $this->tempDir . '/src/Entity/Product.php',
            '<?php
            use ApiPlatform\Metadata\ApiResource;
            use ApiPlatform\Metadata\Post;

            #[ApiResource]
            #[Post]
            class Product {
                private string $name;
            }'
        );

        $analyzer = new ValidationAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertGreaterThanOrEqual(1, count($warnings));
        $this->assertStringContainsString('Product', $warnings[0]->getMessage());
    }

    // --- Test 2 : Resource avec POST et Assert => OK ---

    public function testPostWithValidationNoIssue(): void
    {
        file_put_contents(
            $this->tempDir . '/src/Entity/Product.php',
            '<?php
            use ApiPlatform\Metadata\ApiResource;
            use ApiPlatform\Metadata\Post;
            use Symfony\Component\Validator\Constraints as Assert;

            #[ApiResource]
            #[Post]
            class Product {
                #[Assert\NotBlank]
                private string $name;
            }'
        );

        $analyzer = new ValidationAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        // Should not have the "no validation" warning
        $writeWarnings = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'ecriture sans validation'),
        );
        $this->assertCount(0, $writeWarnings);
    }

    // --- Test 3 : Pas de dossier Entity => OK ---

    public function testNoEntityDirDoesNothing(): void
    {
        $analyzer = new ValidationAnalyzer($this->tempDir . '/nonexistent');
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 4 : Fichier sans ApiResource => ignore ---

    public function testNonApiResourceFileIgnored(): void
    {
        file_put_contents(
            $this->tempDir . '/src/Entity/BaseEntity.php',
            '<?php
            class BaseEntity {
                private string $name;
            }'
        );

        $analyzer = new ValidationAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 5 : Colonne NOT NULL sans Assert\NotBlank => WARNING ---

    public function testNotNullColumnWithoutNotBlankCreatesWarning(): void
    {
        file_put_contents(
            $this->tempDir . '/src/Entity/User.php',
            '<?php
            use ApiPlatform\Metadata\ApiResource;
            use ApiPlatform\Metadata\Post;
            use Doctrine\ORM\Mapping as ORM;
            use Symfony\Component\Validator\Constraints as Assert;

            #[ApiResource]
            #[Post]
            class User {
                #[ORM\Column(type: "string")]
                private string $email;

                #[Assert\NotBlank]
                #[ORM\Column(type: "string")]
                private string $name;
            }'
        );

        $analyzer = new ValidationAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        // email should have a warning (NOT NULL without NotBlank)
        $notBlankWarnings = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'NotBlank'),
        );
        $this->assertCount(1, $notBlankWarnings);
        $this->assertStringContainsString('email', array_values($notBlankWarnings)[0]->getMessage());
    }

    // --- Test 6 : Colonne nullable => pas de warning NotBlank ---

    public function testNullableColumnNoNotBlankWarning(): void
    {
        file_put_contents(
            $this->tempDir . '/src/Entity/Post.php',
            '<?php
            use ApiPlatform\Metadata\ApiResource;
            use ApiPlatform\Metadata\Post as PostOp;
            use Doctrine\ORM\Mapping as ORM;
            use Symfony\Component\Validator\Constraints as Assert;

            #[ApiResource]
            #[PostOp]
            class Post {
                #[Assert\NotBlank]
                #[ORM\Column(type: "string")]
                private string $title;

                #[ORM\Column(type: "string", nullable: true)]
                private ?string $subtitle;
            }'
        );

        $analyzer = new ValidationAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $notBlankWarnings = array_filter(
            $report->getIssues(),
            fn ($i) => str_contains($i->getMessage(), 'subtitle'),
        );
        $this->assertCount(0, $notBlankWarnings);
    }

    // --- Test 7 : getName et getModule ---

    public function testGetNameAndGetModule(): void
    {
        $analyzer = new ValidationAnalyzer($this->tempDir);

        $this->assertSame('API Platform Validation Analyzer', $analyzer->getName());
        $this->assertSame(Module::API_PLATFORM, $analyzer->getModule());
    }

    // --- Test 8 : supports retourne true si API Platform present ---

    public function testSupportsWithApiPlatform(): void
    {
        $analyzer = new ValidationAnalyzer($this->tempDir);
        $context = new ProjectContext(
            projectPath: '/fake',
            hasDoctrineOrm: false,
            hasMessenger: false,
            hasApiPlatform: true,
            hasTwig: false,
            hasSecurityBundle: false,
            hasWebProfilerBundle: false,
            hasMailer: false,
            hasNelmioCors: false,
            hasNelmioSecurity: false,
            hasJwtAuth: false,
            symfonyVersion: null,
        );
        $this->assertTrue($analyzer->supports($context));
    }

    // --- Test 9 : Enrichment fields ---

    public function testEnrichmentFields(): void
    {
        file_put_contents(
            $this->tempDir . '/src/Entity/Category.php',
            '<?php
            use ApiPlatform\Metadata\ApiResource;
            use ApiPlatform\Metadata\Post;

            #[ApiResource]
            #[Post]
            class Category {
                private string $name;
            }'
        );

        $analyzer = new ValidationAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertGreaterThanOrEqual(1, count($report->getIssues()));
        $issue = $report->getIssues()[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
