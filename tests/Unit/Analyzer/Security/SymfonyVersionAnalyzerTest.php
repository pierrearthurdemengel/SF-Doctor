<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Security\SymfonyVersionAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class SymfonyVersionAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Cree un repertoire temporaire unique pour chaque test.
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Nettoyage du repertoire temporaire apres chaque test.
        $this->deleteDirectory($this->tempDir);
    }

    // --- Helper : creer un analyzer pointe sur le repertoire temporaire ---

    private function createAnalyzer(): SymfonyVersionAnalyzer
    {
        return new SymfonyVersionAnalyzer($this->tempDir);
    }

    // Helper : creer un rapport vide pour chaque test.
    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::SECURITY]);
    }

    /**
     * Helper : creer un composer.lock avec une version donnee de symfony/framework-bundle.
     * Le fichier est place dans le repertoire temporaire.
     */
    private function createComposerLock(string $version): void
    {
        $lock = [
            'packages' => [
                [
                    'name' => 'symfony/framework-bundle',
                    'version' => $version,
                ],
            ],
        ];

        file_put_contents(
            $this->tempDir . '/composer.lock',
            json_encode($lock, JSON_PRETTY_PRINT)
        );
    }

    // Helper : supprimer un repertoire recursivement.
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

    // =============================================
    // Test 1 : Pas de composer.lock
    // =============================================

    public function testNoComposerLockDoesNothing(): void
    {
        // Arrange : pas de fichier composer.lock
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 2 : Version 5.4 vulnerable (< 5.4.46)
    // =============================================

    public function testVulnerable54VersionCreatesCritical(): void
    {
        // Arrange : version 5.4.20, anterieure au patch 5.4.46
        $this->createComposerLock('v5.4.20');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : une issue CRITICAL pour la CVE
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('CVE', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 3 : Version 6.x vulnerable (< 6.4.14)
    // =============================================

    public function testVulnerable6xVersionCreatesCritical(): void
    {
        // Arrange : version 6.4.10, anterieure au patch 6.4.14
        $this->createComposerLock('v6.4.10');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : une issue CRITICAL pour la CVE
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('CVE', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 4 : Version 7.x vulnerable (< 7.1.7)
    // =============================================

    public function testVulnerable7xVersionCreatesCritical(): void
    {
        // Arrange : version 7.1.2, anterieure au patch 7.1.7
        $this->createComposerLock('v7.1.2');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : une issue CRITICAL pour la CVE
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('CVE', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 5 : Version 6.4 patchee, pas de CVE
    // =============================================

    public function testPatchedVersionHasNoCve(): void
    {
        // Arrange : version 6.4.14, le dernier patch connu
        $this->createComposerLock('v6.4.14');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue CRITICAL
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(0, $criticals);
    }

    // =============================================
    // Test 6 : Version 5.x -> end of life (WARNING)
    // =============================================

    public function testEndOfLifeVersionCreatesWarning(): void
    {
        // Arrange : version 5.4.46 (patchee mais en fin de vie)
        $this->createComposerLock('v5.4.46');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : un WARNING pour la fin de vie
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $hasEolWarning = false;
        foreach ($warnings as $w) {
            if (str_contains($w->getMessage(), 'fin de vie')) {
                $hasEolWarning = true;
                break;
            }
        }
        $this->assertTrue($hasEolWarning, 'Un WARNING pour la fin de vie de Symfony 5.x est attendu');
    }

    // =============================================
    // Test 7 : Version 6.2 non-LTS -> WARNING
    // =============================================

    public function testNonLtsVersionCreatesWarning(): void
    {
        // Arrange : version 6.2.5, non LTS
        $this->createComposerLock('v6.2.5');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : un WARNING pour la version non LTS
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $hasNonLtsWarning = false;
        foreach ($warnings as $w) {
            if (str_contains($w->getMessage(), 'LTS')) {
                $hasNonLtsWarning = true;
                break;
            }
        }
        $this->assertTrue($hasNonLtsWarning, 'Un WARNING pour la version non-LTS est attendu');
    }

    // =============================================
    // Test 8 : Version 6.4 LTS recente -> pas de WARNING non-LTS
    // =============================================

    public function testLtsVersionDoesNotCreateNonLtsWarning(): void
    {
        // Arrange : version 6.4.14 (LTS, patchee)
        $this->createComposerLock('v6.4.14');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucun WARNING
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(0, $warnings);
    }

    // =============================================
    // Test 9 : Verification des champs d'enrichissement
    // =============================================

    public function testEnrichmentFields(): void
    {
        // Arrange : version vulnerable pour generer un CRITICAL
        $this->createComposerLock('v6.4.10');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : verification des champs d'enrichissement sur le CRITICAL
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('security-advisories', $issue->getDocUrl() ?? '');
    }
}
