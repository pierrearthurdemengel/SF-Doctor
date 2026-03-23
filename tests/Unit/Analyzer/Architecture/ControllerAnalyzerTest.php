<?php

declare(strict_types=1);

namespace SfDoctor\Tests\Unit\Analyzer\Architecture;

use PHPUnit\Framework\TestCase;
use SfDoctor\Analyzer\Architecture\ControllerAnalyzer;
use SfDoctor\Model\AuditReport;
use SfDoctor\Model\Module;
use SfDoctor\Model\Severity;

final class ControllerAnalyzerTest extends TestCase
{
    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createAnalyzer(string $projectPath): ControllerAnalyzer
    {
        return new ControllerAnalyzer($projectPath);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::ARCHITECTURE]);
    }

    private function createTempProjectWithController(string $phpContent): string
    {
        $projectPath = sys_get_temp_dir() . '/sf_doctor_controller_test_' . uniqid();
        $controllerDir = $projectPath . '/src/Controller';

        mkdir($controllerDir, 0777, true);
        file_put_contents($controllerDir . '/TestController.php', $phpContent);

        return $projectPath;
    }

    private function cleanTempProject(string $projectPath): void
    {
        $controllerDir = $projectPath . '/src/Controller';

        foreach (glob($controllerDir . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($controllerDir);
        rmdir($projectPath . '/src');
        rmdir($projectPath);
    }

    // ---------------------------------------------------------------
    // 1. Dossier manquant
    // ---------------------------------------------------------------

    public function testMissingControllerDirProducesNoIssue(): void
    {
        $analyzer = $this->createAnalyzer('/fake/project');
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // ---------------------------------------------------------------
    // 2. createQueryBuilder
    // ---------------------------------------------------------------

    public function testCreateQueryBuilderCreatesCritical(): void
    {
        $projectPath = $this->createTempProjectWithController(<<<'PHP'
            <?php
            class TestController {
                public function index(): void {
                    $qb = $this->entityManager->createQueryBuilder();
                    $qb->select('u')->from(User::class, 'u');
                }
            }
            PHP,
        );

        $analyzer = $this->createAnalyzer($projectPath);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('QueryBuilder', $criticals[0]->getMessage());

        $this->cleanTempProject($projectPath);
    }

    // ---------------------------------------------------------------
    // 3. createQuery (DQL)
    // ---------------------------------------------------------------

    public function testCreateQueryCreatesCritical(): void
    {
        $projectPath = $this->createTempProjectWithController(<<<'PHP'
            <?php
            class TestController {
                public function index(): void {
                    $query = $this->em->createQuery('SELECT u FROM App\Entity\User u');
                }
            }
            PHP,
        );

        $analyzer = $this->createAnalyzer($projectPath);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('DQL', $criticals[0]->getMessage());

        $this->cleanTempProject($projectPath);
    }

    // ---------------------------------------------------------------
    // 4. EntityManager - methodes acceptables
    // ---------------------------------------------------------------

    public function testAllowedEntityManagerMethodsProduceNoWarning(): void
    {
        $projectPath = $this->createTempProjectWithController(<<<'PHP'
            <?php
            class TestController {
                public function create(User $user): void {
                    $this->em->persist($user);
                    $this->em->flush();
                }
                public function delete(User $user): void {
                    $this->em->remove($user);
                    $this->em->flush();
                }
                public function show(int $id): void {
                    $user = $this->em->find(User::class, $id);
                }
            }
            PHP,
        );

        $analyzer = $this->createAnalyzer($projectPath);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));

        $this->cleanTempProject($projectPath);
    }

    // ---------------------------------------------------------------
    // 5. EntityManager - methodes non-acceptables
    // ---------------------------------------------------------------

    public function testProblematicEntityManagerMethodCreatesWarning(): void
    {
        $projectPath = $this->createTempProjectWithController(<<<'PHP'
            <?php
            class TestController {
                public function index(): void {
                    $results = $this->entityManager->getRepository(User::class)->findAll();
                    $conn = $this->entityManager->getConnection();
                }
            }
            PHP,
        );

        $analyzer = $this->createAnalyzer($projectPath);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(1, $report->getIssuesBySeverity(Severity::WARNING));

        $this->cleanTempProject($projectPath);
    }

    // ---------------------------------------------------------------
    // 6. Controller propre
    // ---------------------------------------------------------------

    public function testCleanControllerProducesNoIssue(): void
    {
        $projectPath = $this->createTempProjectWithController(<<<'PHP'
            <?php
            class TestController {
                public function __construct(
                    private readonly UserRepository $userRepository,
                ) {}

                public function index(): Response {
                    $users = $this->userRepository->findAllActive();
                    return $this->render('user/index.html.twig', ['users' => $users]);
                }
            }
            PHP,
        );

        $analyzer = $this->createAnalyzer($projectPath);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());

        $this->cleanTempProject($projectPath);
    }

    // ---------------------------------------------------------------
    // 7. Metadata
    // ---------------------------------------------------------------

    public function testGetModuleReturnsArchitecture(): void
    {
        $this->assertSame(Module::ARCHITECTURE, $this->createAnalyzer('/fake')->getModule());
    }

    public function testGetNameReturnsReadableName(): void
    {
        $this->assertSame('Controller Analyzer', $this->createAnalyzer('/fake')->getName());
    }
}