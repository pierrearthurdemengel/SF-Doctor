<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Architecture;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Architecture\VoterUsageAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class VoterUsageAnalyzerTest extends TestCase
{
    // ---------------------------------------------------------------
    // Gestion du repertoire temporaire
    // ---------------------------------------------------------------

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

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createAnalyzer(): VoterUsageAnalyzer
    {
        return new VoterUsageAnalyzer($this->tempDir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::ARCHITECTURE]);
    }

    /**
     * Cree un fichier PHP dans le dossier src/Controller.
     */
    private function writeControllerFile(string $filename, string $content): void
    {
        $dir = $this->tempDir . '/src/Controller';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . $filename, $content);
    }

    // ---------------------------------------------------------------
    // 1. Pas de dossier Controller - aucun issue
    // ---------------------------------------------------------------

    public function testNoControllerDirDoesNothing(): void
    {
        // Le dossier src/Controller n'existe pas
        mkdir($this->tempDir, 0777, true);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // ---------------------------------------------------------------
    // 2. Verification manuelle de role avec in_array(...ROLE_) - WARNING
    // ---------------------------------------------------------------

    public function testManualRoleCheckCreatesWarning(): void
    {
        $content = <<<'PHP'
<?php
namespace App\Controller;

class AdminController
{
    public function dashboard(): void
    {
        $user = $this->getUser();
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            // logique admin
        }
    }
}
PHP;

        $this->writeControllerFile('AdminController.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('manuelle', $warnings[0]->getMessage());
    }

    // ---------------------------------------------------------------
    // 3. Appel a ->hasRole() - WARNING
    // ---------------------------------------------------------------

    public function testHasRoleCallCreatesWarning(): void
    {
        $content = <<<'PHP'
<?php
namespace App\Controller;

class ProfileController
{
    public function edit(): void
    {
        $user = $this->getUser();
        if ($user->hasRole('ROLE_EDITOR')) {
            // acces editeur
        }
    }
}
PHP;

        $this->writeControllerFile('ProfileController.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('manuelle', $warnings[0]->getMessage());
    }

    // ---------------------------------------------------------------
    // 4. denyAccessUnlessGranted('ROLE_ADMIN') en dur - SUGGESTION
    // ---------------------------------------------------------------

    public function testHardcodedRoleInGrantCreatesSuggestion(): void
    {
        $content = <<<'PHP'
<?php
namespace App\Controller;

class SecureController
{
    public function admin(): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        // logique admin
    }
}
PHP;

        $this->writeControllerFile('SecureController.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(1, $suggestions);
        $this->assertStringContainsString('denyAccessUnlessGranted', $suggestions[0]->getMessage());
    }

    // ---------------------------------------------------------------
    // 5. Controller propre sans verification manuelle - aucun issue
    // ---------------------------------------------------------------

    public function testNoManualCheckDoesNothing(): void
    {
        $content = <<<'PHP'
<?php
namespace App\Controller;

use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class CleanController
{
    public function index(): void
    {
        // pas de verification manuelle
        $this->render('index.html.twig');
    }
}
PHP;

        $this->writeControllerFile('CleanController.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // ---------------------------------------------------------------
    // 6. Verification des champs d'enrichissement
    // ---------------------------------------------------------------

    public function testEnrichmentFields(): void
    {
        // Verification manuelle pour declencher un warning
        $content = <<<'PHP'
<?php
namespace App\Controller;

class EnrichController
{
    public function test(): void
    {
        if (in_array('ROLE_ADMIN', $this->getUser()->getRoles())) {
            // test
        }
    }
}
PHP;

        $this->writeControllerFile('EnrichController.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode(), 'fixCode ne doit pas etre null');
        $this->assertNotNull($issue->getDocUrl(), 'docUrl ne doit pas etre null');
        $this->assertNotNull($issue->getBusinessImpact(), 'businessImpact ne doit pas etre null');
        $this->assertNotNull($issue->getEstimatedFixMinutes(), 'estimatedFixMinutes ne doit pas etre null');
        $this->assertSame(30, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('voters', $issue->getDocUrl() ?? '');
    }
}
