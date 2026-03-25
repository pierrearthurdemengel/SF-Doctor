<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Messenger;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Messenger\UnhandledMessageAnalyzer;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste la detection des messages Messenger sans handler associe.
 * L'analyzer scanne src/Message/ pour les classes de messages,
 * puis verifie qu'un handler existe dans src/ pour chacune.
 */
final class UnhandledMessageAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_test_' . uniqid();
        mkdir($this->tempDir . '/src/Message', 0777, true);
        mkdir($this->tempDir . '/src/MessageHandler', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    // --- Cas sans probleme ---

    /**
     * Si le dossier src/Message n'existe pas, l'analyzer ne fait rien.
     */
    public function testNoMessageDirDoesNothing(): void
    {
        $analyzer = new UnhandledMessageAnalyzer($this->tempDir . '/nonexistent');
        $report = new AuditReport('/fake/project', [Module::MESSENGER]);

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Un message avec un handler #[AsMessageHandler] ne doit pas etre signale.
     */
    public function testMessageWithHandlerDoesNothing(): void
    {
        // Cree le message.
        $messageContent = "<?php\nnamespace App\\Message;\n\n"
            . "class SendEmailNotification\n"
            . "{\n"
            . "    public function __construct(public readonly string \$email) {}\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Message/SendEmailNotification.php', $messageContent);

        // Cree le handler correspondant.
        $handlerContent = "<?php\nnamespace App\\MessageHandler;\n\n"
            . "use Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler;\n\n"
            . "#[AsMessageHandler]\n"
            . "class SendEmailNotificationHandler\n"
            . "{\n"
            . "    public function __invoke(SendEmailNotification \$message): void\n"
            . "    {\n"
            . "        // Envoi de l'email\n"
            . "    }\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/MessageHandler/SendEmailNotificationHandler.php', $handlerContent);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Un message avec un handler implementant MessageHandlerInterface
     * ne doit pas etre signale non plus.
     */
    public function testMessageWithInterfaceHandlerDoesNothing(): void
    {
        $messageContent = "<?php\nnamespace App\\Message;\n\n"
            . "class ProcessPayment\n"
            . "{\n"
            . "    public function __construct(public readonly int \$orderId) {}\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Message/ProcessPayment.php', $messageContent);

        $handlerContent = "<?php\nnamespace App\\MessageHandler;\n\n"
            . "use Symfony\\Component\\Messenger\\Handler\\MessageHandlerInterface;\n\n"
            . "class ProcessPaymentHandler implements MessageHandlerInterface\n"
            . "{\n"
            . "    public function __invoke(ProcessPayment \$message): void\n"
            . "    {\n"
            . "        // Traitement du paiement\n"
            . "    }\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/MessageHandler/ProcessPaymentHandler.php', $handlerContent);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    // --- Cas avec probleme ---

    /**
     * Un message dans src/Message/ sans aucun handler dans src/ est signale CRITICAL.
     * Quand ce message sera dispatche, Symfony levera NoHandlerForMessageException.
     */
    public function testUnhandledMessageCreatesCritical(): void
    {
        $messageContent = "<?php\nnamespace App\\Message;\n\n"
            . "class GenerateReport\n"
            . "{\n"
            . "    public function __construct(public readonly int \$reportId) {}\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Message/GenerateReport.php', $messageContent);

        $report = $this->runAnalyzer();

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertSame(Module::MESSENGER, $criticals[0]->getModule());
        $this->assertStringContainsString('GenerateReport', $criticals[0]->getMessage());
        $this->assertStringContainsString('sans handler', $criticals[0]->getMessage());
    }

    /**
     * Si src/Message contient un fichier PHP qui n'est pas une classe (interface, trait),
     * l'analyzer doit l'ignorer.
     */
    public function testNonClassFileIsIgnored(): void
    {
        // Un fichier sans declaration de classe ne devrait pas etre detecte comme message.
        $content = "<?php\nnamespace App\\Message;\n\n"
            . "interface MessageInterface\n"
            . "{\n"
            . "    public function getId(): int;\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Message/MessageInterface.php', $content);

        $report = $this->runAnalyzer();

        $this->assertCount(0, $report->getIssues());
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue CRITICAL contient les champs d'enrichissement.
     */
    public function testEnrichmentFields(): void
    {
        $messageContent = "<?php\nnamespace App\\Message;\n\n"
            . "class SyncInventory\n"
            . "{\n"
            . "    public function __construct(public readonly int \$warehouseId) {}\n"
            . "}\n";
        file_put_contents($this->tempDir . '/src/Message/SyncInventory.php', $messageContent);

        $report = $this->runAnalyzer();

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);

        $issue = $criticals[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('AsMessageHandler', $issue->getFixCode() ?? '');
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('symfony.com', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(20, $issue->getEstimatedFixMinutes());
        $this->assertNotNull($issue->getFile());
        $this->assertStringContainsString('SyncInventory.php', $issue->getFile() ?? '');
    }

    // --- Helpers ---

    private function runAnalyzer(): AuditReport
    {
        $analyzer = new UnhandledMessageAnalyzer($this->tempDir);
        $report = new AuditReport('/fake/project', [Module::MESSENGER]);
        $analyzer->analyze($report);

        return $report;
    }

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
}
