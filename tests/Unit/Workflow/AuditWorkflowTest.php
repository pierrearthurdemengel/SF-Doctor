<?php

declare(strict_types=1);

namespace PierreArthur\SfDoctor\Tests\Unit\Workflow;

use PHPUnit\Framework\TestCase;
use PierreArthur\SfDoctor\Workflow\AuditContext;
use PierreArthur\SfDoctor\Workflow\AuditWorkflow;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;

final class AuditWorkflowTest extends TestCase
{
    private \Symfony\Component\Workflow\StateMachine $workflow;

    protected function setUp(): void
    {
        $this->workflow = AuditWorkflow::create();
    }

    public function testInitialStatusIsPending(): void
    {
        $context = new AuditContext();

        $this->assertSame(AuditWorkflow::STATUS_PENDING, $context->getStatus());
    }

    public function testCanStartFromPending(): void
    {
        $context = new AuditContext();

        $this->assertTrue($this->workflow->can($context, AuditWorkflow::TRANSITION_START));
    }

    public function testStartTransitionSetsRunning(): void
    {
        $context = new AuditContext();
        $this->workflow->apply($context, AuditWorkflow::TRANSITION_START);

        $this->assertSame(AuditWorkflow::STATUS_RUNNING, $context->getStatus());
    }

    public function testCompleteTransitionSetsCompleted(): void
    {
        $context = new AuditContext();
        $this->workflow->apply($context, AuditWorkflow::TRANSITION_START);
        $this->workflow->apply($context, AuditWorkflow::TRANSITION_COMPLETE);

        $this->assertSame(AuditWorkflow::STATUS_COMPLETED, $context->getStatus());
    }

    public function testFailTransitionSetsFailed(): void
    {
        $context = new AuditContext();
        $this->workflow->apply($context, AuditWorkflow::TRANSITION_START);
        $this->workflow->apply($context, AuditWorkflow::TRANSITION_FAIL);

        $this->assertSame(AuditWorkflow::STATUS_FAILED, $context->getStatus());
    }

    public function testCannotCompleteFromPending(): void
    {
        $context = new AuditContext();

        $this->assertFalse($this->workflow->can($context, AuditWorkflow::TRANSITION_COMPLETE));
    }

    public function testCannotFailFromPending(): void
    {
        $context = new AuditContext();

        $this->assertFalse($this->workflow->can($context, AuditWorkflow::TRANSITION_FAIL));
    }

    public function testApplyInvalidTransitionThrows(): void
    {
        $context = new AuditContext();

        $this->expectException(NotEnabledTransitionException::class);

        // Tentative de complete sans passer par start.
        $this->workflow->apply($context, AuditWorkflow::TRANSITION_COMPLETE);
    }

    public function testCannotStartFromRunning(): void
    {
        $context = new AuditContext();
        $this->workflow->apply($context, AuditWorkflow::TRANSITION_START);

        $this->assertFalse($this->workflow->can($context, AuditWorkflow::TRANSITION_START));
    }
}