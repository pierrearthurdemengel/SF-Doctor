<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Message;

use PierreArthur\SfDoctor\Analyzer\AnalyzerInterface;
use PierreArthur\SfDoctor\Message\RunAnalyzerMessage;
use PierreArthur\SfDoctor\Message\RunAnalyzerMessageHandler;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use PierreArthur\SfDoctor\Model\Severity;
use PierreArthur\SfDoctor\Model\Issue;
use PHPUnit\Framework\TestCase;

final class RunAnalyzerMessageHandlerTest extends TestCase
{
    public function testInvokeRunsMatchingAnalyzer(): void
    {
        $analyzer = $this->createMock(AnalyzerInterface::class);
        $analyzer->method('supports')->willReturn(true);
        $analyzer->expects($this->once())->method('analyze');

        $handler = new RunAnalyzerMessageHandler([$analyzer]);
        $message = new RunAnalyzerMessage($analyzer::class, '/project', []);

        $result = $handler($message);

        $this->assertInstanceOf(AuditReport::class, $result);
    }

    public function testInvokeReturnsEmptyReportIfAnalyzerNotFound(): void
    {
        $handler = new RunAnalyzerMessageHandler([]);
        $message = new RunAnalyzerMessage('NonExistentAnalyzer', '/project', []);

        $result = $handler($message);

        $this->assertInstanceOf(AuditReport::class, $result);
        $this->assertCount(0, $result->getIssues());
    }

    public function testInvokeReturnsEmptyReportIfAnalyzerDoesNotSupport(): void
    {
        $analyzer = $this->createMock(AnalyzerInterface::class);
        $analyzer->method('supports')->willReturn(false);
        $analyzer->expects($this->never())->method('analyze');

        $handler = new RunAnalyzerMessageHandler([$analyzer]);
        $message = new RunAnalyzerMessage($analyzer::class, '/project', []);

        $result = $handler($message);

        $this->assertInstanceOf(AuditReport::class, $result);
        $this->assertCount(0, $result->getIssues());
    }
}