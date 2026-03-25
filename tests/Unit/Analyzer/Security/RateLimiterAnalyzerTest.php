<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Security\RateLimiterAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class RateLimiterAnalyzerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sf-doctor-rate-limiter-' . uniqid();
        mkdir($this->tmpDir . '/src/Controller', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::SECURITY]);
    }

    private function createAnalyzer(?array $frameworkConfig): RateLimiterAnalyzer
    {
        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn($frameworkConfig);

        return new RateLimiterAnalyzer($this->tmpDir, $configReader);
    }

    // --- Test 1 : Login route sans rate limiter => WARNING ---

    public function testLoginRouteWithoutRateLimiterCreatesWarning(): void
    {
        file_put_contents(
            $this->tmpDir . '/src/Controller/SecurityController.php',
            '<?php
            class SecurityController {
                #[Route(\'/login\')]
                public function login() {}
            }'
        );

        $analyzer = $this->createAnalyzer(null);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('login', $warnings[0]->getMessage());
    }

    // --- Test 2 : Login route avec rate limiter => pas d'issue ---

    public function testLoginRouteWithRateLimiterNoIssue(): void
    {
        file_put_contents(
            $this->tmpDir . '/src/Controller/SecurityController.php',
            '<?php
            class SecurityController {
                #[Route(\'/login\')]
                public function login() {}
            }'
        );

        $analyzer = $this->createAnalyzer([
            'framework' => [
                'rate_limiter' => [
                    'login' => ['policy' => 'sliding_window', 'limit' => 5],
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 3 : API route sans rate limiter => SUGGESTION ---

    public function testApiRouteWithoutRateLimiterCreatesSuggestion(): void
    {
        file_put_contents(
            $this->tmpDir . '/src/Controller/ApiController.php',
            '<?php
            class ApiController {
                #[Route(\'/api/products\')]
                public function list() {}
            }'
        );

        $analyzer = $this->createAnalyzer(null);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(1, $suggestions);
        $this->assertStringContainsString('API', $suggestions[0]->getMessage());
    }

    // --- Test 4 : Pas de controleur => aucune issue ---

    public function testNoControllerNoIssue(): void
    {
        // Le dossier Controller existe mais est vide (pas de fichiers PHP)
        $analyzer = $this->createAnalyzer(null);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 5 : Route non sensible => aucune issue ---

    public function testNonSensitiveRouteNoIssue(): void
    {
        file_put_contents(
            $this->tmpDir . '/src/Controller/HomeController.php',
            '<?php
            class HomeController {
                #[Route(\'/home\')]
                public function index() {}
            }'
        );

        $analyzer = $this->createAnalyzer(null);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 6 : Les deux (login + API) sans rate limiter ---

    public function testBothLoginAndApiWithoutRateLimiter(): void
    {
        file_put_contents(
            $this->tmpDir . '/src/Controller/SecurityController.php',
            '<?php
            class SecurityController {
                #[Route(\'/login\')]
                public function login() {}
            }'
        );
        file_put_contents(
            $this->tmpDir . '/src/Controller/ApiController.php',
            '<?php
            class ApiController {
                #[Route(\'/api/users\')]
                public function users() {}
            }'
        );

        $analyzer = $this->createAnalyzer(null);
        $report = $this->createReport();
        $analyzer->analyze($report);

        // WARNING pour login + SUGGESTION pour API
        $this->assertCount(2, $report->getIssues());
        $this->assertCount(1, $report->getIssuesBySeverity(Severity::WARNING));
        $this->assertCount(1, $report->getIssuesBySeverity(Severity::SUGGESTION));
    }

    // --- Test 7 : Enrichment fields ---

    public function testEnrichmentFields(): void
    {
        file_put_contents(
            $this->tmpDir . '/src/Controller/SecurityController.php',
            '<?php
            class SecurityController {
                #[Route(\'/login\')]
                public function login() {}
            }'
        );

        $analyzer = $this->createAnalyzer(null);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $issue = $report->getIssues()[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
    }

    // --- Test 8 : getName et getModule ---

    public function testGetNameAndGetModule(): void
    {
        $analyzer = $this->createAnalyzer(null);

        $this->assertSame('Rate Limiter Analyzer', $analyzer->getName());
        $this->assertSame(Module::SECURITY, $analyzer->getModule());
    }

    // --- Test 9 : supports retourne toujours true ---

    public function testSupportsAlwaysReturnsTrue(): void
    {
        $analyzer = $this->createAnalyzer(null);
        $context = new ProjectContext(
            projectPath: '/fake/project',
            hasDoctrineOrm: false,
            hasMessenger: false,
            hasApiPlatform: false,
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
}
