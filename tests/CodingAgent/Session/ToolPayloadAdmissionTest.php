<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Ineersa\CodingAgent\Session\ToolPayloadAdmission;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ToolPayloadAdmissionTest extends TestCase
{
    public function testLoadedShellProducerAndResultAvoidBodyReplay(): void
    {
        $call = $this->shell();
        $state = $this->state($call);
        $replay = $this->createMock(RunStateRebuilderInterface::class);
        $replay->expects($this->never())->method('rebuildIfStale');
        $events = $this->events();
        $events->expects($this->exactly(2))->method('latestSequenceFor')->with('run')->willReturn(7);
        $admission = $this->admission($state, $replay, $events);

        $this->assertTrue($admission->canPublish($call));
        $this->assertTrue($admission->canPublish(new ToolCallResult('run', 1, 'shell-step', 1, $call->idempotencyKey(), $call->toolCallId, 0)));
    }

    public function testFailedStandaloneShellUsesCommandIdentityNotExecutionKey(): void
    {
        $call = $this->shell();
        $replay = $this->createMock(RunStateRebuilderInterface::class);
        $replay->expects($this->never())->method('rebuildIfStale');
        $events = $this->events();
        $events->method('latestSequenceFor')->willReturn(7);
        $admission = $this->admission($this->state($call), $replay, $events);

        $failure = $admission->failedInvocation($this->reference($call));
        $this->assertTrue($failure->isError);
        $this->assertTrue($failure->result['standalone']);
        $this->assertArrayNotHasKey('arguments', $failure->result);
    }

    public function testFailedAttachedShellDoesNotCompleteModelOperation(): void
    {
        $call = $this->shell();
        $state = $this->state($call)->with(['currentOperation' => new CurrentOperationDTO(1, 'model-step', 1, 'model-key')]);
        $replay = $this->createMock(RunStateRebuilderInterface::class);
        $replay->expects($this->never())->method('rebuildIfStale');
        $events = $this->events();
        $events->method('latestSequenceFor')->willReturn(7);

        $this->assertFalse($this->admission($state, $replay, $events)->failedInvocation($this->reference($call))->result['standalone']);
    }

    public function testLoadedDeferredProducerAvoidsQueuedReplay(): void
    {
        $state = new RunState('run', RunStatus::Running, turnNo: 1, lastSeq: 7, activeStepId: 'step', pendingToolCalls: ['call' => false]);
        $reference = new ToolPayloadReferenceDTO('call', 'run', 1, 'step', 1, 'key', 'call', 0, 'subagent', str_repeat('a', 64), 100);
        $batches = $this->createStub(ToolBatchStoreInterface::class);
        $batches->method('loadCallReference')->willReturn($reference);
        $replay = $this->createMock(RunStateRebuilderInterface::class);
        $replay->expects($this->never())->method('rebuildIfStale');
        $events = $this->events();
        $events->method('latestSequenceFor')->willReturn(7);
        $registry = new TestActiveRunContext();
        $registry->loadRecovered($state);
        $admission = new ToolPayloadAdmission($batches, $registry, $replay, $events, new TestLogger());

        $this->assertTrue($admission->acceptsDeferred($reference, false));
    }

    public function testStaleLoadedStateIsNotTrusted(): void
    {
        $call = $this->shell();
        $state = $this->state($call);
        $replay = $this->createMock(RunStateRebuilderInterface::class);
        $replay->expects($this->once())->method('rebuildIfStale')->with($this->identicalTo($state), 'run')
            ->willReturn(RunStateReplayResult::rebuilt($state->with(['lastSeq' => 8, 'pendingShellToolCalls' => []])));
        $events = $this->events();
        $events->method('latestSequenceFor')->willReturn(8);

        $this->assertFalse($this->admission($state, $replay, $events)->canPublish($call));
    }

    public function testMissingCanonicalHistoryCannotAuthorizeLoadedShell(): void
    {
        $call = $this->shell();
        $replay = $this->createMock(RunStateRebuilderInterface::class);
        $replay->expects($this->never())->method('rebuildIfStale');
        $events = $this->events();
        $events->method('latestSequenceFor')->willReturn(null);

        $this->expectException(ToolPayloadException::class);
        $this->admission($this->state($call), $replay, $events)->canPublish($call);
    }

    private function shell(): ExecuteShellToolCall
    {
        return new ExecuteShellToolCall('run', 1, 'shell-step', 1, 'sh_'.hash('sha256', 'command-key'), 'true', true);
    }

    private function state(ExecuteShellToolCall $call): RunState
    {
        return new RunState('run', RunStatus::Running, turnNo: 1, lastSeq: 7, pendingShellToolCalls: [$call->toolCallId => true], currentOperation: new CurrentOperationDTO(1, 'shell-step', 1, 'command-key'));
    }

    private function reference(ExecuteShellToolCall $call): ToolPayloadReferenceDTO
    {
        return new ToolPayloadReferenceDTO('shell', 'run', 1, 'shell-step', 1, $call->idempotencyKey(), $call->toolCallId, 0, 'bash', str_repeat('a', 64), 100);
    }

    private function events(): EventStoreInterface&MockObject
    {
        $events = $this->createMock(EventStoreInterface::class);
        $events->expects($this->never())->method('allFor');
        $events->expects($this->never())->method('rangeFor');
        $events->expects($this->never())->method('reverseFor');

        return $events;
    }

    private function admission(RunState $state, RunStateRebuilderInterface $replay, EventStoreInterface $events): ToolPayloadAdmission
    {
        $registry = new TestActiveRunContext();
        $registry->loadRecovered($state);

        return new ToolPayloadAdmission($this->createStub(ToolBatchStoreInterface::class), $registry, $replay, $events, new TestLogger());
    }
}
