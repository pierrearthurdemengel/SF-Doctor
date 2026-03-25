<?php

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Security;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Security\PublicSensitiveFilesAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class PublicSensitiveFilesAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Cree un repertoire temporaire unique pour chaque test.
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
        mkdir($this->tempDir . '/public', 0777, true);
    }

    protected function tearDown(): void
    {
        // Nettoyage du repertoire temporaire apres chaque test.
        $this->deleteDirectory($this->tempDir);
    }

    // --- Helper : creer un analyzer pointe sur le repertoire temporaire ---

    private function createAnalyzer(): PublicSensitiveFilesAnalyzer
    {
        return new PublicSensitiveFilesAnalyzer($this->tempDir);
    }

    // Helper : creer un rapport vide pour chaque test.
    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::SECURITY]);
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
    // Test 1 : Pas de repertoire public/
    // =============================================

    public function testNoPublicDirDoesNothing(): void
    {
        // Arrange : supprime le repertoire public pour simuler son absence
        $this->deleteDirectory($this->tempDir . '/public');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 2 : public/.env expose -> CRITICAL
    // =============================================

    public function testDotEnvExposedCreatesCritical(): void
    {
        // Arrange : un fichier .env dans public/
        file_put_contents($this->tempDir . '/public/.env', 'APP_SECRET=my_secret_key');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : une issue CRITICAL pour le .env expose
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertGreaterThanOrEqual(1, count($criticals));

        $envIssue = false;
        foreach ($criticals as $c) {
            if (str_contains($c->getMessage(), '.env')) {
                $envIssue = true;
                break;
            }
        }
        $this->assertTrue($envIssue, 'Un CRITICAL pour le fichier .env expose est attendu');
    }

    // =============================================
    // Test 3 : public/phpinfo.php expose -> CRITICAL
    // =============================================

    public function testPhpinfoExposedCreatesCritical(): void
    {
        // Arrange : un fichier phpinfo.php dans public/
        file_put_contents($this->tempDir . '/public/phpinfo.php', '<?php phpinfo();');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : une issue CRITICAL pour phpinfo.php
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertGreaterThanOrEqual(1, count($criticals));

        $phpinfoIssue = false;
        foreach ($criticals as $c) {
            if (str_contains($c->getMessage(), 'phpinfo.php')) {
                $phpinfoIssue = true;
                break;
            }
        }
        $this->assertTrue($phpinfoIssue, 'Un CRITICAL pour le fichier phpinfo.php expose est attendu');
    }

    // =============================================
    // Test 4 : public/composer.json expose -> WARNING
    // =============================================

    public function testComposerJsonExposedCreatesWarning(): void
    {
        // Arrange : un fichier composer.json dans public/
        file_put_contents($this->tempDir . '/public/composer.json', '{"require":{}}');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : un WARNING pour composer.json expose
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertGreaterThanOrEqual(1, count($warnings));

        $composerIssue = false;
        foreach ($warnings as $w) {
            if (str_contains($w->getMessage(), 'composer.json')) {
                $composerIssue = true;
                break;
            }
        }
        $this->assertTrue($composerIssue, 'Un WARNING pour le fichier composer.json expose est attendu');
    }

    // =============================================
    // Test 5 : public/composer.lock expose -> WARNING
    // =============================================

    public function testComposerLockExposedCreatesWarning(): void
    {
        // Arrange : un fichier composer.lock dans public/
        file_put_contents($this->tempDir . '/public/composer.lock', '{"packages":[]}');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : un WARNING pour composer.lock expose
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertGreaterThanOrEqual(1, count($warnings));

        $lockIssue = false;
        foreach ($warnings as $w) {
            if (str_contains($w->getMessage(), 'composer.lock')) {
                $lockIssue = true;
                break;
            }
        }
        $this->assertTrue($lockIssue, 'Un WARNING pour le fichier composer.lock expose est attendu');
    }

    // =============================================
    // Test 6 : Scripts dangereux (info.php, test.php) -> CRITICAL
    // =============================================

    public function testDangerousScriptsCreateCriticals(): void
    {
        // Arrange : des scripts de debug dans public/
        file_put_contents($this->tempDir . '/public/info.php', '<?php phpinfo();');
        file_put_contents($this->tempDir . '/public/test.php', '<?php echo "test";');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : 2 CRITICAL pour les scripts dangereux
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(2, $criticals);

        $messages = array_map(fn ($issue) => $issue->getMessage(), $criticals);
        $messagesStr = implode(' ', $messages);
        $this->assertStringContainsString('info.php', $messagesStr);
        $this->assertStringContainsString('test.php', $messagesStr);
    }

    // =============================================
    // Test 7 : Repertoire public/ propre -> aucune issue
    // =============================================

    public function testCleanPublicDirDoesNothing(): void
    {
        // Arrange : public/ avec seulement index.php (normal)
        file_put_contents($this->tempDir . '/public/index.php', '<?php // front controller');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : aucune issue
        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 8 : Plusieurs fichiers sensibles combines
    // =============================================

    public function testMultipleSensitiveFilesCreateMultipleIssues(): void
    {
        // Arrange : plusieurs fichiers sensibles dans public/
        file_put_contents($this->tempDir . '/public/.env', 'APP_SECRET=key');
        file_put_contents($this->tempDir . '/public/phpinfo.php', '<?php phpinfo();');
        file_put_contents($this->tempDir . '/public/composer.json', '{}');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : 3 issues au total (2 CRITICAL + 1 WARNING)
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(2, $criticals);
        $this->assertCount(1, $warnings);
    }

    // =============================================
    // Test 9 : Verification des champs d'enrichissement
    // =============================================

    public function testEnrichmentFields(): void
    {
        // Arrange : un fichier .env pour generer un CRITICAL
        file_put_contents($this->tempDir . '/public/.env', 'APP_SECRET=my_secret');
        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();

        // Act
        $analyzer->analyze($report);

        // Assert : verification des champs d'enrichissement
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertGreaterThanOrEqual(1, count($criticals));

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('env', $issue->getDocUrl() ?? '');
    }
}
