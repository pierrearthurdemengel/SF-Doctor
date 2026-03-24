<?php

// tests/Unit/Analyzer/Security/SecretsAnalyzerTest.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Security\SecretsAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class SecretsAnalyzerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sf-doctor-secrets-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Supprime les fichiers .env crees pendant le test.
        foreach (['.env', '.env.prod'] as $file) {
            $path = $this->tmpDir . '/' . $file;
            if (file_exists($path)) {
                unlink($path);
            }
        }
        rmdir($this->tmpDir);
    }

    private function makeReport(): AuditReport
    {
        return new AuditReport($this->tmpDir, [Module::SECURITY]);
    }

    private function makeContext(): \PierreArthur\SfDoctor\Context\ProjectContext
    {
        return new \PierreArthur\SfDoctor\Context\ProjectContext(
            projectPath: $this->tmpDir,
            hasDoctrineOrm: false, hasMessenger: false, hasApiPlatform: false,
            hasTwig: false, hasSecurityBundle: false, hasWebProfilerBundle: false,
            hasMailer: false, hasNelmioCors: false, hasNelmioSecurity: false,
            hasJwtAuth: false, symfonyVersion: null,
        );
    }

    // --- supports() ---

    public function testSupportsReturnsTrueWhenEnvExists(): void
    {
        file_put_contents($this->tmpDir . '/.env', 'APP_SECRET=abc');

        $analyzer = new SecretsAnalyzer($this->tmpDir);

        $this->assertTrue($analyzer->supports($this->makeContext()));
    }

    public function testSupportsReturnsFalseWhenNoEnvFile(): void
    {
        $analyzer = new SecretsAnalyzer($this->tmpDir);

        $this->assertFalse($analyzer->supports($this->makeContext()));
    }

    // --- APP_SECRET absent ---

    public function testDetectsMissingAppSecret(): void
    {
        file_put_contents($this->tmpDir . '/.env', 'APP_ENV=prod' . PHP_EOL);

        $analyzer = new SecretsAnalyzer($this->tmpDir);
        $report = $this->makeReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('APP_SECRET', $criticals[0]->getMessage());
    }

    // --- Valeurs par défaut connues ---

    public function testDetectsDefaultSecretThisToken(): void
    {
        file_put_contents($this->tmpDir . '/.env', 'APP_SECRET=ThisTokenIsNotSoSecretChangeIt');

        $analyzer = new SecretsAnalyzer($this->tmpDir);
        $report = $this->makeReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('ThisTokenIsNotSoSecretChangeIt', $criticals[0]->getMessage());
    }

    public function testDetectsDefaultSecretChangeme(): void
    {
        file_put_contents($this->tmpDir . '/.env', 'APP_SECRET=changeme');

        $analyzer = new SecretsAnalyzer($this->tmpDir);
        $report = $this->makeReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
    }

    // --- Secret trop court ---

    public function testDetectsSecretTooShort(): void
    {
        // 16 caractères - en dessous du minimum de 32.
        file_put_contents($this->tmpDir . '/.env', 'APP_SECRET=shortpassword123');

        $analyzer = new SecretsAnalyzer($this->tmpDir);
        $report = $this->makeReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('trop court', $warnings[0]->getMessage());
    }

    // --- Secret valide ---

    public function testPassesWithValidSecret(): void
    {
        // 64 caractères hexadécimaux - généré par bin2hex(random_bytes(32)).
        $secret = bin2hex(random_bytes(32));
        file_put_contents($this->tmpDir . '/.env', 'APP_SECRET=' . $secret);

        $analyzer = new SecretsAnalyzer($this->tmpDir);
        $report = $this->makeReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::CRITICAL));
        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));
    }

    // --- Priorité .env.prod sur .env ---

    public function testReadsProdEnvWithPriority(): void
    {
        // .env a un secret valide, .env.prod a la valeur par défaut.
        file_put_contents($this->tmpDir . '/.env', 'APP_SECRET=' . bin2hex(random_bytes(32)));
        file_put_contents($this->tmpDir . '/.env.prod', 'APP_SECRET=ThisTokenIsNotSoSecretChangeIt');

        $analyzer = new SecretsAnalyzer($this->tmpDir);
        $report = $this->makeReport();
        $analyzer->analyze($report);

        // Doit lire .env.prod en priorité et détecter le problème.
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
    }

    // --- Guillemets ---

    public function testHandlesQuotedSecret(): void
    {
        $secret = bin2hex(random_bytes(32));
        file_put_contents($this->tmpDir . '/.env', 'APP_SECRET="' . $secret . '"');

        $analyzer = new SecretsAnalyzer($this->tmpDir);
        $report = $this->makeReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::CRITICAL));
        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));
    }

    // --- Enrichissement ---

    public function testIssueHasDocUrl(): void
    {
        file_put_contents($this->tmpDir . '/.env', 'APP_SECRET=ThisTokenIsNotSoSecretChangeIt');

        $analyzer = new SecretsAnalyzer($this->tmpDir);
        $report = $this->makeReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertNotNull($criticals[0]->getDocUrl());
        $this->assertStringContainsString('symfony.com', $criticals[0]->getDocUrl());
    }
}