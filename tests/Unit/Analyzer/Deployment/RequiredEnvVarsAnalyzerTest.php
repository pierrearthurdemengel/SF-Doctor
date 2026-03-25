<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Deployment;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Deployment\RequiredEnvVarsAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Tests unitaires pour RequiredEnvVarsAnalyzer.
 *
 * Verifie la detection des variables d'environnement manquantes
 * dans .env.prod et des valeurs placeholder (changeme, todo, etc.).
 */
final class RequiredEnvVarsAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport($this->tempDir, [Module::DEPLOYMENT]);
    }

    private function makeContext(): ProjectContext
    {
        return new ProjectContext(
            projectPath: $this->tempDir,
            hasDoctrineOrm: false, hasMessenger: false, hasApiPlatform: false,
            hasTwig: false, hasSecurityBundle: false, hasWebProfilerBundle: false,
            hasMailer: false, hasNelmioCors: false, hasNelmioSecurity: false,
            hasJwtAuth: false, symfonyVersion: null,
        );
    }

    // =============================================
    // Test 1 : supports() retourne false sans fichier .env
    // =============================================

    public function testSupportsReturnsFalseWithoutEnvFile(): void
    {
        $analyzer = new RequiredEnvVarsAnalyzer($this->tempDir);

        $this->assertFalse($analyzer->supports($this->makeContext()));
    }

    // =============================================
    // Test 2 : supports() retourne true avec fichier .env
    // =============================================

    public function testSupportsReturnsTrueWithEnvFile(): void
    {
        file_put_contents($this->tempDir . '/.env', 'APP_ENV=prod');
        $analyzer = new RequiredEnvVarsAnalyzer($this->tempDir);

        $this->assertTrue($analyzer->supports($this->makeContext()));
    }

    // =============================================
    // Test 3 : Variables presentes dans .env mais absentes de .env.prod - CRITICAL
    // =============================================

    public function testMissingProdVarsCreatesCritical(): void
    {
        $envContent = "DATABASE_URL=postgres://localhost\nMAILER_DSN=smtp://localhost";
        file_put_contents($this->tempDir . '/.env', $envContent);
        // Pas de .env.prod du tout.

        $analyzer = new RequiredEnvVarsAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('production', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 4 : Variables systeme (APP_ENV, APP_DEBUG, APP_SECRET) ignorees
    // =============================================

    public function testSystemVarsAreIgnored(): void
    {
        $envContent = "APP_ENV=dev\nAPP_DEBUG=true\nAPP_SECRET=abc123";
        file_put_contents($this->tempDir . '/.env', $envContent);

        $analyzer = new RequiredEnvVarsAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        // Les variables systeme ne devraient pas generer de CRITICAL pour "manquante en prod".
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $missingProdIssue = false;
        foreach ($criticals as $c) {
            if (str_contains($c->getMessage(), 'production')) {
                $missingProdIssue = true;
            }
        }
        $this->assertFalse($missingProdIssue, 'Les variables systeme ne devraient pas etre signalees comme manquantes en prod');
    }

    // =============================================
    // Test 5 : Valeur placeholder detectee - CRITICAL
    // =============================================

    public function testPlaceholderValueCreatesCritical(): void
    {
        $envContent = "DATABASE_URL=changeme";
        file_put_contents($this->tempDir . '/.env', $envContent);

        $analyzer = new RequiredEnvVarsAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        // Au moins un critical pour la valeur placeholder.
        $placeholderIssue = false;
        foreach ($criticals as $c) {
            if (str_contains($c->getMessage(), 'placeholder')) {
                $placeholderIssue = true;
            }
        }
        $this->assertTrue($placeholderIssue, 'Devrait signaler la valeur placeholder "changeme"');
    }

    // =============================================
    // Test 6 : Toutes les variables ont des valeurs de production - pas de critical
    // =============================================

    public function testAllVarsInProdDoesNothing(): void
    {
        $envContent = "DATABASE_URL=postgres://localhost\nMAILER_DSN=smtp://localhost";
        $envProdContent = "DATABASE_URL=postgres://prod-server\nMAILER_DSN=smtp://prod-server";
        file_put_contents($this->tempDir . '/.env', $envContent);
        file_put_contents($this->tempDir . '/.env.prod', $envProdContent);

        $analyzer = new RequiredEnvVarsAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 7 : .env.prod avec valeur placeholder - CRITICAL
    // =============================================

    public function testProdPlaceholderCreatesCritical(): void
    {
        $envContent = "DATABASE_URL=postgres://dev\nMAILER_DSN=smtp://dev";
        $envProdContent = "DATABASE_URL=todo\nMAILER_DSN=smtp://prod";
        file_put_contents($this->tempDir . '/.env', $envContent);
        file_put_contents($this->tempDir . '/.env.prod', $envProdContent);

        $analyzer = new RequiredEnvVarsAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $placeholderIssue = false;
        foreach ($criticals as $c) {
            if (str_contains($c->getMessage(), 'placeholder')) {
                $placeholderIssue = true;
            }
        }
        $this->assertTrue($placeholderIssue, 'Devrait signaler la valeur placeholder "todo" dans .env.prod');
    }

    // =============================================
    // Test 8 : Les commentaires et lignes vides sont ignores
    // =============================================

    public function testCommentsAndEmptyLinesAreIgnored(): void
    {
        $envContent = "# Ceci est un commentaire\n\nDATABASE_URL=postgres://prod";
        $envProdContent = "DATABASE_URL=postgres://prod";
        file_put_contents($this->tempDir . '/.env', $envContent);
        file_put_contents($this->tempDir . '/.env.prod', $envProdContent);

        $analyzer = new RequiredEnvVarsAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 9 : Verification des metadonnees
    // =============================================

    public function testGetNameReturnsExpectedName(): void
    {
        $analyzer = new RequiredEnvVarsAnalyzer($this->tempDir);
        $this->assertSame('Required Env Vars Analyzer', $analyzer->getName());
    }

    public function testGetModuleReturnsDeployment(): void
    {
        $analyzer = new RequiredEnvVarsAnalyzer($this->tempDir);
        $this->assertSame(Module::DEPLOYMENT, $analyzer->getModule());
    }

    // =============================================
    // Test 10 : Champs d'enrichissement sur variables manquantes en prod
    // =============================================

    public function testMissingProdVarsIssueHasEnrichmentFields(): void
    {
        $envContent = "DATABASE_URL=postgres://localhost";
        file_put_contents($this->tempDir . '/.env', $envContent);

        $analyzer = new RequiredEnvVarsAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        // Chercher l'issue pour les variables manquantes en prod.
        $issue = null;
        foreach ($criticals as $c) {
            if (str_contains($c->getMessage(), 'production')) {
                $issue = $c;
                break;
            }
        }
        $this->assertNotNull($issue, 'Devrait trouver une issue pour variable manquante en prod');
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertSame(15, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('environment-variables', $issue->getDocUrl() ?? '');
    }

    // =============================================
    // Test 11 : Champs d'enrichissement sur valeur placeholder
    // =============================================

    public function testPlaceholderIssueHasEnrichmentFields(): void
    {
        $envContent = "API_KEY=changeme";
        file_put_contents($this->tempDir . '/.env', $envContent);

        $analyzer = new RequiredEnvVarsAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $issue = null;
        foreach ($criticals as $c) {
            if (str_contains($c->getMessage(), 'placeholder')) {
                $issue = $c;
                break;
            }
        }
        $this->assertNotNull($issue, 'Devrait trouver une issue pour valeur placeholder');
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertSame(10, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('secrets', $issue->getDocUrl() ?? '');
    }
}
