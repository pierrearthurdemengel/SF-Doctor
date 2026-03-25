<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Architecture;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Architecture\EventSubscriberAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class EventSubscriberAnalyzerTest extends TestCase
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

    private function createAnalyzer(): EventSubscriberAnalyzer
    {
        return new EventSubscriberAnalyzer($this->tempDir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::ARCHITECTURE]);
    }

    /**
     * Cree le dossier src/EventSubscriber et y ecrit un fichier PHP.
     */
    private function writeSubscriberFile(string $filename, string $content): void
    {
        $dir = $this->tempDir . '/src/EventSubscriber';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/' . $filename, $content);
    }

    // ---------------------------------------------------------------
    // 1. Pas de dossier EventSubscriber - aucun issue
    // ---------------------------------------------------------------

    public function testNoSubscriberDirDoesNothing(): void
    {
        // Le dossier src/EventSubscriber n'existe pas du tout
        mkdir($this->tempDir, 0777, true);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // ---------------------------------------------------------------
    // 2. Subscriber court (< 80 lignes) - aucun issue de longueur
    // ---------------------------------------------------------------

    public function testShortSubscriberHasNoIssue(): void
    {
        // Fichier de 30 lignes, sans couplage Doctrine
        $content = "<?php\nnamespace App\\EventSubscriber;\n"
            . str_repeat("// ligne courte\n", 25)
            . "class ShortSubscriber {}\n";

        $this->writeSubscriberFile('ShortSubscriber.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // ---------------------------------------------------------------
    // 3. Subscriber trop long (> 80 lignes) - WARNING
    // ---------------------------------------------------------------

    public function testLongSubscriberCreatesWarning(): void
    {
        // Fichier de ~95 lignes, depasse le seuil de 80
        $content = "<?php\nnamespace App\\EventSubscriber;\n"
            . str_repeat("// ligne de remplissage\n", 90)
            . "class LongSubscriber {}\n";

        $this->writeSubscriberFile('LongSubscriber.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('LongSubscriber.php', $warnings[0]->getMessage());
        $this->assertStringContainsString('volumineux', $warnings[0]->getMessage());
    }

    // ---------------------------------------------------------------
    // 4. Couplage Doctrine detecte (EntityManager, ->persist(, ->flush()
    // ---------------------------------------------------------------

    public function testDoctrineCouplingCreatesWarning(): void
    {
        // Fichier court mais avec des appels Doctrine directs
        $content = <<<'PHP'
<?php
namespace App\EventSubscriber;

class OrderSubscriber
{
    public function __construct(
        private readonly \Doctrine\ORM\EntityManagerInterface $entityManager,
    ) {}

    public function onOrderCreated(OrderEvent $event): void
    {
        $log = new AuditLog($event->getOrder());
        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
PHP;

        $this->writeSubscriberFile('OrderSubscriber.php', $content);

        $analyzer = $this->createAnalyzer();
        $report = $this->createReport();
        $analyzer->analyze($report);

        // On cherche les warnings lies au couplage Doctrine
        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $doctrineWarnings = array_filter(
            $warnings,
            fn ($issue) => str_contains($issue->getMessage(), 'Doctrine')
                || str_contains($issue->getMessage(), 'Couplage'),
        );

        $this->assertNotEmpty($doctrineWarnings, 'Un warning de couplage Doctrine devrait etre cree');
    }

    // ---------------------------------------------------------------
    // 5. Pas de couplage Doctrine - aucun issue de couplage
    // ---------------------------------------------------------------

    public function testNoDoctrineCouplingDoesNothing(): void
    {
        // Fichier court sans aucune reference a Doctrine
        $content = <<<'PHP'
<?php
namespace App\EventSubscriber;

class CleanSubscriber
{
    public function __construct(
        private readonly NotificationService $notifier,
    ) {}

    public function onUserRegistered(UserEvent $event): void
    {
        $this->notifier->send($event->getUser());
    }
}
PHP;

        $this->writeSubscriberFile('CleanSubscriber.php', $content);

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
        // Fichier long pour declencher un warning de longueur
        $content = "<?php\nnamespace App\\EventSubscriber;\n"
            . str_repeat("// ligne de remplissage pour enrichissement\n", 90)
            . "class EnrichTestSubscriber {}\n";

        $this->writeSubscriberFile('EnrichTestSubscriber.php', $content);

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
        $this->assertSame(45, $issue->getEstimatedFixMinutes());
        $this->assertStringContainsString('event_dispatcher', $issue->getDocUrl() ?? '');
    }
}
