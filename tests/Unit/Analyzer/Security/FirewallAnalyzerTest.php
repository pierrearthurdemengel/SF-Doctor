<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Config\NullParameterResolver;
use PierreArthur\SfDoctor\Analyzer\Security\FirewallAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class FirewallAnalyzerTest extends TestCase
{
    // --- Helper : créer un analyzer avec un mock du ConfigReader ---

    // Cette méthode crée un FirewallAnalyzer dont le ConfigReader
    // retourne exactement les données qu'on lui donne.
    // C'est la clé de tout : on contrôle l'entrée pour vérifier la sortie.

    /**
     * @param array<mixed>|null $securityConfig Ce que read() retournera
     */
    private function createAnalyzer(?array $securityConfig): FirewallAnalyzer
    {
        // createMock() est une méthode fournie par TestCase.
        // Elle crée un FAUX objet qui implémente ConfigReaderInterface.
        // Ce faux objet ne lit aucun fichier.
        $configReader = $this->createMock(ConfigReaderInterface::class);

        // method('read') → quand quelqu'un appelle ->read(...)
        // willReturn($securityConfig) → retourne cette valeur
        //
        // Peu importe le chemin passé à read(), le mock retournera
        // toujours $securityConfig. C'est suffisant pour nos tests :
        // le FirewallAnalyzer appelle read('config/packages/security.yaml'),
        // et on contrôle ce qu'il reçoit.
        $configReader->method('read')->willReturn($securityConfig);

        return new FirewallAnalyzer($configReader, new NullParameterResolver());
    }

    // Helper : créer un rapport vide pour chaque test.
    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::SECURITY]);
    }

    // =============================================
    // Test 1 : Fichier security.yaml introuvable
    // =============================================

    public function testMissingSecurityYamlCreatesWarning(): void
    {
        // Arrange : le reader retourne null (fichier inexistant)
        $analyzer = $this->createAnalyzer(null);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : on doit avoir exactement 1 issue WARNING
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('introuvable', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 2 : Firewall sans authenticator
    // =============================================

    public function testFirewallWithoutAuthenticatorCreatesWarning(): void
    {
        // Arrange : un firewall "main" actif mais sans aucun moyen d'authentification
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'main' => [
                        'lazy' => true,
                        // Pas de form_login, json_login, http_basic, etc.
                    ],
                ],
                'access_control' => [
                    ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : WARNING pour l'absence d'authenticator
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('authentification', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 3 : Firewall "main" sans access_control
    // =============================================

    public function testMainFirewallWithoutAccessControlCreatesCritical(): void
    {
        // Arrange : un firewall "main" avec un authenticator
        // MAIS aucune règle access_control
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'main' => [
                        'lazy' => true,
                        'form_login' => ['login_path' => '/login'],
                    ],
                ],
                // Pas d'access_control du tout
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : CRITICAL pour l'absence d'access_control
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('access_control', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 4 : Mode lazy détecté (bonne pratique)
    // =============================================

    public function testLazyModeCreatesOkIssue(): void
    {
        // Arrange : config complète et correcte avec lazy: true
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'main' => [
                        'lazy' => true,
                        'form_login' => ['login_path' => '/login'],
                    ],
                ],
                'access_control' => [
                    ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : un OK pour le lazy mode
        $oks = $report->getIssuesBySeverity(Severity::OK);
        $this->assertCount(1, $oks);
        $this->assertStringContainsString('lazy', $oks[0]->getMessage());
    }

    // =============================================
    // Test 5 : Le firewall "dev" est ignoré
    // =============================================

    public function testDevFirewallIsIgnored(): void
    {
        // Arrange : un firewall "dev" sans rien — c'est normal
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'dev' => [
                        'pattern' => '^/(_(profiler|wdt))',
                        'security' => false,
                    ],
                ],
                'access_control' => [],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue — le firewall dev est sauté
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 6 : Firewall avec security: false
    // =============================================

    public function testFirewallWithSecurityFalseIsNotFlaggedForAuth(): void
    {
        // Arrange : un firewall explicitement désactivé
        // C'est un choix conscient du dev, pas un oubli.
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'api_public' => [
                        'security' => false,
                    ],
                ],
                'access_control' => [
                    ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : pas de WARNING pour l'absence d'authenticator
        // (security: false = pas besoin d'authenticator)
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(0, $warnings);
    }

    // =============================================
    // Test 7 : Config complète et correcte
    // =============================================

    public function testProperConfigHasNoNegativeIssues(): void
    {
        // Arrange : le projet parfait — firewall avec authenticator,
        // access_control présent, lazy activé
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'dev' => [
                        'pattern' => '^/(_(profiler|wdt))',
                        'security' => false,
                    ],
                    'main' => [
                        'lazy' => true,
                        'form_login' => [
                            'login_path' => '/login',
                            'check_path' => '/login',
                        ],
                    ],
                ],
                'access_control' => [
                    ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                    ['path' => '^/profile', 'roles' => 'ROLE_USER'],
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucun CRITICAL, aucun WARNING
        $this->assertCount(0, $report->getIssuesBySeverity(Severity::CRITICAL));
        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));

        // Mais on a bien un OK pour le lazy mode
        $this->assertCount(1, $report->getIssuesBySeverity(Severity::OK));
    }

    // =============================================
    // Test 8 : Vérification des métadonnées
    // =============================================

    public function testGetModuleReturnsSecurity(): void
    {
        $analyzer = $this->createAnalyzer(null);

        $this->assertSame(Module::SECURITY, $analyzer->getModule());
    }

    public function testGetNameReturnsReadableName(): void
    {
        $analyzer = $this->createAnalyzer(null);

        $this->assertSame('Firewall Analyzer', $analyzer->getName());
    }

    // =============================================
    // Test 9 : Enrichissement de l'issue CRITICAL (access_control manquant)
    // =============================================

    public function testAccessControlIssueHasEnrichmentFields(): void
    {
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'main' => [
                        'form_login' => ['login_path' => '/login'],
                    ],
                ],
                // Pas d'access_control
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('access_control.html', $issue->getDocUrl() ?? '');
    }

    // =============================================
    // Test 10 : Enrichissement de l'issue WARNING (authenticator manquant)
    // =============================================

    public function testAuthenticatorIssueHasEnrichmentFields(): void
    {
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'main' => [
                        // Pas d'authenticator
                    ],
                ],
                'access_control' => [
                    ['path' => '^/admin', 'roles' => 'ROLE_ADMIN'],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('form-login', $issue->getDocUrl() ?? '');
    }
}