<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Security\ExposedDebugEndpointsAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class ExposedDebugEndpointsAnalyzerTest extends TestCase
{
    // --- Helper : creer un analyzer avec un mock du ConfigReader ---

    /**
     * @param array<mixed>|null $securityConfig Ce que read() retournera
     */
    private function createAnalyzer(?array $securityConfig): ExposedDebugEndpointsAnalyzer
    {
        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn($securityConfig);

        return new ExposedDebugEndpointsAnalyzer($configReader);
    }

    // Helper : creer un rapport vide pour chaque test.
    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::SECURITY]);
    }

    // =============================================
    // Test 1 : Pas de fichier security.yaml
    // =============================================

    public function testNoSecurityYamlDoesNothing(): void
    {
        // Arrange : le reader retourne null (fichier inexistant)
        $analyzer = $this->createAnalyzer(null);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 2 : Pas d'access_control -> profiler et wdt non proteges
    // =============================================

    public function testNoAccessControlCreatesTwoCriticals(): void
    {
        // Arrange : security.yaml sans access_control
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'main' => [
                        'lazy' => true,
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : 2 issues CRITICAL (/_profiler et /_wdt)
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(2, $criticals);

        // Verifie que les deux endpoints sont mentionnes
        $messages = array_map(fn ($issue) => $issue->getMessage(), $criticals);
        $messagesStr = implode(' ', $messages);
        $this->assertStringContainsString('_profiler', $messagesStr);
        $this->assertStringContainsString('_wdt', $messagesStr);
    }

    // =============================================
    // Test 3 : access_control couvre /_profiler mais pas /_wdt
    // =============================================

    public function testProfilerProtectedButNotWdtCreatesOneCritical(): void
    {
        // Arrange : access_control couvre /_profiler mais pas /_wdt
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    ['path' => '^/_profiler', 'roles' => 'ROLE_ADMIN'],
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : 1 seul CRITICAL pour /_wdt non protege
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('_wdt', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 4 : access_control couvre les deux endpoints
    // =============================================

    public function testBothEndpointsProtectedDoesNothing(): void
    {
        // Arrange : access_control couvre les deux avec un pattern large
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    ['path' => '^/_profiler', 'roles' => 'ROLE_ADMIN'],
                    ['path' => '^/_wdt', 'roles' => 'ROLE_ADMIN'],
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 5 : Pattern regex large couvrant les deux endpoints
    // =============================================

    public function testBroadPatternCoversEndpoints(): void
    {
        // Arrange : un pattern regex large qui couvre _profiler et _wdt
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    ['path' => '^/_', 'roles' => 'ROLE_ADMIN'],
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue, le pattern couvre les deux chemins
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 6 : access_control vide -> deux CRITICAL
    // =============================================

    public function testEmptyAccessControlCreatesTwoCriticals(): void
    {
        // Arrange : access_control vide
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : 2 CRITICAL
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(2, $criticals);
    }

    // =============================================
    // Test 7 : Verification des champs d'enrichissement
    // =============================================

    public function testEnrichmentFields(): void
    {
        // Arrange : config qui genere des CRITICAL (pas de protection)
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : verification des champs d'enrichissement sur le premier CRITICAL
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertGreaterThanOrEqual(1, count($criticals));

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('profiler', $issue->getDocUrl() ?? '');
    }
}
