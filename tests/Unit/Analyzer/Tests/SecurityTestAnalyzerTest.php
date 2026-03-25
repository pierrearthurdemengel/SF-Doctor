<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Tests;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Tests\SecurityTestAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Tests unitaires pour SecurityTestAnalyzer.
 *
 * Verifie que chaque Voter dans src/Security/ possede un test
 * correspondant dans tests/.
 */
final class SecurityTestAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
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
        return new AuditReport($this->tempDir, [Module::TESTS]);
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
    // Test 1 : Pas de dossier src/Security/ - rien ne se passe
    // =============================================

    public function testNoSecurityDirDoesNothing(): void
    {
        mkdir($this->tempDir, 0777, true);

        $analyzer = new SecurityTestAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 2 : Voter avec test correspondant - aucune issue
    // =============================================

    public function testVoterWithTestDoesNothing(): void
    {
        mkdir($this->tempDir . '/src/Security', 0777, true);
        mkdir($this->tempDir . '/tests/Security', 0777, true);

        $voterCode = <<<'PHP'
        <?php
        class PostVoter extends Voter
        {
            protected function supports(string $attribute, mixed $subject): bool
            {
                return true;
            }
        }
        PHP;
        file_put_contents($this->tempDir . '/src/Security/PostVoter.php', $voterCode);
        file_put_contents($this->tempDir . '/tests/Security/PostVoterTest.php', "<?php\nclass PostVoterTest {}");

        $analyzer = new SecurityTestAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 3 : Voter sans test correspondant - CRITICAL
    // =============================================

    public function testVoterWithoutTestCreatesCritical(): void
    {
        mkdir($this->tempDir . '/src/Security', 0777, true);
        mkdir($this->tempDir . '/tests', 0777, true);

        $voterCode = <<<'PHP'
        <?php
        class ArticleVoter extends Voter
        {
            protected function supports(string $attribute, mixed $subject): bool
            {
                return true;
            }
        }
        PHP;
        file_put_contents($this->tempDir . '/src/Security/ArticleVoter.php', $voterCode);

        $analyzer = new SecurityTestAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('ArticleVoter', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 4 : Champs d'enrichissement sur issue CRITICAL
    // =============================================

    public function testEnrichmentFields(): void
    {
        mkdir($this->tempDir . '/src/Security', 0777, true);
        mkdir($this->tempDir . '/tests', 0777, true);

        $voterCode = <<<'PHP'
        <?php
        class CommentVoter extends Voter
        {
            protected function supports(string $attribute, mixed $subject): bool
            {
                return true;
            }
        }
        PHP;
        file_put_contents($this->tempDir . '/src/Security/CommentVoter.php', $voterCode);

        $analyzer = new SecurityTestAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertSame(30, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('voters', $issue->getDocUrl() ?? '');
        $this->assertStringContainsString('CommentVoter', $issue->getMessage());
    }

    // =============================================
    // Test 5 : Voter detecte par le contenu "extends Voter"
    // =============================================

    public function testVoterDetectedByContentCreatesIssue(): void
    {
        mkdir($this->tempDir . '/src/Security', 0777, true);
        mkdir($this->tempDir . '/tests', 0777, true);

        // Fichier sans "Voter" dans le nom mais qui etend Voter.
        $voterCode = <<<'PHP'
        <?php
        class AccessChecker extends Voter
        {
            protected function supports(string $attribute, mixed $subject): bool
            {
                return true;
            }
        }
        PHP;
        file_put_contents($this->tempDir . '/src/Security/AccessChecker.php', $voterCode);

        $analyzer = new SecurityTestAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('AccessChecker', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 6 : Plusieurs Voters, un avec test et un sans
    // =============================================

    public function testMultipleVotersMixedResults(): void
    {
        mkdir($this->tempDir . '/src/Security', 0777, true);
        mkdir($this->tempDir . '/tests/Security', 0777, true);

        // Voter avec test.
        file_put_contents($this->tempDir . '/src/Security/PostVoter.php', "<?php\nclass PostVoter extends Voter {}");
        file_put_contents($this->tempDir . '/tests/Security/PostVoterTest.php', "<?php\nclass PostVoterTest {}");

        // Voter sans test.
        file_put_contents($this->tempDir . '/src/Security/UserVoter.php', "<?php\nclass UserVoter extends Voter {}");

        $analyzer = new SecurityTestAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        // Seul UserVoter doit generer un CRITICAL.
        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('UserVoter', $criticals[0]->getMessage());
    }

    // =============================================
    // Test 7 : Verification des metadonnees
    // =============================================

    public function testGetNameReturnsExpectedName(): void
    {
        mkdir($this->tempDir, 0777, true);
        $analyzer = new SecurityTestAnalyzer($this->tempDir);
        $this->assertSame('Security Test Analyzer', $analyzer->getName());
    }

    public function testGetModuleReturnsTests(): void
    {
        mkdir($this->tempDir, 0777, true);
        $analyzer = new SecurityTestAnalyzer($this->tempDir);
        $this->assertSame(Module::TESTS, $analyzer->getModule());
    }

    // =============================================
    // Test 8 : supports() verifie la presence de src/Security/
    // =============================================

    public function testSupportsReturnsFalseWithoutSecurityDir(): void
    {
        mkdir($this->tempDir, 0777, true);
        $analyzer = new SecurityTestAnalyzer($this->tempDir);

        $this->assertFalse($analyzer->supports($this->makeContext()));
    }

    public function testSupportsReturnsTrueWithSecurityDir(): void
    {
        mkdir($this->tempDir . '/src/Security', 0777, true);
        $analyzer = new SecurityTestAnalyzer($this->tempDir);

        $this->assertTrue($analyzer->supports($this->makeContext()));
    }

    // =============================================
    // Test 9 : Voter sans dossier tests/ - CRITICAL
    // =============================================

    public function testVoterWithoutTestsDirCreatesCritical(): void
    {
        mkdir($this->tempDir . '/src/Security', 0777, true);

        file_put_contents($this->tempDir . '/src/Security/OrderVoter.php', "<?php\nclass OrderVoter extends Voter {}");

        $analyzer = new SecurityTestAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('OrderVoter', $criticals[0]->getMessage());
    }
}
