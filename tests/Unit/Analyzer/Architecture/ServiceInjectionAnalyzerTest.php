<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Architecture;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Architecture\ServiceInjectionAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class ServiceInjectionAnalyzerTest extends TestCase
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

    private function createAnalyzer(): ServiceInjectionAnalyzer
    {
        return new ServiceInjectionAnalyzer($this->tempDir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::ARCHITECTURE]);
    }

    /**
     * Cree un fichier PHP dans le dossier src/Service.
     */
    private function writeSrcFile(string $relativePath, string $content): void
    {
        $fullPath = $this->tempDir . '/src/' . $relativePath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($fullPath, $content);
    }

    // ---------------------------------------------------------------
    // 1. Pas de dossier src - aucun issue
    // ---------------------------------------------------------------

    public function testNoSrcDirDoesNothing(): void
    {
        // Le dossier src/ n'existe pas du tout
        mkdir($this->tempDir, 0777, true);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // ---------------------------------------------------------------
    // 2. Injection du ContainerInterface dans le constructeur - CRITICAL
    // ---------------------------------------------------------------

    public function testContainerInjectionCreatesCritical(): void
    {
        $content = <<<'PHP'
<?php
namespace App\Service;

use Symfony\Component\DependencyInjection\ContainerInterface;

class LegacyService
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function doSomething(): void
    {
        $mailer = $this->container->get('mailer');
    }
}
PHP;

        $this->writeSrcFile('Service/LegacyService.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        // Injection du ContainerInterface + usage de $this->container->get()
        $this->assertGreaterThanOrEqual(1, count($criticals));

        // Verifier qu'il y a au moins un issue pour l'injection du ContainerInterface
        $injectionIssues = array_filter(
            $criticals,
            fn ($issue) => str_contains($issue->getMessage(), 'ContainerInterface'),
        );
        $this->assertNotEmpty($injectionIssues, 'Un CRITICAL pour l\'injection du ContainerInterface');
    }

    // ---------------------------------------------------------------
    // 3. Usage de $this->container->get() - CRITICAL
    // ---------------------------------------------------------------

    public function testContainerGetCreatesCritical(): void
    {
        $content = <<<'PHP'
<?php
namespace App\Service;

class ServiceLocatorService
{
    public function handleRequest(): void
    {
        $logger = $this->container->get('logger');
        $logger->info('test');
    }
}
PHP;

        $this->writeSrcFile('Service/ServiceLocatorService.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('container->get()', $criticals[0]->getMessage());
    }

    // ---------------------------------------------------------------
    // 4. Service normal sans container - aucun issue
    // ---------------------------------------------------------------

    public function testNormalServiceDoesNothing(): void
    {
        $content = <<<'PHP'
<?php
namespace App\Service;

class CleanService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MailerInterface $mailer,
    ) {}

    public function process(): void
    {
        $users = $this->userRepository->findAll();
        $this->mailer->send($users);
    }
}
PHP;

        $this->writeSrcFile('Service/CleanService.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // ---------------------------------------------------------------
    // 5. ContainerAwareInterface est exclu (legacy accepte)
    // ---------------------------------------------------------------

    public function testContainerAwareIsExcluded(): void
    {
        // Un service qui implemente ContainerAwareInterface est ignore
        $content = <<<'PHP'
<?php
namespace App\Service;

use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class LegacyContainerAware implements ContainerAwareInterface
{
    private ContainerInterface $container;

    public function setContainer(?ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function doWork(): void
    {
        $service = $this->container->get('some.service');
    }
}
PHP;

        $this->writeSrcFile('Service/LegacyContainerAware.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        // Le fichier contient ContainerAwareInterface, donc il est exclu
        $this->assertCount(0, $report->getIssues());
    }

    // ---------------------------------------------------------------
    // 6. Verification des champs d'enrichissement
    // ---------------------------------------------------------------

    public function testEnrichmentFields(): void
    {
        $content = <<<'PHP'
<?php
namespace App\Service;

use Symfony\Component\DependencyInjection\ContainerInterface;

class EnrichTestService
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}
}
PHP;

        $this->writeSrcFile('Service/EnrichTestService.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode(), 'fixCode ne doit pas etre null');
        $this->assertNotNull($issue->getDocUrl(), 'docUrl ne doit pas etre null');
        $this->assertNotNull($issue->getBusinessImpact(), 'businessImpact ne doit pas etre null');
        $this->assertNotNull($issue->getEstimatedFixMinutes(), 'estimatedFixMinutes ne doit pas etre null');
        $this->assertSame(30, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('service_container', $issue->getDocUrl() ?? '');
    }
}
