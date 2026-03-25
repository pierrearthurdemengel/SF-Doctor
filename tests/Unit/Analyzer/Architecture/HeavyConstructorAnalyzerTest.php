<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Architecture;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Architecture\HeavyConstructorAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class HeavyConstructorAnalyzerTest extends TestCase
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

    private function createAnalyzer(): HeavyConstructorAnalyzer
    {
        return new HeavyConstructorAnalyzer($this->tempDir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::ARCHITECTURE]);
    }

    /**
     * Cree un fichier PHP dans le dossier src/Service.
     */
    private function writeServiceFile(string $filename, string $content): void
    {
        $dir = $this->tempDir . '/src/Service';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . $filename, $content);
    }

    // ---------------------------------------------------------------
    // 1. Aucun dossier scanne n'existe - aucun issue
    // ---------------------------------------------------------------

    public function testNoServiceDirDoesNothing(): void
    {
        // Aucun des dossiers src/Service, src/EventListener, src/EventSubscriber
        mkdir($this->tempDir, 0777, true);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // ---------------------------------------------------------------
    // 2. Constructeur avec peu de dependances (3) - aucun issue
    // ---------------------------------------------------------------

    public function testFewDependenciesDoesNothing(): void
    {
        $content = <<<'PHP'
<?php
namespace App\Service;

class LightService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(): void
    {
        // logique metier
    }
}
PHP;

        $this->writeServiceFile('LightService.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // ---------------------------------------------------------------
    // 3. Constructeur avec 9+ dependances - WARNING
    // ---------------------------------------------------------------

    public function testTooManyDependenciesCreatesWarning(): void
    {
        // 9 dependances injectees, depasse le seuil de 8
        $content = "<?php\nnamespace App\\Service;\n\nclass HeavyService\n{\n"
            . "    public function __construct(\n"
            . "        private readonly ServiceA \$a,\n"
            . "        private readonly ServiceB \$b,\n"
            . "        private readonly ServiceC \$c,\n"
            . "        private readonly ServiceD \$d,\n"
            . "        private readonly ServiceE \$e,\n"
            . "        private readonly ServiceF \$f,\n"
            . "        private readonly ServiceG \$g,\n"
            . "        private readonly ServiceH \$h,\n"
            . "        private readonly ServiceI \$i,\n"
            . "    ) {}\n\n"
            . "    public function run(): void {}\n"
            . "}\n";

        $this->writeServiceFile('HeavyService.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('HeavyService.php', $warnings[0]->getMessage());
        $this->assertStringContainsString('9', $warnings[0]->getMessage());
    }

    // ---------------------------------------------------------------
    // 4. Travail dans le constructeur (->findBy) - CRITICAL
    // ---------------------------------------------------------------

    public function testWorkInConstructorCreatesCritical(): void
    {
        // Le constructeur execute une requete via findBy()
        $content = <<<'PHP'
<?php
namespace App\Service;

class EagerService
{
    private array $users;

    public function __construct(
        private readonly UserRepository $repo,
    ) {
        $this->users = $this->repo->findBy(['active' => true]);
    }

    public function getUsers(): array
    {
        return $this->users;
    }
}
PHP;

        $this->writeServiceFile('EagerService.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('EagerService.php', $criticals[0]->getMessage());
        $this->assertStringContainsString('constructeur', $criticals[0]->getMessage());
    }

    // ---------------------------------------------------------------
    // 5. Verification des champs d'enrichissement
    // ---------------------------------------------------------------

    public function testEnrichmentFields(): void
    {
        // 9 dependances pour declencher un warning
        $content = "<?php\nnamespace App\\Service;\n\nclass EnrichService\n{\n"
            . "    public function __construct(\n"
            . "        private readonly ServiceA \$a,\n"
            . "        private readonly ServiceB \$b,\n"
            . "        private readonly ServiceC \$c,\n"
            . "        private readonly ServiceD \$d,\n"
            . "        private readonly ServiceE \$e,\n"
            . "        private readonly ServiceF \$f,\n"
            . "        private readonly ServiceG \$g,\n"
            . "        private readonly ServiceH \$h,\n"
            . "        private readonly ServiceI \$i,\n"
            . "    ) {}\n}\n";

        $this->writeServiceFile('EnrichService.php', $content);

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
        $this->assertSame(60, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('best_practices', $issue->getDocUrl() ?? '');
    }
}
