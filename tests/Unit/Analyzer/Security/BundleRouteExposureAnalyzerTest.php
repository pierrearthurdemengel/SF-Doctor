<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Security\BundleRouteExposureAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class BundleRouteExposureAnalyzerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sf-doctor-bundle-exposure-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
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

    /**
     * Cree un analyzer avec un composer.json et une configuration security.yaml configurables.
     *
     * @param array<string, string> $requirePackages  Packages dans "require"
     * @param array<mixed>|null     $securityConfig   Ce que read('security.yaml') retournera
     */
    private function createAnalyzer(array $requirePackages, ?array $securityConfig = null): BundleRouteExposureAnalyzer
    {
        // Ecrire le composer.json dans le tmpDir
        $composerData = [
            'require' => $requirePackages,
        ];
        file_put_contents($this->tmpDir . '/composer.json', json_encode($composerData));

        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn($securityConfig);

        return new BundleRouteExposureAnalyzer($this->tmpDir, $configReader);
    }

    // =============================================
    // Test 1 : EasyAdmin sans firewall => CRITICAL
    // =============================================

    public function testEasyAdminWithoutFirewallCreatesCritical(): void
    {
        // Arrange : EasyAdmin installe, pas de regle access_control sur /admin
        $analyzer = $this->createAnalyzer(
            ['easycorp/easyadmin-bundle' => '^4.0'],
            ['security' => ['access_control' => []]],
        );
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : 1 issue CRITICAL mentionnant EasyAdmin
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('EasyAdmin', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 2 : EasyAdmin avec firewall => OK
    // =============================================

    public function testEasyAdminWithFirewallNoIssue(): void
    {
        // Arrange : EasyAdmin installe avec access_control couvrant /admin
        $analyzer = $this->createAnalyzer(
            ['easycorp/easyadmin-bundle' => '^4.0'],
            [
                'security' => [
                    'access_control' => [
                        ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                    ],
                ],
            ],
        );
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 3 : SonataAdmin sans firewall => CRITICAL
    // =============================================

    public function testSonataAdminWithoutFirewallCreatesCritical(): void
    {
        // Arrange : SonataAdmin installe, pas de regle access_control sur /admin
        $analyzer = $this->createAnalyzer(
            ['sonata-project/admin-bundle' => '^4.0'],
            ['security' => ['access_control' => []]],
        );
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : 1 issue CRITICAL mentionnant Sonata
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('Sonata', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 4 : SonataAdmin avec firewall => OK
    // =============================================

    public function testSonataAdminWithFirewallNoIssue(): void
    {
        // Arrange : SonataAdmin installe avec access_control couvrant /admin
        $analyzer = $this->createAnalyzer(
            ['sonata-project/admin-bundle' => '^4.0'],
            [
                'security' => [
                    'access_control' => [
                        ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                    ],
                ],
            ],
        );
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 5 : Pas de bundle admin installe => OK
    // =============================================

    public function testNoAdminBundlesNoIssue(): void
    {
        // Arrange : projet sans bundle admin ni API Platform
        $analyzer = $this->createAnalyzer(
            ['symfony/framework-bundle' => '^6.4'],
            ['security' => ['access_control' => []]],
        );
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 6 : API Platform /api/docs sans restriction => WARNING
    // =============================================

    public function testApiPlatformDocsWithoutRestrictionCreatesWarning(): void
    {
        // Arrange : API Platform installe sans protection sur /api/docs
        $analyzer = $this->createAnalyzer(
            ['api-platform/core' => '^3.0'],
            ['security' => ['access_control' => []]],
        );
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : 1 issue WARNING mentionnant /api/docs
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('/api/docs', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 7 : API Platform /api/docs avec restriction => OK
    // =============================================

    public function testApiPlatformDocsWithRestrictionNoIssue(): void
    {
        // Arrange : API Platform installe avec access_control sur /api/docs
        $analyzer = $this->createAnalyzer(
            ['api-platform/core' => '^3.0'],
            [
                'security' => [
                    'access_control' => [
                        ['path' => '^/api/docs', 'roles' => 'ROLE_ADMIN'],
                    ],
                ],
            ],
        );
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 8 : Verification des champs d'enrichissement
    // =============================================

    public function testEnrichmentFields(): void
    {
        // Arrange : EasyAdmin sans protection pour generer un CRITICAL
        $analyzer = $this->createAnalyzer(
            ['easycorp/easyadmin-bundle' => '^4.0'],
            ['security' => ['access_control' => []]],
        );
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : verification des champs d'enrichissement sur l'issue CRITICAL
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('EasyAdmin', $issue->getDocUrl() ?? '');
    }

    // =============================================
    // Test 9 : getName et getModule
    // =============================================

    public function testGetNameAndGetModule(): void
    {
        $analyzer = $this->createAnalyzer([]);

        $this->assertSame('Bundle Route Exposure Analyzer', $analyzer->getName());
        $this->assertSame(Module::SECURITY, $analyzer->getModule());
    }

    // =============================================
    // Test 10 : supports() retourne toujours true
    // =============================================

    public function testSupportsAlwaysReturnsTrue(): void
    {
        $analyzer = $this->createAnalyzer([]);
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

    // =============================================
    // Test 11 : Pas de composer.json => aucune issue
    // =============================================

    public function testNoComposerJsonNoIssue(): void
    {
        // Arrange : supprimer le composer.json cree par createAnalyzer
        // On cree le mock manuellement pour eviter l'ecriture du fichier
        $tmpDir = sys_get_temp_dir() . '/sf-doctor-no-composer-' . uniqid();
        mkdir($tmpDir, 0777, true);

        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn(null);

        $analyzer = new BundleRouteExposureAnalyzer($tmpDir, $configReader);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue (pas de composer.json)
        $this->assertCount(0, $report->getIssues());

        // Cleanup
        $this->removeDirectory($tmpDir);
    }

    // =============================================
    // Test 12 : EasyAdmin + SonataAdmin sans firewall => 2 CRITICAL
    // =============================================

    public function testBothAdminBundlesWithoutFirewallCreatesTwoCriticals(): void
    {
        // Arrange : les deux bundles admin installes sans protection
        $analyzer = $this->createAnalyzer(
            [
                'easycorp/easyadmin-bundle' => '^4.0',
                'sonata-project/admin-bundle' => '^4.0',
            ],
            ['security' => ['access_control' => []]],
        );
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : 2 issues CRITICAL (une pour chaque bundle)
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(2, $criticals);

        $messages = array_map(fn ($issue) => $issue->getMessage(), $criticals);
        $messagesStr = implode(' ', $messages);
        $this->assertStringContainsString('EasyAdmin', $messagesStr);
        $this->assertStringContainsString('Sonata', $messagesStr);
    }
}
