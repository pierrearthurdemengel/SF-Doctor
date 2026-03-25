<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Messenger;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Messenger\UnserializableMessageAnalyzer;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class UnserializableMessageAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_unserializable_' . uniqid();
        mkdir($this->tempDir . '/src/Message', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::MESSENGER]);
    }

    // --- Test 1 : Message avec Closure => CRITICAL ---

    public function testClosurePropertyCreatesCritical(): void
    {
        file_put_contents(
            $this->tempDir . '/src/Message/ProcessDataMessage.php',
            '<?php
            class ProcessDataMessage {
                private \Closure $callback;
            }'
        );

        $analyzer = new UnserializableMessageAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('Closure', $criticals[0]->getMessage());
    }

    // --- Test 2 : Message avec proprietes publiques => OK ---

    public function testPublicPropertiesNoIssue(): void
    {
        file_put_contents(
            $this->tempDir . '/src/Message/SendEmailMessage.php',
            '<?php
            class SendEmailMessage {
                public readonly string $email;
                public readonly string $subject;
            }'
        );

        $analyzer = new UnserializableMessageAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 3 : Message avec getters => OK ---

    public function testWithGettersNoIssue(): void
    {
        file_put_contents(
            $this->tempDir . '/src/Message/NotifyMessage.php',
            '<?php
            class NotifyMessage {
                private string $content;
                public function getContent(): string { return $this->content; }
            }'
        );

        $analyzer = new UnserializableMessageAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 4 : Message sans proprietes publiques ni getters => WARNING ---

    public function testNoPublicPropertiesOrGettersCreatesWarning(): void
    {
        file_put_contents(
            $this->tempDir . '/src/Message/SecretMessage.php',
            '<?php
            class SecretMessage {
                private string $data;
                private int $priority;
            }'
        );

        $analyzer = new UnserializableMessageAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('SecretMessage', $warnings[0]->getMessage());
    }

    // --- Test 5 : Pas de dossier src/Message => aucune issue ---

    public function testNoMessageDirDoesNothing(): void
    {
        $analyzer = new UnserializableMessageAnalyzer($this->tempDir . '/nonexistent');
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 6 : Message avec promoted public properties => OK ---

    public function testPromotedPublicPropertiesNoIssue(): void
    {
        file_put_contents(
            $this->tempDir . '/src/Message/CreateUserMessage.php',
            '<?php
            class CreateUserMessage {
                public function __construct(
                    public readonly string $email,
                    public readonly string $name,
                ) {}
            }'
        );

        $analyzer = new UnserializableMessageAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 7 : getName et getModule ---

    public function testGetNameAndGetModule(): void
    {
        $analyzer = new UnserializableMessageAnalyzer($this->tempDir);

        $this->assertSame('Unserializable Message Analyzer', $analyzer->getName());
        $this->assertSame(Module::MESSENGER, $analyzer->getModule());
    }

    // --- Test 8 : supports retourne true si Messenger present ---

    public function testSupportsWithMessenger(): void
    {
        $analyzer = new UnserializableMessageAnalyzer($this->tempDir);
        $context = new ProjectContext(
            projectPath: '/fake',
            hasDoctrineOrm: false,
            hasMessenger: true,
            hasApiPlatform: false,
            hasTwig: false,
            hasSecurityBundle: false,
            hasWebProfilerBundle: false,
            hasMailer: false,
            hasNelmioCors: false,
            hasNelmioSecurity: false,
            hasJwtAuth: false,
            symfonyVersion: null,
        );
        $this->assertTrue($analyzer->supports($context));
    }

    // --- Test 9 : Enrichment fields ---

    public function testEnrichmentFields(): void
    {
        file_put_contents(
            $this->tempDir . '/src/Message/BadMessage.php',
            '<?php
            class BadMessage {
                private \Closure $handler;
            }'
        );

        $analyzer = new UnserializableMessageAnalyzer($this->tempDir);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $issue = $report->getIssues()[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
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
