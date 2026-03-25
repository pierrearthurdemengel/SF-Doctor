<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Messenger;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Messenger\MessengerSigningAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Context\ProjectContext;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

final class MessengerSigningAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sf_doctor_signing_' . uniqid();
        mkdir($this->tempDir . '/src', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::MESSENGER]);
    }

    private function createAnalyzer(?array $messengerConfig): MessengerSigningAnalyzer
    {
        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn($messengerConfig);

        return new MessengerSigningAnalyzer($configReader, $this->tempDir);
    }

    private function writeComposerLock(string $messengerVersion): void
    {
        $lock = [
            'packages' => [
                ['name' => 'symfony/messenger', 'version' => $messengerVersion],
            ],
        ];
        file_put_contents($this->tempDir . '/composer.lock', json_encode($lock));
    }

    private function writeDangerousHandler(): void
    {
        file_put_contents(
            $this->tempDir . '/src/DangerousHandler.php',
            '<?php
            use Symfony\Component\Messenger\Attribute\AsMessageHandler;
            use Symfony\Component\Process\Messenger\RunProcessHandler;

            class DangerousHandler {
                private RunProcessHandler $handler;
            }'
        );
    }

    // --- Test 1 : Pas de config Messenger => aucune issue ---

    public function testNoConfigDoesNothing(): void
    {
        $analyzer = $this->createAnalyzer(null);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 2 : Symfony >= 7.4 + handlers dangereux + transport non signe => CRITICAL ---

    public function testDangerousHandlersWithoutSigningCreatesCritical(): void
    {
        $this->writeComposerLock('7.4.0');
        $this->writeDangerousHandler();

        $analyzer = $this->createAnalyzer([
            'framework' => [
                'messenger' => [
                    'transports' => [
                        'async' => [
                            'dsn' => 'doctrine://default',
                        ],
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('signature', $criticals[0]->getMessage());
    }

    // --- Test 3 : Symfony >= 7.4 + handlers dangereux + transport signe => OK ---

    public function testDangerousHandlersWithSigningNoIssue(): void
    {
        $this->writeComposerLock('7.4.0');
        $this->writeDangerousHandler();

        $analyzer = $this->createAnalyzer([
            'framework' => [
                'messenger' => [
                    'transports' => [
                        'async' => [
                            'dsn' => 'doctrine://default',
                            'sign' => true,
                        ],
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 4 : Symfony >= 7.4 + transport AMQP sans signing => WARNING ---

    public function testAmqpWithoutSigningCreatesWarning(): void
    {
        $this->writeComposerLock('7.4.0');

        $analyzer = $this->createAnalyzer([
            'framework' => [
                'messenger' => [
                    'transports' => [
                        'async' => [
                            'dsn' => 'amqp://guest:guest@localhost/%2f/messages',
                        ],
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('reseau', $warnings[0]->getMessage());
    }

    // --- Test 5 : Symfony < 7.4 + handlers dangereux => WARNING ---

    public function testOldSymfonyWithDangerousHandlersCreatesWarning(): void
    {
        $this->writeComposerLock('6.4.12');
        $this->writeDangerousHandler();

        $analyzer = $this->createAnalyzer([
            'framework' => [
                'messenger' => [
                    'transports' => [
                        'async' => 'doctrine://default',
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('signature', $warnings[0]->getMessage());
    }

    // --- Test 6 : Symfony < 7.4 sans handlers dangereux => OK ---

    public function testOldSymfonyWithoutDangerousHandlersNoIssue(): void
    {
        $this->writeComposerLock('6.4.12');

        $analyzer = $this->createAnalyzer([
            'framework' => [
                'messenger' => [
                    'transports' => [
                        'async' => 'doctrine://default',
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Test 7 : getName et getModule ---

    public function testGetNameAndGetModule(): void
    {
        $analyzer = $this->createAnalyzer(null);

        $this->assertSame('Messenger Signing Analyzer', $analyzer->getName());
        $this->assertSame(Module::MESSENGER, $analyzer->getModule());
    }

    // --- Test 8 : supports retourne true si Messenger present ---

    public function testSupportsWithMessenger(): void
    {
        $analyzer = $this->createAnalyzer(null);
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
        $this->writeComposerLock('6.4.12');
        $this->writeDangerousHandler();

        $analyzer = $this->createAnalyzer([
            'framework' => [
                'messenger' => [
                    'transports' => [
                        'async' => 'doctrine://default',
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $issue = $report->getIssues()[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertNotNull($issue->getDocUrl());
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertNotNull($issue->getEstimatedFixMinutes());
    }

    // --- Test 10 : Transport 'failed' ignore ---

    public function testFailedTransportIgnored(): void
    {
        $this->writeComposerLock('7.4.0');

        $analyzer = $this->createAnalyzer([
            'framework' => [
                'messenger' => [
                    'transports' => [
                        'failed' => [
                            'dsn' => 'amqp://guest:guest@localhost',
                        ],
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
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
