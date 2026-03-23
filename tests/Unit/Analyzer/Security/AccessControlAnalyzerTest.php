<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Security\AccessControlAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Config\NullParameterResolver;


class AccessControlAnalyzerTest extends TestCase
{
    /**
     * @param array<mixed>|null $securityConfig
     */
    private function createAnalyzer(?array $securityConfig): AccessControlAnalyzer
    {
        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn($securityConfig);

        return new AccessControlAnalyzer($configReader, new NullParameterResolver());
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::SECURITY]);
    }

    // =============================================
    // Cas de sortie anticipée (early return)
    // =============================================

    public function testMissingSecurityYamlProducesNoIssue(): void
    {
        // Le FirewallAnalyzer gère déjà ce cas.
        // L'AccessControlAnalyzer ne doit pas dupliquer l'alerte.
        $analyzer = $this->createAnalyzer(null);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    public function testEmptyAccessControlProducesNoIssue(): void
    {
        // Pareil : c'est le FirewallAnalyzer qui gère l'absence d'access_control.
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => ['main' => ['lazy' => true]],
                // Pas d'access_control
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Check 1 : Règle sans rôle
    // =============================================

    public function testRuleWithoutRolesCreatesWarning(): void
    {
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    // Pas de clé "roles" du tout
                    ['path' => '^/admin'],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('sans rôle', $warnings[0]->getMessage());
    }

    public function testRuleWithEmptyArrayRolesCreatesWarning(): void
    {
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    ['path' => '^/admin', 'roles' => []],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('vide', $warnings[0]->getMessage());
    }

    public function testRuleWithEmptyStringRolesCreatesWarning(): void
    {
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    ['path' => '^/admin', 'roles' => ''],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('vide', $warnings[0]->getMessage());
    }

    // =============================================
    // Check 2 : Rôles dépréciés
    // =============================================

    public function testDeprecatedAnonymousRoleCreatesWarning(): void
    {
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    [
                        'path' => '^/public',
                        'roles' => 'IS_AUTHENTICATED_ANONYMOUSLY',
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        // On cherche le warning spécifique au rôle déprécié.
        // Il peut y avoir d'autres warnings (ex: sensitive paths),
        // donc on vérifie qu'au moins un contient "déprécié".
        $deprecatedWarnings = array_filter(
            $warnings,
            fn ($issue) => str_contains($issue->getMessage(), 'déprécié'),
        );
        $this->assertCount(1, $deprecatedWarnings);
    }

    public function testRemovedAnonymousRoleCreatesWarning(): void
    {
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    ['path' => '^/public', 'roles' => 'ROLE_ANONYMOUS'],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $removedWarnings = array_filter(
            $warnings,
            fn ($issue) => str_contains($issue->getMessage(), 'supprimé'),
        );
        $this->assertCount(1, $removedWarnings);
    }

    public function testDeprecatedRoleInArrayFormat(): void
    {
        // Les rôles peuvent aussi être un tableau en YAML :
        // roles: [IS_AUTHENTICATED_ANONYMOUSLY]
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    [
                        'path' => '^/public',
                        'roles' => ['IS_AUTHENTICATED_ANONYMOUSLY'],
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $deprecatedWarnings = array_filter(
            $warnings,
            fn ($issue) => str_contains($issue->getMessage(), 'déprécié'),
        );
        $this->assertCount(1, $deprecatedWarnings);
    }

    // =============================================
    // Check 3 : Ordre des règles attrape-tout
    // =============================================

    public function testCatchAllRuleNotLastCreatesCritical(): void
    {
        // ^/ matche tout. Placé en premier, il rend la règle /admin inaccessible.
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    ['path' => '^/', 'roles' => 'ROLE_USER'],
                    ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('attrape-tout', $criticals[0]->getMessage());
    }

    public function testCatchAllRuleLastPositionNoCritical(): void
    {
        // ^/ en dernière position → c'est correct.
        // Les règles spécifiques sont évaluées en premier.
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                    ['path' => '^/', 'roles' => 'ROLE_USER'],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(0, $criticals);
    }

    public function testCatchAllWithDotStarPatternCreatesCritical(): void
    {
        // ^.* est un autre pattern attrape-tout.
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    ['path' => '^.*', 'roles' => 'ROLE_USER'],
                    ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
    }

    public function testSingleRuleNoCatchAllProblem(): void
    {
        // Une seule règle → pas de problème d'ordre possible.
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    ['path' => '^/', 'roles' => 'ROLE_USER'],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(0, $criticals);
    }

    // =============================================
    // Check 4 : Chemins sensibles non couverts
    // =============================================

    public function testUncoveredAdminPathCreatesSuggestion(): void
    {
        // access_control ne couvre que /profile, pas /admin.
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    ['path' => '^/profile', 'roles' => 'ROLE_USER'],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $adminSuggestions = array_filter(
            $suggestions,
            fn ($issue) => str_contains($issue->getMessage(), '/admin'),
        );
        $this->assertCount(1, $adminSuggestions);
    }

    public function testUncoveredApiPathCreatesSuggestion(): void
    {
        // access_control ne couvre que /admin, pas /api.
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $apiSuggestions = array_filter(
            $suggestions,
            fn ($issue) => str_contains($issue->getMessage(), '/api'),
        );
        $this->assertCount(1, $apiSuggestions);
    }

    public function testCoveredSensitivePathsNoSuggestion(): void
    {
        // Les deux chemins sensibles sont couverts.
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                    ['path' => '^/api', 'roles' => 'ROLE_API'],
                    ['path' => '^/', 'roles' => 'ROLE_USER'],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(0, $suggestions);
    }

    public function testCatchAllRuleCoversAllSensitivePaths(): void
    {
        // Une règle ^/ matche tout, donc /admin et /api sont couverts.
        // Pas de suggestion pour les chemins sensibles.
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    ['path' => '^/', 'roles' => 'ROLE_USER'],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(0, $suggestions);
    }

    // =============================================
    // Config complète et correcte
    // =============================================

    public function testProperConfigHasNoCriticalOrWarning(): void
    {
        // Le projet parfait : règles spécifiques d'abord,
        // attrape-tout en dernier, rôles corrects, chemins sensibles couverts.
        $analyzer = $this->createAnalyzer([
            'security' => [
                'access_control' => [
                    ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                    ['path' => '^/api', 'roles' => 'ROLE_API'],
                    ['path' => '^/profile', 'roles' => 'ROLE_USER'],
                    ['path' => '^/', 'roles' => 'IS_AUTHENTICATED_FULLY'],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::CRITICAL));
        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));
        $this->assertCount(0, $report->getIssuesBySeverity(Severity::SUGGESTION));
    }

    // =============================================
    // Métadonnées de l'analyzer
    // =============================================

    public function testGetModuleReturnsSecurity(): void
    {
        $analyzer = $this->createAnalyzer(null);
        $this->assertSame(Module::SECURITY, $analyzer->getModule());
    }

    public function testGetNameReturnsReadableName(): void
    {
        $analyzer = $this->createAnalyzer(null);
        $this->assertSame('Access Control Analyzer', $analyzer->getName());
    }
}