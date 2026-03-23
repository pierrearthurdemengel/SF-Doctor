<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\EventSubscriber;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Event\AnalysisCompletedEvent;
use PierreArthur\SfDoctor\Event\AnalysisStartedEvent;
use PierreArthur\SfDoctor\Event\ModuleCompletedEvent;
use PierreArthur\SfDoctor\EventSubscriber\ProgressSubscriber;
use PierreArthur\SfDoctor\Model\AuditReport;
use PierreArthur\SfDoctor\Model\Module;
use Symfony\Component\Console\Output\BufferedOutput;

final class ProgressSubscriberTest extends TestCase
{
    // ---------------------------------------------------------------
    // 1. getSubscribedEvents() déclare les bons events
    // ---------------------------------------------------------------
    public function testGetSubscribedEventsReturnsExpectedEvents(): void
    {
        $events = ProgressSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(AnalysisStartedEvent::NAME, $events);
        $this->assertArrayHasKey(ModuleCompletedEvent::NAME, $events);
        $this->assertArrayHasKey(AnalysisCompletedEvent::NAME, $events);
    }

    // ---------------------------------------------------------------
    // 2. onAnalysisStarted affiche le chemin et le nombre d'analyzers
    // ---------------------------------------------------------------
    public function testOnAnalysisStartedWritesProjectPathAndCount(): void
    {
        $output = new BufferedOutput();
        $subscriber = new ProgressSubscriber($output);

        $event = new AnalysisStartedEvent('/var/www/my-project', 5);
        $subscriber->onAnalysisStarted($event);

        $display = $output->fetch();
        $this->assertStringContainsString('/var/www/my-project', $display);
        $this->assertStringContainsString('5', $display);
    }

    // ---------------------------------------------------------------
    // 3. onModuleCompleted affiche le module et le nombre d'issues
    // ---------------------------------------------------------------
    public function testOnModuleCompletedWritesModuleAndIssueCount(): void
    {
        $output = new BufferedOutput();
        $subscriber = new ProgressSubscriber($output);

        $event = new ModuleCompletedEvent(Module::SECURITY, 3);
        $subscriber->onModuleCompleted($event);

        $display = $output->fetch();
        $this->assertStringContainsString('security', $display);
        $this->assertStringContainsString('3', $display);
    }

    // ---------------------------------------------------------------
    // 4. onAnalysisCompleted affiche la durée et le score
    // ---------------------------------------------------------------
    public function testOnAnalysisCompletedWritesDurationAndScore(): void
    {
        $output = new BufferedOutput();
        $subscriber = new ProgressSubscriber($output);

        $report = new AuditReport(
            projectPath: '/var/www/my-project',
            modules: [Module::SECURITY],
        );
        $report->complete();

        $event = new AnalysisCompletedEvent($report, 0.342);
        $subscriber->onAnalysisCompleted($event);

        $display = $output->fetch();
        $this->assertStringContainsString('0.34', $display);
        $this->assertStringContainsString('100', $display);
    }

    // ---------------------------------------------------------------
    // 5. Module avec zéro issue s'affiche correctement
    // ---------------------------------------------------------------
    public function testModuleWithZeroIssuesDisplaysCorrectly(): void
    {
        $output = new BufferedOutput();
        $subscriber = new ProgressSubscriber($output);

        $event = new ModuleCompletedEvent(Module::ARCHITECTURE, 0);
        $subscriber->onModuleCompleted($event);

        $display = $output->fetch();
        $this->assertStringContainsString('architecture', $display);
        $this->assertStringContainsString('0', $display);
    }
}