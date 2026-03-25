<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Messenger;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Messenger\MessengerTransportAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

/**
 * Teste l'analyse de la configuration des transports Messenger.
 * L'analyzer verifie le routage sync, l'absence de failure_transport,
 * et l'absence de retry_strategy sur les transports asynchrones.
 */
final class MessengerTransportAnalyzerTest extends TestCase
{
    // --- Cas sans probleme ---

    /**
     * Si le fichier messenger.yaml n'existe pas, l'analyzer ne fait rien.
     */
    public function testNullConfigDoesNothing(): void
    {
        $analyzer = $this->createAnalyzer(null);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Config complete et correcte : failure_transport present,
     * pas de routage sync, retry_strategy sur le transport async.
     */
    public function testProperConfigCreatesNoIssue(): void
    {
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'messenger' => [
                    'failure_transport' => 'failed',
                    'transports' => [
                        'async' => [
                            'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%',
                            'retry_strategy' => [
                                'max_retries' => 3,
                                'delay' => 1000,
                            ],
                        ],
                        'failed' => 'doctrine://default?queue_name=failed',
                    ],
                    'routing' => [
                        'App\\Message\\SendEmail' => 'async',
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    /**
     * Si la config ne contient pas la cle 'messenger', l'analyzer ne fait rien.
     */
    public function testMissingMessengerKeyDoesNothing(): void
    {
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'cache' => ['app' => 'cache.adapter.redis'],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssues());
    }

    // --- Cas avec probleme : routage sync ---

    /**
     * Un message route vers le transport 'sync' defait l'interet de Messenger.
     * L'analyzer doit signaler un WARNING.
     */
    public function testSyncRoutingCreatesWarning(): void
    {
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'messenger' => [
                    'failure_transport' => 'failed',
                    'transports' => [
                        'failed' => 'doctrine://default?queue_name=failed',
                    ],
                    'routing' => [
                        'App\\Message\\HeavyReport' => 'sync',
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('sync', $warnings[0]->getMessage());
        $this->assertStringContainsString('HeavyReport', $warnings[0]->getMessage());
    }

    // --- Cas avec probleme : failure_transport manquant ---

    /**
     * Sans failure_transport, les messages en echec sont perdus definitivement.
     * L'analyzer doit signaler un WARNING.
     */
    public function testMissingFailureTransportCreatesWarning(): void
    {
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'messenger' => [
                    'transports' => [
                        'async' => [
                            'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%',
                            'retry_strategy' => [
                                'max_retries' => 3,
                            ],
                        ],
                    ],
                    'routing' => [
                        'App\\Message\\Notification' => 'async',
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('failed', $warnings[0]->getMessage());
    }

    // --- Cas avec probleme : retry_strategy manquante ---

    /**
     * Un transport async sans retry_strategy utilise les valeurs par defaut.
     * L'analyzer doit signaler une SUGGESTION (transport = simple string DSN).
     */
    public function testMissingRetryStrategyOnStringDsnCreatesSuggestion(): void
    {
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'messenger' => [
                    'failure_transport' => 'failed',
                    'transports' => [
                        'async' => 'doctrine://default',
                        'failed' => 'doctrine://default?queue_name=failed',
                    ],
                    'routing' => [
                        'App\\Message\\Notification' => 'async',
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(1, $suggestions);
        $this->assertStringContainsString('retry_strategy', $suggestions[0]->getMessage());
        $this->assertStringContainsString('async', $suggestions[0]->getMessage());
    }

    /**
     * Un transport async configure en tableau sans retry_strategy
     * est aussi signale comme SUGGESTION.
     */
    public function testMissingRetryStrategyOnArrayConfigCreatesSuggestion(): void
    {
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'messenger' => [
                    'failure_transport' => 'failed',
                    'transports' => [
                        'async' => [
                            'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%',
                            // Pas de retry_strategy
                        ],
                        'failed' => 'doctrine://default?queue_name=failed',
                    ],
                    'routing' => [],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(1, $suggestions);
        $this->assertStringContainsString('retry_strategy', $suggestions[0]->getMessage());
    }

    /**
     * Les transports 'sync' et 'failed' ne necessitent pas de retry_strategy,
     * l'analyzer ne doit pas les signaler.
     */
    public function testSyncAndFailedTransportsAreExcludedFromRetryCheck(): void
    {
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'messenger' => [
                    'failure_transport' => 'failed',
                    'transports' => [
                        'sync' => 'sync://',
                        'failed' => 'doctrine://default?queue_name=failed',
                    ],
                    'routing' => [],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        // Aucune suggestion pour les transports exclus.
        $suggestions = $report->getIssuesBySeverity(Severity::SUGGESTION);
        $this->assertCount(0, $suggestions);
    }

    // --- Enrichissement ---

    /**
     * Verifie que l'issue WARNING (sync routing) contient les champs d'enrichissement.
     */
    public function testEnrichmentFields(): void
    {
        $analyzer = $this->createAnalyzer([
            'framework' => [
                'messenger' => [
                    'failure_transport' => 'failed',
                    'transports' => [
                        'failed' => 'doctrine://default?queue_name=failed',
                    ],
                    'routing' => [
                        'App\\Message\\ImportData' => 'sync',
                    ],
                ],
            ],
        ]);
        $report = $this->createReport();

        $analyzer->analyze($report);

        $warnings = $report->getIssuesBySeverity(Severity::WARNING);
        $this->assertCount(1, $warnings);

        $issue = $warnings[0];
        $this->assertNotNull($issue->getFixCode());
        $this->assertStringContainsString('async', $issue->getFixCode() ?? '');
        $this->assertNotNull($issue->getDocUrl());
        $this->assertStringContainsString('symfony.com', $issue->getDocUrl() ?? '');
        $this->assertNotNull($issue->getBusinessImpact());
        $this->assertSame(10, $issue->getEstimatedFixMinutes());
        $this->assertSame('config/packages/messenger.yaml', $issue->getFile());
    }

    // --- Helpers ---

    /**
     * Cree un analyzer avec un mock du ConfigReader.
     *
     * @param array<mixed>|null $config Ce que read() retournera
     */
    private function createAnalyzer(?array $config): MessengerTransportAnalyzer
    {
        $configReader = $this->createMock(ConfigReaderInterface::class);
        $configReader->method('read')->willReturn($config);

        return new MessengerTransportAnalyzer($configReader);
    }

    private function createReport(): AuditReport
    {
        return new AuditReport('/fake/project', [Module::MESSENGER]);
    }
}
