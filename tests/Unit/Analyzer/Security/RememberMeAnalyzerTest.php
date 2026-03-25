<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Security\RememberMeAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class RememberMeAnalyzerTest extends TestCase
{
    // --- Helper : creer un analyzer avec un mock du ConfigReader ---

    /**
     * @param array<mixed>|null $securityConfig Ce que read() retournera
     */
    private function createAnalyzer(?array $securityConfig): RememberMeAnalyzer
    {
        // On cree un faux objet ConfigReaderInterface
        // qui retourne toujours la config fournie en parametre.
        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn($securityConfig);

        return new RememberMeAnalyzer($configReader);
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

        // Assert : aucune issue car pas de config
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 2 : Config remember_me securisee
    // =============================================

    public function testSecureRememberMeConfig(): void
    {
        // Arrange : remember_me avec secure + httponly + lifetime raisonnable
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'main' => [
                        'remember_me' => [
                            'secret' => '%kernel.secret%',
                            'secure' => true,
                            'httponly' => true,
                            'lifetime' => 604800, // 7 jours
                        ],
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue, la config est correcte
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 3 : Absence du flag secure
    // =============================================

    public function testMissingSecureFlagCreatesWarning(): void
    {
        // Arrange : remember_me sans secure: true
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'main' => [
                        'remember_me' => [
                            'secret' => '%kernel.secret%',
                            'httponly' => true,
                            'lifetime' => 604800,
                        ],
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : au moins un WARNING pour le flag secure manquant
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertGreaterThanOrEqual(1, count($warnings));

        // Verifie qu'un des warnings concerne le flag secure
        $secureMissing = false;
        foreach ($warnings as $w) {
            if (str_contains($w->getMessage(), 'secure')) {
                $secureMissing = true;
                break;
            }
        }
        $this->assertTrue($secureMissing, 'Un WARNING pour le flag secure manquant est attendu');
    }

    // =============================================
    // Test 4 : Absence du flag httponly
    // =============================================

    public function testMissingHttpOnlyFlagCreatesWarning(): void
    {
        // Arrange : remember_me sans httponly: true
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'main' => [
                        'remember_me' => [
                            'secret' => '%kernel.secret%',
                            'secure' => true,
                            'lifetime' => 604800,
                        ],
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : au moins un WARNING pour le flag httponly manquant
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertGreaterThanOrEqual(1, count($warnings));

        $httpOnlyMissing = false;
        foreach ($warnings as $w) {
            if (str_contains($w->getMessage(), 'httponly')) {
                $httpOnlyMissing = true;
                break;
            }
        }
        $this->assertTrue($httpOnlyMissing, 'Un WARNING pour le flag httponly manquant est attendu');
    }

    // =============================================
    // Test 5 : Duree de vie excessive (> 30 jours)
    // =============================================

    public function testExcessiveLifetimeCreatesSuggestion(): void
    {
        // Arrange : lifetime de 90 jours (7776000 secondes), bien au-dessus de 2592000
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'main' => [
                        'remember_me' => [
                            'secret' => '%kernel.secret%',
                            'secure' => true,
                            'httponly' => true,
                            'lifetime' => 7776000, // 90 jours
                        ],
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : une SUGGESTION pour la duree de vie excessive
        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(1, $suggestions);
        $this->assertStringContainsString('durée de vie', $suggestions[0]->getMessage());
    }

    // =============================================
    // Test 6 : Duree de vie raisonnable (<= 30 jours)
    // =============================================

    public function testReasonableLifetimeDoesNothing(): void
    {
        // Arrange : lifetime de 30 jours exactement (2592000)
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'main' => [
                        'remember_me' => [
                            'secret' => '%kernel.secret%',
                            'secure' => true,
                            'httponly' => true,
                            'lifetime' => 2592000, // exactement 30 jours
                        ],
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune SUGGESTION
        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(0, $suggestions);
    }

    // =============================================
    // Test 7 : Le firewall "dev" est ignore
    // =============================================

    public function testDevFirewallIsIgnored(): void
    {
        // Arrange : un firewall "dev" avec remember_me non securise
        // Il doit etre ignore par l'analyzer
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'dev' => [
                        'remember_me' => [
                            'secret' => '%kernel.secret%',
                            // Pas de secure ni httponly, mais c'est le firewall dev
                        ],
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue, le firewall dev est saute
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 8 : Verification des champs d'enrichissement
    // =============================================

    public function testEnrichmentFields(): void
    {
        // Arrange : config qui genere un WARNING (flag secure manquant)
        $analyzer = $this->createAnalyzer([
            'security' => [
                'firewalls' => [
                    'main' => [
                        'remember_me' => [
                            'secret' => '%kernel.secret%',
                            'httponly' => true,
                            'lifetime' => 604800,
                        ],
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : les champs d'enrichissement sont remplis
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertGreaterThanOrEqual(1, count($warnings));

        // On prend la premiere issue pour verifier l'enrichissement
        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('remember_me', $issue->getDocUrl() ?? '');
    }
}
