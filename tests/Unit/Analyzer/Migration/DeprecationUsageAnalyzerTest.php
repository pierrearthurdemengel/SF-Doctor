<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Migration;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Migration\DeprecationUsageAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Tests unitaires pour DeprecationUsageAnalyzer.
 *
 * Verifie la detection des usages de getDoctrine() et getExtendedType()
 * dans les fichiers PHP du projet.
 */
final class DeprecationUsageAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
        mkdir($this->tempDir . '/src', 0777, true);
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
        return new AuditReport($this->tempDir, [Module::MIGRATION]);
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
    // Test 1 : Pas de dossier src/ - rien ne se passe
    // =============================================

    public function testNoSrcDirDoesNothing(): void
    {
        // Supprimer le dossier src/ cree dans setUp.
        rmdir($this->tempDir . '/src');

        $analyzer = new DeprecationUsageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 2 : getDoctrine() detecte dans un controller
    // =============================================

    public function testGetDoctrineCreatesWarning(): void
    {
        $code = <<<'PHP'
        <?php
        class ProductController extends AbstractController
        {
            public function index(): Response
            {
                $em = $this->getDoctrine()->getManager();
                return $this->render('index.html.twig');
            }
        }
        PHP;
        file_put_contents($this->tempDir . '/src/ProductController.php', $code);

        $analyzer = new DeprecationUsageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('getDoctrine()', $warnings[0]->getMessage());
        $this->assertStringContainsString('1 occurrence', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 3 : Plusieurs occurrences de getDoctrine()
    // =============================================

    public function testMultipleGetDoctrineOccurrences(): void
    {
        $code = <<<'PHP'
        <?php
        class OrderController extends AbstractController
        {
            public function create(): Response
            {
                $em = $this->getDoctrine()->getManager();
                $repo = $this->getDoctrine()->getRepository(Order::class);
                return $this->render('create.html.twig');
            }
        }
        PHP;
        file_put_contents($this->tempDir . '/src/OrderController.php', $code);

        $analyzer = new DeprecationUsageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('2 occurrences', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 4 : getExtendedType() sans getExtendedTypes()
    // =============================================

    public function testGetExtendedTypeWithoutPluralCreatesWarning(): void
    {
        $code = <<<'PHP'
        <?php
        class MyExtension extends AbstractTypeExtension
        {
            public function getExtendedType(): string
            {
                return TextType::class;
            }
        }
        PHP;
        file_put_contents($this->tempDir . '/src/MyExtension.php', $code);

        $analyzer = new DeprecationUsageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('getExtendedType()', $warnings[0]->getMessage());
    }

    // =============================================
    // Test 5 : getExtendedType() + getExtendedTypes() - pas de warning
    // =============================================

    public function testGetExtendedTypeWithPluralDoesNothing(): void
    {
        $code = <<<'PHP'
        <?php
        class MyExtension extends AbstractTypeExtension
        {
            public function getExtendedType(): string
            {
                return TextType::class;
            }

            public static function getExtendedTypes(): iterable
            {
                return [TextType::class];
            }
        }
        PHP;
        file_put_contents($this->tempDir . '/src/MyExtension.php', $code);

        $analyzer = new DeprecationUsageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        // Pas de warning pour getExtendedType car getExtendedTypes est present.
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(0, $warnings);
    }

    // =============================================
    // Test 6 : Fichier sans deprecation - aucune issue
    // =============================================

    public function testCleanCodeDoesNothing(): void
    {
        $code = <<<'PHP'
        <?php
        class UserController extends AbstractController
        {
            public function __construct(
                private readonly EntityManagerInterface $entityManager,
            ) {}

            public function index(): Response
            {
                $users = $this->entityManager->getRepository(User::class)->findAll();
                return $this->render('users/index.html.twig', ['users' => $users]);
            }
        }
        PHP;
        file_put_contents($this->tempDir . '/src/UserController.php', $code);

        $analyzer = new DeprecationUsageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // =============================================
    // Test 7 : Verification des metadonnees
    // =============================================

    public function testGetNameReturnsExpectedName(): void
    {
        $analyzer = new DeprecationUsageAnalyzer($this->tempDir);
        $this->assertSame('Deprecation Usage Analyzer', $analyzer->getName());
    }

    public function testGetModuleReturnsMigration(): void
    {
        $analyzer = new DeprecationUsageAnalyzer($this->tempDir);
        $this->assertSame(Module::MIGRATION, $analyzer->getModule());
    }

    public function testSupportsAlwaysReturnsTrue(): void
    {
        $analyzer = new DeprecationUsageAnalyzer($this->tempDir);
        $this->assertTrue($analyzer->supports($this->makeContext()));
    }

    // =============================================
    // Test 8 : Champs d'enrichissement sur getDoctrine()
    // =============================================

    public function testGetDoctrineIssueHasEnrichmentFields(): void
    {
        $code = <<<'PHP'
        <?php
        class TestController extends AbstractController
        {
            public function index(): Response
            {
                $em = $this->getDoctrine()->getManager();
            }
        }
        PHP;
        file_put_contents($this->tempDir . '/src/TestController.php', $code);

        $analyzer = new DeprecationUsageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertSame(10, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('doctrine.html', $issue->getDocUrl() ?? '');
    }

    // =============================================
    // Test 9 : Champs d'enrichissement sur getExtendedType()
    // =============================================

    public function testGetExtendedTypeIssueHasEnrichmentFields(): void
    {
        $code = <<<'PHP'
        <?php
        class MyExtension extends AbstractTypeExtension
        {
            public function getExtendedType(): string
            {
                return TextType::class;
            }
        }
        PHP;
        file_put_contents($this->tempDir . '/src/MyExtension.php', $code);

        $analyzer = new DeprecationUsageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
        $this->assertSame(10, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('form_type_extension', $issue->getDocUrl() ?? '');
    }

    // =============================================
    // Test 10 : Les fichiers non-PHP sont ignores
    // =============================================

    public function testNonPhpFilesAreIgnored(): void
    {
        file_put_contents($this->tempDir . '/src/notes.txt', 'getDoctrine() is deprecated');

        $analyzer = new DeprecationUsageAnalyzer($this->tempDir);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }
}
