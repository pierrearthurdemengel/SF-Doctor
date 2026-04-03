<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\ApiPlatform;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\ApiPlatform\DeprecatedConfigKeyAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class DeprecatedConfigKeyAnalyzerTest extends TestCase
{
    public function testNoConfigDoesNothing(): void
    {
        $reader = $this->createConfigReader(null);
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new DeprecatedConfigKeyAnalyzer($reader);
        $analyzer->analyze($report);
        self::assertCount(0, $report->getIssues());
    }

    public function testNoApiPlatformKeyDoesNothing(): void
    {
        $reader = $this->createConfigReader(['framework' => ['test' => true]]);
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new DeprecatedConfigKeyAnalyzer($reader);
        $analyzer->analyze($report);
        self::assertCount(0, $report->getIssues());
    }

    public function testDeprecatedCollectionKeyCreatesWarning(): void
    {
        $reader = $this->createConfigReader([
            'api_platform' => [
                'collection' => ['pagination' => ['enabled' => true]],
            ],
        ]);
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new DeprecatedConfigKeyAnalyzer($reader);
        $analyzer->analyze($report);
        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertSame(Severity::WARNING, $issues[0]->getSeverity());
        self::assertStringContainsString('collection', $issues[0]->getMessage());
    }

    public function testDeprecatedItemOperationsCreatesWarning(): void
    {
        $reader = $this->createConfigReader([
            'api_platform' => [
                'item_operations' => ['get' => []],
            ],
        ]);
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new DeprecatedConfigKeyAnalyzer($reader);
        $analyzer->analyze($report);
        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertSame(Severity::WARNING, $issues[0]->getSeverity());
        self::assertStringContainsString('item_operations', $issues[0]->getMessage());
    }

    public function testDeprecatedExceptionToStatusCreatesWarning(): void
    {
        $reader = $this->createConfigReader([
            'api_platform' => [
                'exception_to_status' => ['App\Exception\NotFound' => 404],
            ],
        ]);
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new DeprecatedConfigKeyAnalyzer($reader);
        $analyzer->analyze($report);
        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertSame(Severity::WARNING, $issues[0]->getSeverity());
        self::assertStringContainsString('exception_to_status', $issues[0]->getMessage());
    }

    public function testEnableDocsCreatesSuggestion(): void
    {
        $reader = $this->createConfigReader([
            'api_platform' => [
                'enable_docs' => true,
            ],
        ]);
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new DeprecatedConfigKeyAnalyzer($reader);
        $analyzer->analyze($report);
        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertSame(Severity::SUGGESTION, $issues[0]->getSeverity());
        self::assertStringContainsString('enable_docs', $issues[0]->getMessage());
    }

    public function testEnableDocsFalseDoesNothing(): void
    {
        $reader = $this->createConfigReader([
            'api_platform' => [
                'enable_docs' => false,
            ],
        ]);
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new DeprecatedConfigKeyAnalyzer($reader);
        $analyzer->analyze($report);
        self::assertCount(0, $report->getIssues());
    }

    public function testSwaggerVersion2CreatesWarning(): void
    {
        $reader = $this->createConfigReader([
            'api_platform' => [
                'swagger' => ['versions' => [2, 3]],
            ],
        ]);
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new DeprecatedConfigKeyAnalyzer($reader);
        $analyzer->analyze($report);
        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertSame(Severity::WARNING, $issues[0]->getSeverity());
        self::assertStringContainsString('Swagger 2', $issues[0]->getMessage());
    }

    public function testSwaggerVersion3OnlyDoesNothing(): void
    {
        $reader = $this->createConfigReader([
            'api_platform' => [
                'swagger' => ['versions' => [3]],
            ],
        ]);
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new DeprecatedConfigKeyAnalyzer($reader);
        $analyzer->analyze($report);
        self::assertCount(0, $report->getIssues());
    }

    public function testCleanConfigDoesNothing(): void
    {
        $reader = $this->createConfigReader([
            'api_platform' => [
                'title' => 'My API',
                'formats' => ['jsonld' => ['application/ld+json']],
            ],
        ]);
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new DeprecatedConfigKeyAnalyzer($reader);
        $analyzer->analyze($report);
        self::assertCount(0, $report->getIssues());
    }

    public function testSupportsReturnsTrueWithApiPlatform(): void
    {
        $reader = $this->createConfigReader(null);
        $analyzer = new DeprecatedConfigKeyAnalyzer($reader);
        self::assertTrue($analyzer->supports(new ProjectContext('/fake', false, false, true, false, false, false, false, false, false, false, null)));
    }

    public function testGetNameReturnsExpectedName(): void
    {
        $reader = $this->createConfigReader(null);
        $analyzer = new DeprecatedConfigKeyAnalyzer($reader);
        self::assertSame('Deprecated Config Key Analyzer', $analyzer->getName());
    }

    public function testModuleIsApiPlatform(): void
    {
        $reader = $this->createConfigReader(null);
        $analyzer = new DeprecatedConfigKeyAnalyzer($reader);
        self::assertSame(Module::API_PLATFORM, $analyzer->getModule());
    }

    public function testEnrichmentFieldsOnDeprecatedKey(): void
    {
        $reader = $this->createConfigReader([
            'api_platform' => [
                'collection' => ['pagination' => ['enabled' => true]],
            ],
        ]);
        $report = new AuditReport('/fake/project', [Module::API_PLATFORM]);
        $analyzer = new DeprecatedConfigKeyAnalyzer($reader);
        $analyzer->analyze($report);
        $issues = $report->getIssues();
        self::assertCount(1, $issues);
        self::assertNotNull($issues[0]->getFixCode());
        self::assertNotNull($issues[0]->getDocUrl());
        self::assertNotNull($issues[0]->getBusinessImpact());
        self::assertNotNull($issues[0]->getEstimatedFixMinutes());
    }

    private function createConfigReader(?array $config): ConfigReaderInterface
    {
        return new class ($config) implements ConfigReaderInterface {
            public function __construct(private readonly ?array $config)
            {
            }

            public function read(string $path): ?array
            {
                return $this->config;
            }

            public function exists(string $path): bool
            {
                return $this->config !== null;
            }
        };
    }
}
