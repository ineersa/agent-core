<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\CodeMode;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\RedriveDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\RunCancellationToken;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionOriginEnum;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeAcknowledgementHandler;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionHookSubscriber;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionReasonEnum;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Runtime\Controller\CodeModeControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\History\HistoryTailDiscardService;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class CodeModeInterruptionHookSubscriberTest extends IsolatedKernelTestCase
{
    public function testStartupFailureBeforeReadinessSettlesTheOriginalCaller(): void
    {
        [$f, $id] = $this->begin();
        $result = new ToolCallResult($f->call->runId(), 1, 'step', 1, $f->call->idempotencyKey(), 'cm', 0, ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => 'Host failed before readiness.']]], true);
        $c = self::getContainer();
        $c->get(CodeModeExecutionService::class)->preserve($id, null, new \RuntimeException('Host failed before readiness.'));
        $payloads = $c->get(ToolPayloadStoreInterface::class);
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $invocation = $deferred->findInvocationReferenceByDeferredId($id) ?? throw new \RuntimeException('Missing invocation.');
        $accepted = $payloads->retainProcessedResult($invocation, $result);
        $deferred->acceptResult($id, $accepted);
        $c->get(CompleteDeferredToolCallHandler::class)(new CompleteDeferredToolCall($id, \is_array($result->result['content'] ?? null) ? $result->result['content'] : [], \is_array($result->result['details'] ?? null) ? $result->result['details'] : null, $result->isError, $result->error));
        $transport = $c->get('messenger.transport.run_control');
        $bus = $c->get('agent.command.bus');
        $seen = 0;
        for ($i = 0; $i < 8; ++$i) {
            $sent = $transport->getSent();
            if ($seen === \count($sent)) {
                break;
            }
            for (; $seen < \count($sent); ++$seen) {
                $envelope = $sent[$seen];
                if ($envelope->getMessage() instanceof ToolPayloadMessage || $envelope->getMessage() instanceof ToolCallResult) {
                    $bus->dispatch($envelope->with(new ReceivedStamp('run_control')));
                }
            }
        }
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($f->state->runId);
        $terminal = array_values(array_filter($events, static fn (RunEvent $event): bool => 'tool_execution_end' === $event->type));
        $this->assertCount(1, $terminal);
        $this->assertTrue($terminal[0]->payload['tool_result']['is_error']);
        $this->assertSame('completed', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
        $this->assertFalse((bool) $c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm')['ready']);
    }

    public function testCapturedCancellationSurvivesParentRevivalWithoutPollingTheCancellingState(): void
    {
        [$f, $id] = $this->begin();
        $c = self::getContainer();
        $service = $c->get(CodeModeExecutionService::class);
        $token = $service->cancellationToken($id, new RunCancellationToken($c->get(RunOperationalStatusReaderInterface::class), $f->state->runId));
        $this->assertFalse($token->isCancellationRequested());
        $cancelled = $f->state->with(['status' => RunStatus::Cancelling]);
        $hook = $c->get(CodeModeInterruptionHookSubscriber::class);
        $actions = $hook->prepareAfterTurnCommit(AfterTurnCommitHookContext::fromRunState($cancelled, [], 0), $f->state->lastSeq);
        $this->assertSame([$id], $actions[0]->message->identities);
        $f->commit($f->state, new HandlerResult($cancelled, postCommitActions: $actions));
        $c->get(RunOperationalProjectionRepository::class)->replace($f->state->with(['turnNo' => 2]));
        $this->assertTrue($token->isCancellationRequested());
        $this->assertSame('cancelled', $c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm')['interruption_reason']);
        $this->assertSame([], $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($f->state->runId, $id, 'cm', true), $f->state)->events);
    }

    public function testNavigationPreservesHostsAndDiscardCapturesOnlyAbandonedTurns(): void
    {
        [$f, $id] = $this->begin();
        $hook = self::getContainer()->get(CodeModeInterruptionHookSubscriber::class);
        $navigation = new RunEvent($f->state->runId, 4, 0, 'history_position_set', ['position_turn_no' => 0], new \DateTimeImmutable());
        $this->assertSame([], $hook->prepareAfterTurnCommit(AfterTurnCommitHookContext::fromRunState($f->state, [$navigation], 0), 3));
        $retained = new RunEvent($f->state->runId, 4, 1, 'history_tail_discarded', ['after_turn_no' => 1], new \DateTimeImmutable());
        $this->assertSame([], $hook->prepareAfterTurnCommit(AfterTurnCommitHookContext::fromRunState($f->state, [$retained], 0), 3));
        $selected = $f->state->with(['turnNo' => 0, 'lastSeq' => 4]);
        $f->commit($f->state, new HandlerResult($selected, [$navigation]));
        $this->assertTrue(self::getContainer()->get(CodeModeExecutionRepository::class)->isActive($id));
        $discard = new RunEvent($f->state->runId, 5, 0, 'history_tail_discarded', ['after_turn_no' => 0], new \DateTimeImmutable());
        $actions = $hook->prepareAfterTurnCommit(AfterTurnCommitHookContext::fromRunState($selected, [$discard], 0), 4);
        $this->assertSame([$id], $actions[0]->message->identities);
        $f->commit($selected, new HandlerResult(events: [$discard], postCommitActions: $actions));
        $this->assertSame('history_discarded', self::getContainer()->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm')['interruption_reason']);
    }

    public function testFollowUpBehindSelectedHistoryFencesTheOldBackgroundHostAtDiscardCommit(): void
    {
        [$f, $id] = $this->begin();
        $c = self::getContainer();
        $run = $f->state->runId;
        $c->get(CodeModeExecutionService::class)->ready($id);
        $f->commit($f->state, $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($run, $id, 'cm', true), $f->state));
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
        $tip = $f->state->with(['lastSeq' => $events[array_key_last($events)]->seq]);
        $active = $c->get(ActiveRunContextInterface::class);
        $active->loadRecovered($tip);
        $selected = $tip->with(['turnNo' => 0, 'activeStepId' => null, 'pendingToolCalls' => [], 'messages' => [], 'status' => RunStatus::Completed]);
        $c->get(RunCommit::class)->commit($tip, $selected, [RunEvent::forAppend($run, 0, 'history_position_set', ['position_turn_no' => 0, 'reason' => 'history_select'])], dispatchAfterTurnHooks: false);
        $this->assertTrue($c->get(CodeModeExecutionRepository::class)->isActive($id), 'Navigation alone preserves the host.');
        $llmBefore = \count($c->get('messenger.transport.llm')->getSent());
        $c->get(RunMessageProcessor::class)->process('test-follow-up', new ApplyCommand($run, 0, 'replacement', 1, 'replacement-input', 'follow_up', [
            'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Replacement branch']]],
        ]));
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
        $discard = array_values(array_filter($events, static fn (RunEvent $event): bool => 'history_tail_discarded' === $event->type));
        $this->assertCount(1, $discard);
        $this->assertSame(0, $discard[0]->payload['after_turn_no']);
        $this->assertSame('history_discarded', $c->get(CodeModeExecutionRepository::class)->invocation($run, 'cm')['interruption_reason']);
        $this->assertCount($llmBefore, $c->get('messenger.transport.llm')->getSent(), 'Discard must not start automatic model work.');
        $historyBeforeCompletion = $events;
        $result = new ToolCallResult($run, 1, 'step', 1, $f->call->idempotencyKey(), 'cm', 0,
            ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => 'Abandoned branch output']]]);
        $payloads = $c->get(ToolPayloadStoreInterface::class);
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $invocation = $deferred->findInvocationReferenceByDeferredId($id) ?? throw new \RuntimeException('Missing invocation.');
        $accepted = $payloads->retainProcessedResult($invocation, $result);
        $deferred->acceptResult($id, $accepted);
        // Production refuses replacement-branch delivery for the discarded host.
        // Interrupted cleanup retires the deferred row without mailbox admission.
        $c->get(CompleteDeferredToolCallHandler::class)(new CompleteDeferredToolCall(
            $id,
            \is_array($result->result['content'] ?? null) ? $result->result['content'] : [],
            \is_array($result->result['details'] ?? null) ? $result->result['details'] : null,
            $result->isError,
            $result->error,
        ));
        $f->consumeCompletions();
        $this->assertSame('completed', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
        $this->assertEquals($historyBeforeCompletion, $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run), 'Delivery must not admit an abandoned result into replacement history or queue a mailbox command.');
        $this->assertSame([], array_filter($c->get(PreparedTransitionEventStoreInterface::class)->allFor($run), static fn (RunEvent $event): bool => 'tool_execution_end' === $event->type));
    }

    public function testNaturalBackgroundCompletionBehindSelectedHistoryDoesNotDiscardOrFence(): void
    {
        [$f, $id] = $this->begin();
        $c = self::getContainer();
        $run = $f->state->runId;
        $c->get(CodeModeExecutionService::class)->ready($id);
        $f->commit($f->state, $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($run, $id, 'cm', true), $f->state));
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
        $tip = $f->state->with(['lastSeq' => $events[array_key_last($events)]->seq, 'status' => RunStatus::Completed, 'pendingToolCalls' => ['cm' => true], 'activeStepId' => null]);
        $c->get(ActiveRunContextInterface::class)->loadRecovered($tip);
        $c->get(RunOperationalProjectionRepository::class)->replace($tip);
        $forward = RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 2]);
        $ahead = $c->get(RunCommit::class)->commit($tip, $tip->with(['turnNo' => 2, 'lastSeq' => $tip->lastSeq + 1]), [$forward], dispatchAfterTurnHooks: false);
        $selected = $tip->with(['turnNo' => 0, 'activeStepId' => null, 'pendingToolCalls' => [], 'messages' => [], 'status' => RunStatus::Completed]);
        $selected = $c->get(RunCommit::class)->commit(
            $ahead,
            $selected,
            [RunEvent::forAppend($run, 0, 'history_position_set', ['position_turn_no' => 0, 'reason' => 'history_select'])],
            dispatchAfterTurnHooks: false,
        );
        $c->get(ActiveRunContextInterface::class)->loadRecovered($selected);
        $c->get(RunOperationalProjectionRepository::class)->replace($selected);
        $this->assertTrue($c->get(SessionRunEventStore::class)->hasForwardTail($run, 0));
        $historyBefore = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
        $result = new ToolCallResult($run, 1, 'step', 1, $f->call->idempotencyKey(), 'cm', 0,
            ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => 'Natural retained-branch output']]]);
        $payloads = $c->get(ToolPayloadStoreInterface::class);
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $invocation = $deferred->findInvocationReferenceByDeferredId($id) ?? throw new \RuntimeException('Missing invocation.');
        $accepted = $payloads->retainProcessedResult($invocation, $result);
        $deferred->acceptResult($id, $accepted);
        $c->get(CompleteDeferredToolCallHandler::class)(new CompleteDeferredToolCall(
            $id,
            \is_array($result->result['content'] ?? null) ? $result->result['content'] : [],
            \is_array($result->result['details'] ?? null) ? $result->result['details'] : null,
            $result->isError,
            $result->error,
        ));
        $f->consumeCompletions();
        $history = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
        $this->assertSame([], array_filter($history, static fn (RunEvent $event): bool => 'history_tail_discarded' === $event->type));
        $this->assertNull($c->get(CodeModeExecutionRepository::class)->invocation($run, 'cm')['interruption_reason']);
        $this->assertTrue($c->get(CodeModeExecutionRepository::class)->isActive($id));
        $this->assertTrue($c->get(SessionRunEventStore::class)->hasForwardTail($run, 0));
        $this->assertNotEquals($historyBefore, $history, 'Natural completion still admits through the shared background owner.');
        $this->assertNotEmpty(array_filter($history, static fn (RunEvent $event): bool => 'agent_command_queued' === $event->type));
        $this->assertSame([], $c->get(ActiveRunContextInterface::class)->requireLoaded($run)->pendingHumanInputRequests);
        $this->assertFalse($c->get(HistoryTailDiscardService::class)->isContextMutatingMessage(
            new DeliverBackgroundCompletionDTO($run, $id, 'Natural retained-branch output', BackgroundCompletionOriginEnum::CodeMode, deferredId: $id, invocation: $invocation),
        ));
    }

    public function testRestartFencesReadyAcknowledgementAndLateTerminalButRetainsRawEvidence(): void
    {
        [$f, $id] = $this->begin();
        $c = self::getContainer();
        $service = $c->get(CodeModeExecutionService::class);
        $service->ready($id);
        $ack = $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($f->state->runId, $id, 'cm', true), $f->state);
        $f->commit($f->state, $ack);
        $service->preserve($id, 42, null);
        $c->get(CodeModeControllerSessionLifecycleListener::class)->starting(new ControllerSessionStartingEvent($f->state->runId));
        $row = $c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm');
        $this->assertSame('controller_restarted', $row['interruption_reason']);
        $this->assertSame([], $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($f->state->runId, $id, 'cm', true), $f->state)->events);
        // Late worker after controller loss must not accept or settle; recovery owns delivery.
        $this->assertSame('pending', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id), 'Late work cannot retire the controller recovery send.');
        $f->consumeCompletions();
        $this->assertSame('pending', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id), 'Passive recovery parks delivery.');
        $c->get(CompleteDeferredToolCallHandler::class)->redrive(new RedriveDeferredToolCall($f->state->runId, $id));
        $f->consumeCompletions();
        $this->assertSame('completed', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
        $this->assertNull($c->get(ToolBatchStoreInterface::class)->loadSchedule($f->state->runId, 1, 'step'));
        $this->assertFileExists($c->get(CodeModeResultArtifactStore::class)->path($service->resultReference($row['raw_reference'])));
        $this->expectException(\RuntimeException::class);
        $service->ready($id);
    }

    public function testCancellationRetiresAnUnconsumedControllerRecoveryNotice(): void
    {
        [$f, $id] = $this->begin();
        $c = self::getContainer();
        $c->get(CodeModeExecutionService::class)->ready($id);
        $f->commit($f->state, $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($f->state->runId, $id, 'cm', true), $f->state));
        $listener = $c->get(CodeModeControllerSessionLifecycleListener::class);
        $listener->starting(new ControllerSessionStartingEvent($f->state->runId));
        $f->consumeCompletions();
        $hook = $c->get(CodeModeInterruptionHookSubscriber::class);
        $actions = $hook->prepareAfterTurnCommit(AfterTurnCommitHookContext::fromRunState($f->state->with(['status' => RunStatus::Cancelling]), [], 0), $f->state->lastSeq);
        $this->assertSame([$id], $actions[0]->message->identities, 'An unconsumed recovery notice remains subject to explicit cancellation.');
        $hook->interrupt($actions[0]->message);
        $this->assertSame(CodeModeInterruptionReasonEnum::Cancelled, $c->get(CodeModeExecutionRepository::class)->interruption($f->state->runId, 'cm'));
        $listener->starting(new ControllerSessionStartingEvent($f->state->runId));
        $this->assertSame(CodeModeInterruptionReasonEnum::Cancelled, $c->get(CodeModeExecutionRepository::class)->interruption($f->state->runId, 'cm'));
    }

    public function testDeletionRollsBackNativeMetadataAndPreservesArtifacts(): void
    {
        [$f, $id] = $this->begin();
        $c = self::getContainer();
        $service = $c->get(CodeModeExecutionService::class);
        $service->preserve($id, 42, null);
        $row = $c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm');
        $path = $c->get(CodeModeResultArtifactStore::class)->path($service->resultReference($row['raw_reference']));
        $dispatcher = $c->get(EventDispatcherInterface::class);
        $fail = static function (ControllerSessionShutdownEvent $event): void {
            if ($event->permanentDeletion) {
                throw new \RuntimeException('Rollback deletion.');
            }
        };
        $dispatcher->addListener(ControllerSessionShutdownEvent::class, $fail, -2048);
        try {
            $c->get(HatfieldSessionStore::class)->deleteSession($f->state->runId);
            $this->fail('Deletion must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Rollback deletion.', $error->getMessage());
        } finally {
            $dispatcher->removeListener(ControllerSessionShutdownEvent::class, $fail);
        }
        $this->assertNotNull($c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm'));
        $this->assertFileExists($path);
    }

    public function testPermanentDeletionRemovesNativeMetadataAndRetainedArtifacts(): void
    {
        [$f, $id] = $this->begin();
        $c = self::getContainer();
        $service = $c->get(CodeModeExecutionService::class);
        $service->preserve($id, 42, null);
        $row = $c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm');
        $path = $c->get(CodeModeResultArtifactStore::class)->path($service->resultReference($row['raw_reference']));
        $c->get(HatfieldSessionStore::class)->deleteSession($f->state->runId);
        $this->assertNull($c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm'));
        $this->assertNull($c->get(DeferredToolCompletionRepositoryInterface::class)->findMetadataByDeferredId($id));
        $service->preserve($id, 'late value after deletion', null);
        $this->assertFileDoesNotExist($path);
        $this->assertDirectoryDoesNotExist(\dirname($path));
        $this->assertTrue($service->cancellationToken($id, new NullCancellationToken())->isCancellationRequested());
    }

    /** @return array{CodeModeBackendFixture, string} */
    private function begin(): array
    {
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'return 42;');
        $id = $c->get(StackToolExecutionContextAccessor::class)->with(new ToolContext($f->state->runId, 1, 'cm', 'code_mode', new NullCancellationToken(), 8, 0, null, 'step'), static fn () => $c->get(CodeModeExecutionService::class)->begin());

        return [$f, $id];
    }
}
