<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Security\HttpsAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class HttpsAnalyzerTest extends TestCase
{
    // --- Helper : creer un analyzer avec controle sur les deux fichiers de config ---

    /**
     * Cree un HttpsAnalyzer avec un mock qui retourne des configs differentes
     * selon le fichier demande (security.yaml ou framework.yaml).
     *
     * @param array<mixed>|null $securityConfig Config de security.yaml
     * @param array<mixed>|null $frameworkConfig Config de framework.yaml
     */
    private function createAnalyzer(?array $securityConfig, ?array $frameworkConfig = null): HttpsAnalyzer
    {
        $configReader = $this->createMock(ConfigReaderInterface::class);

        // Le mock retourne des configs differentes selon le chemin du fichier.
        $configReader->method('read')->willReturnCallback(
            function (string $path) use ($securityConfig, $frameworkConfig) {
                if ($path === 'config/packages/security.yaml') {
                    return $securityConfig;
                }
                if ($path === 'config/packages/framework.yaml') {
                    return $frameworkConfig;
                }
                return null;
            }
        );

        return new HttpsAnalyzer($configReader);
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
        // Arrange : security.yaml absent, framework.yaml avec cookie_secure: auto
        $analyzer = $this->createAnalyzer(null, [
            'framework' => [
                'session' => [
                    'cookie_secure' => 'auto',
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : pas de WARNING pour access_control (security.yaml absent)
        $warnings = array_filter(
            $report->getIssuesBySeverity(Severity::WARNING),
            fn ($issue) => str_contains($issue->getMessage(), 'access_control')
        );
        $this->assertCount(0, $warnings);
    }

    // =============================================
    // Test 2 : Pas d'access_control du tout
    // =============================================

    public function testNoAccessControlDoesNothing(): void
    {
        // Arrange : security.yaml sans access_control
        $analyzer = $this->createAnalyzer(
            ['security' => ['firewalls' => ['main' => []]]],
            ['framework' => ['session' => ['cookie_secure' => 'auto']]]
        );
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : pas de WARNING pour access_control vide
        $warnings = array_filter(
            $report->getIssuesBySeverity(Severity::WARNING),
            fn ($issue) => str_contains($issue->getMessage(), 'HTTPS')
        );
        $this->assertCount(0, $warnings);
    }

    // =============================================
    // Test 3 : access_control sans requires_channel: https
    // =============================================

    public function testAccessControlWithoutHttpsCreatesWarning(): void
    {
        // Arrange : access_control sans requires_channel
        $analyzer = $this->createAnalyzer(
            [
                'security' => [
                    'access_control' => [
                        ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                        ['path' => '^/profile', 'roles' => 'ROLE_USER'],
                    ],
                ],
            ],
            ['framework' => ['session' => ['cookie_secure' => 'auto']]]
        );
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : un WARNING pour l'absence de HTTPS
        $warnings = array_filter(
            $report->getIssuesBySeverity(Severity::WARNING),
            fn ($issue) => str_contains($issue->getMessage(), 'HTTPS')
        );
        $this->assertCount(1, $warnings);
    }

    // =============================================
    // Test 4 : access_control avec requires_channel: https
    // =============================================

    public function testAccessControlWithHttpsDoesNothing(): void
    {
        // Arrange : au moins une regle avec requires_channel: https
        $analyzer = $this->createAnalyzer(
            [
                'security' => [
                    'access_control' => [
                        ['path' => '^/admin', 'roles' => 'ROLE_ADMIN', 'requires_channel' => 'https'],
                        ['path' => '^/profile', 'roles' => 'ROLE_USER'],
                    ],
                ],
            ],
            ['framework' => ['session' => ['cookie_secure' => 'auto']]]
        );
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : pas de WARNING pour access_control (au moins une regle HTTPS)
        $warnings = array_filter(
            $report->getIssuesBySeverity(Severity::WARNING),
            fn ($issue) => str_contains($issue->getMessage(), 'HTTPS')
        );
        $this->assertCount(0, $warnings);
    }

    // =============================================
    // Test 5 : cookie_secure: auto, pas de probleme
    // =============================================

    public function testCookieSecureAutoDoesNothing(): void
    {
        // Arrange : cookie_secure a 'auto' (detection automatique)
        $analyzer = $this->createAnalyzer(
            null,
            ['framework' => ['session' => ['cookie_secure' => 'auto']]]
        );
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : pas de WARNING pour cookie_secure
        $warnings = array_filter(
            $report->getIssuesBySeverity(Severity::WARNING),
            fn ($issue) => str_contains($issue->getMessage(), 'cookie')
        );
        $this->assertCount(0, $warnings);
    }

    // =============================================
    // Test 6 : cookie_secure absent -> WARNING
    // =============================================

    public function testCookieSecureMissingCreatesWarning(): void
    {
        // Arrange : framework.yaml sans cookie_secure
        $analyzer = $this->createAnalyzer(
            null,
            ['framework' => ['session' => ['handler_id' => null]]]
        );
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : un WARNING pour le cookie non securise
        $warnings = array_filter(
            $report->getIssuesBySeverity(Severity::WARNING),
            fn ($issue) => str_contains($issue->getMessage(), 'cookie') || str_contains($issue->getMessage(), 'Cookie')
        );
        $this->assertCount(1, $warnings);
    }

    // =============================================
    // Test 7 : Verification des champs d'enrichissement
    // =============================================

    public function testEnrichmentFields(): void
    {
        // Arrange : config qui genere un WARNING (access_control sans HTTPS)
        $analyzer = $this->createAnalyzer(
            [
                'security' => [
                    'access_control' => [
                        ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                    ],
                ],
            ],
            ['framework' => ['session' => ['cookie_secure' => 'auto']]]
        );
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : verification des champs d'enrichissement
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertGreaterThanOrEqual(1, count($warnings));

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('access_control', $issue->getDocUrl() ?? '');
    }
}
