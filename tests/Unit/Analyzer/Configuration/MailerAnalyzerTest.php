<?php

// tests/Unit/Analyzer/Configuration/MailerAnalyzerTest.php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Analyzer\Configuration;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Analyzer\Configuration\MailerAnalyzer;
use PierreArthur\SfDoctor\Config\ConfigReaderInterface;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;

class MailerAnalyzerTest extends TestCase
{
    private function makeReport(): AuditReport
    {
        return new AuditReport('/fake/path', [Module::SECURITY]);
    }

    private function makeAnalyzer(?array $mailerConfig): MailerAnalyzer
    {
        $reader = $this->createMock(ConfigReaderInterface::class);
        $reader->method('read')->willReturn($mailerConfig);

        return new MailerAnalyzer($reader);
    }

    // --- Transport null ---

    public function testDetectsNullTransport(): void
    {
        $analyzer = $this->makeAnalyzer([
            'framework' => ['mailer' => ['dsn' => 'null://']],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
        $this->assertStringContainsString('null', $criticals[0]->getMessage());
    }

    public function testDetectsNullNullTransport(): void
    {
        $analyzer = $this->makeAnalyzer([
            'framework' => ['mailer' => ['dsn' => 'null://null']],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
    }

    // --- DSN sous la clé mailer.dsn (ancienne syntaxe) ---

    public function testDetectsNullTransportUnderMailerKey(): void
    {
        $analyzer = $this->makeAnalyzer([
            'mailer' => ['dsn' => 'null://'],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertCount(1, $criticals);
    }

    // --- DSN valide ---

    public function testPassesWithSmtpTransport(): void
    {
        $analyzer = $this->makeAnalyzer([
            'framework' => ['mailer' => ['dsn' => 'smtp://user:pass@smtp.example.com:587']],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::CRITICAL));
        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));
    }

    // --- DSN variable d'env : on ne peut pas conclure ---

    public function testSkipsWhenDsnIsEnvVar(): void
    {
        $analyzer = $this->makeAnalyzer([
            'framework' => ['mailer' => ['dsn' => '%env(MAILER_DSN)%']],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::CRITICAL));
        $this->assertCount(0, $report->getIssuesBySeverity(Severity::WARNING));
    }

    // --- mailer.yaml absent ---

    public function testPassesWhenNoMailerConfig(): void
    {
        $analyzer = $this->makeAnalyzer(null);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::CRITICAL));
    }

    // --- DSN absent dans le fichier ---

    public function testPassesWhenDsnKeyAbsent(): void
    {
        $analyzer = $this->makeAnalyzer([
            'framework' => ['mailer' => []],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $this->assertCount(0, $report->getIssuesBySeverity(Severity::CRITICAL));
    }

    // --- Enrichissement ---

    public function testIssueHasDocUrlAndFixCode(): void
    {
        $analyzer = $this->makeAnalyzer([
            'framework' => ['mailer' => ['dsn' => 'null://']],
        ]);

        $report = $this->makeReport();
        $analyzer->analyze($report);

        $criticals = $report->getIssuesBySeverity(Severity::CRITICAL);
        $this->assertNotNull($criticals[0]->getDocUrl());
        $this->assertNotNull($criticals[0]->getFixCode());
    }
}