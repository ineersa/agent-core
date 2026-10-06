<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\ExecuteCompactionStepWorker;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\SymfonyAiTestMessages;
use Ineersa\CodingAgent\Application\Message\AttachRun;
use Ineersa\CodingAgent\Application\Message\RepairSession;
use Ineersa\CodingAgent\Application\Message\SelectHistoryPrompt;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Ineersa\CodingAgent\Runtime\Contract\SessionRepairRefusalReasonEnum;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\RepairHandler;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\SelectHistoryTurnHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class SessionMaintenanceRoutingTest extends PerMethodIsolatedKernelTestCase
{
    private \Ineersa\AgentCore\Tests\Support\TestMessageBus $autoCompactionBus;
    private int $afterTurnCount = 0;

    public function testManualCompactionAfterCancelledToolRecoversColdOwnerAndReplacesCanonicalHistory(): void
    {
        $container = self::getContainer();
        $container->get(AppConfig::class)->compaction = new CompactionConfig(autoEnabled: false, keepRecentTokens: 10, model: 'llama_cpp_test/test');
        $bus = $container->get('agent.command.bus');
        $ownerTransport = $container->get('messenger.transport.run_control');
        $llmTransport = $container->get('messenger.transport.llm');
        $toolTransport = $container->get('messenger.transport.tool');
        $run = $container->get(HatfieldSessionStore::class)->createSession('cancelled maintenance');
        $store = $container->get(EventStoreInterface::class);
        $registry = $container->get(ActiveRunContextInterface::class);
        $rawMessage = static fn (string $role, string $text): array => ['role' => $role, 'content' => [['type' => 'text', 'text' => $text]]];
        $store->appendMany([
            RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['metadata' => ['model' => 'llama_cpp_test/test', 'session' => []], 'messages' => [
                $rawMessage('user', 'OLD_CONTEXT '.str_repeat('previous conversation ', 60)),
                $rawMessage('assistant', str_repeat('previous answer ', 60)),
                $rawMessage('user', 'Read the recent file.'),
            ]]]),
            RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'old-step', 'operation_attempt' => 1, 'operation_idempotency_key' => 'old-key']),
        ]);
        $toolRequest = new LlmStepResult($run, 1, 'old-step', 1, 'old-key',
            assistantMessage: SymfonyAiTestMessages::assistantWithToolCalls([['id' => 'cancelled-read', 'name' => 'read', 'arguments' => ['path' => './recent.txt']]]),
            stopReason: 'tool_call',
        );
        $bus->dispatch($bus->dispatch($toolRequest)->with(new ReceivedStamp('run_control')));
        $this->assertCount(1, $toolTransport->getSent());
        $tool = $toolTransport->getSent()[0]->getMessage();
        $this->assertInstanceOf(ExecuteToolCall::class, $tool);
        $cancel = new ApplyCommand($run, 1, 'cancel', 1, 'cancel-key', CoreCommandKind::Cancel);
        $bus->dispatch($bus->dispatch($cancel)->with(new ReceivedStamp('run_control')));
        $this->assertSame(RunStatus::Cancelling, $registry->requireLoaded($run)->status);
        // Resolve the cancelled worker without executing its read or starting another turn.
        $cancelledResult = new ToolCallResult($run, 1, $tool->stepId(), 1, $tool->idempotencyKey(), $tool->toolCallId, 0,
            result: ['content' => [['type' => 'text', 'text' => 'Tool call cancelled.']], 'details' => ['cancelled' => true]],
            isError: true,
        );
        $bus->dispatch($bus->dispatch($cancelledResult)->with(new ReceivedStamp('run_control')));
        $cancelled = $registry->requireLoaded($run);
        $this->assertSame(RunStatus::Cancelled, $cancelled->status);
        $this->assertNull($cancelled->currentOperation);
        $checkpoint = $store->latestSequenceFor($run);
        $archiveBefore = $store->allFor($run);
        $ownerTransport->reset();
        $toolTransport->reset();
        $llmTransport->reset();
        $registry->release($run);

        $platform = $this->createMock(PlatformInterface::class);
        $platform->expects($this->once())->method('invoke')->with($this->callback(static fn (ModelInvocationRequest $request): bool => !$request->options->toolsEnabled && !$request->options->streamObserverEnabled && $run === $request->input->runId))
            ->willReturn(new PlatformInvocationResult(SymfonyAiTestMessages::assistantText('COMPACTED_HISTORY')));
        $container->set(ExecuteCompactionStepWorker::class, new ExecuteCompactionStepWorker($platform, $bus));

        $compact = new ApplyCommand($run, 1, 'manual-compact', 1, 'manual-compact-key', CoreCommandKind::Compact);
        $queued = $bus->dispatch($compact);
        $this->assertSame($checkpoint, $store->latestSequenceFor($run));
        $bus->dispatch($queued->with(new ReceivedStamp('run_control')));
        $sent = $ownerTransport->getSent();
        $this->assertCount(2, $sent);
        $request = $sent[1]->getMessage();
        $this->assertInstanceOf(CompactRun::class, $request);
        $this->assertFalse($request->continueAfterCompaction);
        $this->assertSame('manual', $request->trigger);
        $this->assertSame(RunStatus::Cancelled, $registry->requireLoaded($run)->status);
        $bus->dispatch($sent[1]->with(new ReceivedStamp('run_control')));
        $this->assertSame(RunStatus::Compacting, $registry->requireLoaded($run)->status);
        $this->assertCount(1, $llmTransport->getSent());
        $workerEnvelope = $llmTransport->getSent()[0];
        $reference = $workerEnvelope->getMessage();
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ExecutionRequest::class, $reference);
        $this->assertSame(ExecuteCompactionStep::class, $reference->requestType);
        $this->assertSame($run, $reference->runId());
        $this->assertNotNull($workerEnvelope->last(\Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp::class));
        $container->get('agent.execution.bus')->dispatch($workerEnvelope->with(new ReceivedStamp('llm')));
        $sent = $ownerTransport->getSent();
        $this->assertCount(3, $sent);
        $notification = $sent[2]->getMessage();
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\DurableExecutionResult::class, $notification);
        $this->assertSame(CompactionStepResult::class, $notification->resultType);
        $this->assertSame($reference->effectId, $notification->effectId);
        $bus->dispatch($sent[2]->with(new ReceivedStamp('run_control')));

        $completed = $registry->requireLoaded($run);
        $this->assertSame(RunStatus::Completed, $completed->status);
        $this->assertSame(1, $completed->turnNo);
        $this->assertNull($completed->currentOperation);
        $this->assertSame([], $toolTransport->getSent());
        $this->assertSame([], $this->autoCompactionBus->messages);
        $archiveAfter = $store->allFor($run);
        $this->assertEquals($archiveBefore, \array_slice($archiveAfter, 0, \count($archiveBefore)));
        $this->assertSame(['agent_command_applied', 'context_compaction_started', 'context_compacted'], array_column(\array_slice($archiveAfter, \count($archiveBefore)), 'type'));
        $compactedEvent = $archiveAfter[array_key_last($archiveAfter)];
        $this->assertStringContainsString('COMPACTED_HISTORY', json_encode($compactedEvent->payload['messages'], \JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('OLD_CONTEXT', json_encode($compactedEvent->payload['messages'], \JSON_THROW_ON_ERROR));
        $this->assertEquals(array_map(static fn ($message): array => $message->toArray(), $completed->messages), $compactedEvent->payload['messages']);
        $replay = $container->get(\Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface::class)->rebuildIfStale(RunState::queued($run), $run)->rebuiltState;
        $this->assertEquals($completed->messages, $replay->messages);
        $this->assertSame($completed->status, $replay->status);
        $this->assertSame($completed->lastSeq, $replay->lastSeq);
        // Command and worker-result redelivery cannot compact twice or revive the old work.
        $container->get('cache.app')->clear();
        $registry->release($run);
        $bus->dispatch($queued->with(new ReceivedStamp('run_control')));
        $registry->release($run);
        $bus->dispatch($sent[2]->with(new ReceivedStamp('run_control')));
        $this->assertSame($completed->lastSeq, $store->latestSequenceFor($run));
        $this->assertCount(3, $ownerTransport->getSent(), 'Manual compaction must not dispatch AdvanceRun.');
        $this->assertCount(1, $llmTransport->getSent());
        $this->assertSame([], $toolTransport->getSent());
    }

    public function testAttachResetsReasoningOnlyAtOwnerConsumptionWithoutStartingModelTurn(): void
    {
        $run = $this->seed();
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $sessions->claimReasoningBaseline($run, 'test-model', 'medium');
        $baseline = $sessions->findSession($run)->reasoningBaseline;
        $active = self::getContainer()->get(ActiveRunContextInterface::class);
        $state = $active->requireLoaded($run);
        $bus = self::getContainer()->get('agent.command.bus');
        $queued = $bus->dispatch(new AttachRun($run, [], 'attach-id'));
        $this->assertSame($baseline, $sessions->findSession($run)->reasoningBaseline);
        $bus->dispatch($queued->with(new ReceivedStamp('run_control')));
        $this->assertSame(['continuation_generation' => $sessions->continuationGeneration($run)], $sessions->findSession($run)->reasoningBaseline);
        $this->assertSame($state->turnNo, $active->requireLoaded($run)->turnNo);
        $this->assertGreaterThan($state->lastSeq, $active->requireLoaded($run)->lastSeq);
        $this->assertSame([], self::getContainer()->get('messenger.transport.llm')->getSent());
    }

    public function testControllerSelectionWritesOnlyWhenOwnerConsumesAndReturnsNarrowEvent(): void
    {
        $run = $this->seed();
        $store = self::getContainer()->get(EventStoreInterface::class);
        $before = $store->latestSequenceFor($run);
        $emitted = [];
        self::getContainer()->get(SelectHistoryTurnHandler::class)(new ControllerCommandEvent(new RuntimeCommand('select', 'select_history_turn', $run, ['turn_no' => 1]), static function (RuntimeEvent $event) use (&$emitted): void { $emitted[] = $event; }));
        $this->assertSame($before, $store->latestSequenceFor($run));
        $this->assertSame([], $emitted);
        $queued = self::getContainer()->get('messenger.transport.run_control')->getSent()[0];
        $this->assertInstanceOf(SelectHistoryPrompt::class, $queued->getMessage());
        $handled = self::getContainer()->get('agent.command.bus')->dispatch($queued->with(new ReceivedStamp('run_control')));
        $result = $handled->last(HandledStamp::class)->getResult();
        $this->assertInstanceOf(RuntimeEvent::class, $result);
        $this->assertSame(RuntimeEventTypeEnum::RunHistoryPositionChanged->value, $result->type);
        $this->assertGreaterThan($before, $result->seq);
        $this->assertSame($result->seq, $store->latestSequenceFor($run));
        $state = self::getContainer()->get(ActiveRunContextInterface::class)->requireLoaded($run);
        $this->assertSame(0, $state->turnNo);
        $this->assertSame($result->seq, $state->lastSeq);
        $this->assertSame('First prompt', $result->payload['editor_prompt_text']);
        $events = iterator_to_array(self::getContainer()->get(InMemoryRuntimeEventSink::class)->drain($run));
        $this->assertSame($result, $events[array_key_last($events)]);
        $duplicate = self::getContainer()->get('agent.command.bus')->dispatch($queued->with(new ReceivedStamp('run_control')))->last(HandledStamp::class)->getResult();
        $this->assertInstanceOf(RuntimeEvent::class, $duplicate);
        $this->assertSame(RuntimeEventTypeEnum::CommandAck->value, $duplicate->type);
        $this->assertSame(['commandId' => 'select', 'commandType' => 'select_history_turn', 'status' => 'accepted'], $duplicate->payload);
        $this->assertSame(0, $duplicate->seq);
        $this->assertSame($result->seq, $store->latestSequenceFor($run));
        $events = iterator_to_array(self::getContainer()->get(InMemoryRuntimeEventSink::class)->drain($run));
        $this->assertSame([$duplicate], $events, 'Duplicate selection acknowledges without reseeding the editor.');
        $this->assertMaintenanceDidNotScheduleCompaction($run);
    }

    public function testControllerRepairPreviewReturnsCorrelatedResponseOnlyAfterOwnerConsumption(): void
    {
        $run = $this->seed();
        $store = self::getContainer()->get(EventStoreInterface::class);
        $before = $store->latestSequenceFor($run);
        $emitted = [];
        self::getContainer()->get(RepairHandler::class)(new ControllerCommandEvent(new RuntimeCommand('repair-id', 'repair', $run, ['apply' => false]), static function (RuntimeEvent $event) use (&$emitted): void { $emitted[] = $event; }));
        $this->assertSame([], $emitted);
        $this->assertSame($before, $store->latestSequenceFor($run));
        $sent = self::getContainer()->get('messenger.transport.run_control')->getSent();
        $queued = $sent[array_key_last($sent)];
        $this->assertInstanceOf(RepairSession::class, $queued->getMessage());
        $handled = self::getContainer()->get('agent.command.bus')->dispatch($queued->with(new ReceivedStamp('run_control')));
        $this->assertInstanceOf(\Ineersa\CodingAgent\Runtime\Contract\RepairResult::class, $handled->last(HandledStamp::class)->getResult());
        $this->assertSame($before, $store->latestSequenceFor($run));
        $events = iterator_to_array(self::getContainer()->get(InMemoryRuntimeEventSink::class)->drain($run));
        $result = $events[array_key_last($events)];
        $this->assertSame(RuntimeEventTypeEnum::SessionRepairCompleted->value, $result->type);
        $this->assertSame('repair-id', $result->payload['commandId']);
        $this->assertSame('completed', $result->payload['status']);
        $this->assertArrayHasKey('refusal_reason', $result->payload);
    }

    public function testOwnerRepairApplyCommitsTerminalStateAndCorrelatedReply(): void
    {
        $run = $this->seed();
        $store = self::getContainer()->get(EventStoreInterface::class);
        $store->append(RunEvent::forAppend($run, 1, 'agent_command_applied', ['kind' => 'cancel']));
        $active = self::getContainer()->get(ActiveRunContextInterface::class);
        $active->loadRecovered($active->requireLoaded($run)->with(['status' => RunStatus::Cancelling, 'lastSeq' => $store->latestSequenceFor($run)]));
        $before = $store->latestSequenceFor($run);
        $bus = self::getContainer()->get('agent.command.bus');
        $queued = $bus->dispatch(new RepairSession($run, true, 'apply-id'));
        $this->assertSame($before, $store->latestSequenceFor($run));
        $handled = $bus->dispatch($queued->with(new ReceivedStamp('run_control')));
        $result = $handled->last(HandledStamp::class)->getResult();
        $this->assertTrue($result->staleCancellationRepaired);
        $this->assertGreaterThan($before, $store->latestSequenceFor($run));
        $this->assertSame(RunStatus::Cancelled, $active->requireLoaded($run)->status);
        $this->assertSame($store->latestSequenceFor($run), $active->requireLoaded($run)->lastSeq);
        $events = iterator_to_array(self::getContainer()->get(InMemoryRuntimeEventSink::class)->drain($run));
        $reply = $events[array_key_last($events)];
        $this->assertSame('apply-id', $reply->payload['commandId']);
        $this->assertTrue($reply->payload['stale_cancellation_repaired']);
        $this->assertMaintenanceDidNotScheduleCompaction($run);
    }

    public function testDiscardCannotCompactAheadOfPendingUserAdvance(): void
    {
        $run = $this->seed();
        $active = self::getContainer()->get(ActiveRunContextInterface::class);
        $active->replaceCurrent($active->requireLoaded($run)->with(['turnNo' => 0]));
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $sessions->claimReasoningBaseline($run, 'test-model', 'medium');
        $handler = $this->createMock(\Ineersa\AgentCore\Application\Pipeline\RunMessageHandler::class);
        $handler->method('supports')->willReturn(true);
        $handler->expects($this->once())->method('handle')->willReturn(new \Ineersa\AgentCore\Application\Pipeline\HandlerResult(postCommitActions: [new \Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO(new \Ineersa\AgentCore\Domain\Message\AdvanceRun($run, 0, 'user-advance', 1, 'user-advance'), 'advance failed')]));
        $processor = new \Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor(
            $active,
            self::getContainer()->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class),
            self::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\RunCommit::class),
            [$handler],
            self::getContainer()->get(\Ineersa\AgentCore\Contract\History\HistoryTailDiscardInterface::class),
        );
        $processor->process('user-command', new ApplyCommand($run, 0, 'steer', 1, 'steer', 'steer'));
        $sent = self::getContainer()->get('messenger.transport.run_control')->getSent();
        $this->assertCount(1, $sent);
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\AdvanceRun::class, $sent[0]->getMessage());
        $this->assertSame([], $this->autoCompactionBus->messages);
        $this->assertSame(0, $this->afterTurnCount);
        $this->assertSame(['continuation_generation' => $sessions->continuationGeneration($run)], $sessions->findSession($run)->reasoningBaseline);
        $this->autoCompactionBus->messages = [];
        $this->assertMaintenanceDidNotScheduleCompaction($run);
    }

    public function testAsyncFifoAttachFinishesCleanupBeforeAlreadyQueuedFollowUp(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('attach FIFO');
        $events = $container->get(EventStoreInterface::class);
        $events->appendMany([
            RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['metadata' => ['model' => 'test-model'], 'messages' => []]]),
            RunEvent::forAppend($run, 0, 'waiting_human', ['question_id' => 'old-question', 'prompt' => 'Continue?']),
        ]);
        $sessions->claimReasoningBaseline($run, 'test-model', 'medium');
        $bus = $container->get('agent.command.bus');
        $bus->dispatch(new AttachRun($run, [], 'attach-id'));
        $bus->dispatch(new ApplyCommand($run, 0, 'follow-now', 1, 'follow-now', 'follow_up', ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Continue now']]]]));
        $transport = $container->get('messenger.transport.run_control');
        $queued = $transport->getSent();
        $this->assertCount(2, $queued);
        $this->assertInstanceOf(AttachRun::class, $queued[0]->getMessage());
        $this->assertInstanceOf(ApplyCommand::class, $queued[1]->getMessage());
        $bus->dispatch($queued[0]->with(new ReceivedStamp('run_control')));
        $state = $container->get(ActiveRunContextInterface::class)->requireLoaded($run);
        $this->assertSame(RunStatus::Cancelled, $state->status);
        $this->assertSame([], $state->pendingHumanInputRequests);
        $this->assertSame(0, $state->turnNo);
        $this->assertCount(2, $transport->getSent(), 'Attach must not enqueue its cleanup behind the follow-up.');
        $this->assertSame([], $container->get('messenger.transport.llm')->getSent());
        $this->assertSame(['continuation_generation' => $sessions->continuationGeneration($run)], $sessions->findSession($run)->reasoningBaseline);
        $cleanup = $events->allFor($run);
        $this->assertSame(['run_started', 'waiting_human', 'agent_command_applied', 'agent_end', 'context_refreshed'], array_column($cleanup, 'type'));
        $bus->dispatch($queued[1]->with(new ReceivedStamp('run_control')));
        $after = $events->allFor($run);
        $this->assertNotContains('agent_command_rejected', array_column($after, 'type'));
        $this->assertSame('agent_command_queued', $after[array_key_last($after)]->type);
        $this->assertSame('follow_up', $after[array_key_last($after)]->payload['kind']);
        $this->assertGreaterThan($cleanup[array_key_last($cleanup)]->seq, $after[array_key_last($after)]->seq);
    }

    public function testColdDuplicateArchiveReturnsCorrelatedRefusalWithoutRecoveryOrAppend(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('duplicate repair');
        $store = $container->get(EventStoreInterface::class);
        $store->append(RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['messages' => []]]));
        $path = $this->archivePath($run);
        $line = file_get_contents($path);
        $this->assertIsString($line);
        file_put_contents($path, $line.$line);
        $before = file_get_contents($path);
        $bus = $container->get('agent.command.bus');
        $queued = $bus->dispatch(new RepairSession($run, true, 'duplicate-request'));
        $bus->dispatch($queued->with(new ReceivedStamp('run_control')));
        $reply = $this->repairReply($run);
        $this->assertSame('duplicate-request', $reply->payload['commandId']);
        $this->assertSame('completed', $reply->payload['status']);
        $this->assertSame(SessionRepairRefusalReasonEnum::DuplicateSequences->value, $reply->payload['refusal_reason']);
        $this->assertSame($before, file_get_contents($path));
        $this->assertNotAdmitted($run);
    }

    public function testColdRecoveryFailureStillEmitsSanitizedCorrelatedRepairReply(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('invalid recovery');
        $store = $container->get(EventStoreInterface::class);
        // Sequence integrity is valid; execution recovery rejects an invalid human request.
        $store->appendMany([
            RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['messages' => []]]),
            RunEvent::forAppend($run, 0, 'waiting_human', ['prompt' => 'private recovery content']),
        ]);
        $before = file_get_contents($this->archivePath($run));
        $bus = $container->get('agent.command.bus');
        $queued = $bus->dispatch(new RepairSession($run, true, 'recovery-request'));
        try {
            $bus->dispatch($queued->with(new ReceivedStamp('run_control')));
            $this->fail('Invalid canonical recovery must fail.');
        } catch (HandlerFailedException $exception) {
            $this->assertNotEmpty($exception->getWrappedExceptions());
        }
        $reply = $this->repairReply($run);
        $this->assertSame('recovery-request', $reply->payload['commandId']);
        $this->assertSame('failed', $reply->payload['status']);
        $this->assertSame(\InvalidArgumentException::class, $reply->payload['exception_class']);
        $this->assertArrayNotHasKey('error', $reply->payload);
        $this->assertArrayNotHasKey('message', $reply->payload);
        $this->assertStringNotContainsString('private recovery content', json_encode($reply->payload, \JSON_THROW_ON_ERROR));
        $this->assertSame($before, file_get_contents($this->archivePath($run)));
        $this->assertNotAdmitted($run);
    }

    public function testRepairPublishesRetainedSelectionAndNextInvocationExcludesDiscardedHistory(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('selected repair');
        $store = $container->get(EventStoreInterface::class);
        $rawMessage = static fn (string $role, string $text): array => ['role' => $role, 'content' => [['type' => 'text', 'text' => $text]]];
        $store->appendMany([
            RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['metadata' => ['model' => 'test-model'], 'messages' => [$rawMessage('user', 'RETAINED_PROMPT')]]]),
            RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'one']),
            RunEvent::forAppend($run, 1, 'llm_step_completed', ['assistant_message' => $rawMessage('assistant', 'RETAINED_ASSISTANT')]),
            RunEvent::forAppend($run, 1, 'agent_command_applied', ['kind' => 'follow_up', 'message' => $rawMessage('user', 'DISCARDED_PROMPT')]),
            RunEvent::forAppend($run, 2, 'turn_advanced', ['turn_no' => 2, 'step_id' => 'two']),
            RunEvent::forAppend($run, 2, 'llm_step_completed', ['assistant_message' => $rawMessage('assistant', 'DISCARDED_ASSISTANT')]),
            RunEvent::forAppend($run, 2, 'agent_end', ['reason' => 'completed']),
        ]);
        $replay = $container->get(\Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface::class);
        $registry = $container->get(ActiveRunContextInterface::class);
        $registry->loadRecovered($replay->rebuildIfStale(RunState::queued($run), $run)->rebuiltState);
        $maintenance = $container->get(\Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler::class);
        $attach = new AttachRun($run, [], 'attach-before-discard');
        $selection = new SelectHistoryPrompt($run, 2, 'selection-before-discard');
        $maintenance->attach($attach);
        $this->assertSame(RuntimeEventTypeEnum::RunHistoryPositionChanged->value, $maintenance->select($selection)->type);
        $processor = $container->get(\Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor::class);
        $processor->process('test', new ApplyCommand($run, 1, 'append-selected', 1, 'append-selected', 'append_message', ['message' => $rawMessage('user', 'NEW_CONTEXT')]));
        $this->assertContains('history_tail_discarded', array_column($store->allFor($run), 'type'));
        // Persist only the cancellation acceptance to reproduce interruption before terminalization.
        $store->append(RunEvent::forAppend($run, 1, 'agent_command_applied', ['kind' => 'cancel']));
        $registry->loadRecovered($replay->rebuildIfStale(RunState::queued($run), $run)->rebuiltState);
        $result = $container->get(\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface::class)->repair($run, true, 'repair-before-new-turn');
        $this->assertTrue($result->staleCancellationRepaired, $result->message);
        $immediate = $registry->requireLoaded($run);
        $cold = $replay->rebuildIfStale(RunState::queued($run), $run)->rebuiltState;
        $this->assertSame(1, $immediate->turnNo);
        $this->assertSame($store->latestSequenceFor($run), $immediate->lastSeq);
        $this->assertEquals($cold->messages, $immediate->messages);
        $this->assertSame($cold->status, $immediate->status);
        $this->assertSame($cold->turnNo, $immediate->turnNo);
        $processor->process('test', new ApplyCommand($run, 1, 'follow-after-repair', 1, 'follow-after-repair', 'follow_up', ['message' => $rawMessage('user', 'CONTINUE_RETAINED')]));
        $processor->process('test', new \Ineersa\AgentCore\Domain\Message\AdvanceRun($run, 1, 'invoke-retained', 1, 'invoke-retained'));
        $sent = $container->get('messenger.transport.llm')->getSent();
        $this->assertCount(1, $sent);
        $reference = $sent[0]->getMessage();
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ExecutionRequest::class, $reference);
        $authorization = $sent[0]->last(\Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp::class);
        $this->assertNotNull($authorization);
        $operations = $container->get(\Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface::class);
        $claim = $operations->claim($reference, $authorization);
        $this->assertIsString($claim);
        $request = $operations->resolveRequest($reference, $authorization, $claim);
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ExecuteLlmStep::class, $request);
        $texts = json_encode(array_map(static fn ($message): array => $message->toArray(), $request->messages), \JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('RETAINED_ASSISTANT', $texts);
        $this->assertStringContainsString('CONTINUE_RETAINED', $texts);
        $this->assertStringNotContainsString('DISCARDED_PROMPT', $texts);
        $this->assertStringNotContainsString('DISCARDED_ASSISTANT', $texts);
        $bytes = file_get_contents($this->archivePath($run));
        $this->assertNotFalse($bytes);
        $ownerCount = \count($container->get('messenger.transport.run_control')->getSent());
        $container->get('cache.app')->clear();
        $registry->release($run);
        $container->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class)->initializeForOwner($run, $attach);
        $maintenance->attach($attach);
        $this->assertSame(RuntimeEventTypeEnum::CommandAck->value, $maintenance->select($selection)->type, 'An accepted selection of discarded history must not reposition newer work.');
        $this->assertSame(0, $maintenance->repair(new RepairSession($run, true, 'repair-before-new-turn'))->activeOperationsRedriven);
        $processor->process('test', new ApplyCommand($run, 1, 'append-selected', 1, 'append-selected', 'append_message', ['message' => $rawMessage('user', 'NEW_CONTEXT')]));
        $this->assertSame($bytes, file_get_contents($this->archivePath($run)));
        $this->assertCount(1, $container->get('messenger.transport.llm')->getSent());
        $this->assertCount($ownerCount, $container->get('messenger.transport.run_control')->getSent());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('maintenanceInterruptions')]
    public function testMaintenanceRecoveryFencesTheOriginalActionButAllowsFreshIdentity(string $kind, string $phase, string $boundary): void
    {
        $c = self::getContainer();
        $store = $c->get(\Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface::class);
        $registry = $c->get(ActiveRunContextInterface::class);
        $sessions = $c->get(HatfieldSessionStore::class);
        if ('attach' === $kind) {
            $run = $sessions->createSession('attach recovery');
            $c->get(EventStoreInterface::class)->appendMany([
                RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['metadata' => ['model' => 'test-model'], 'messages' => []]]),
                RunEvent::forAppend($run, 0, 'waiting_human', ['question_id' => 'old-question', 'prompt' => 'Continue?']),
            ]);
            $command = new AttachRun($run, [], 'original-action');
        } else {
            $run = $this->seed();
            $command = new SelectHistoryPrompt($run, 1, 'original-action');
        }
        $source = \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance::actionIdentity($command::class, $run, $command->commandId);
        $acceptance = $c->get(\Ineersa\AgentCore\Application\Pipeline\SourceAcceptance::class);
        $this->assertFalse($acceptance->identityAlreadyAccepted($source));
        $fault = $this->createStub(\Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface::class);
        $fired = false;
        $matches = static fn (array $work): bool => ($work['source']['type'] ?? null) === $command::class && ($work['source']['step_id'] ?? null) === $phase;
        $fault->method('assertTransitionReady')->willReturnCallback($store->assertTransitionReady(...));
        $fault->method('verifiedPendingTransition')->willReturnCallback($store->verifiedPendingTransition(...));
        $fault->method('appendTransition')->willReturnCallback(static function (array $events, array $work) use ($store, $matches, $boundary, &$fired): array {
            if (!$fired && 'before_append' === $boundary && $matches($work)) {
                $fired = true;
                throw new \RuntimeException('Injected maintenance interruption.');
            }
            $persisted = $store->appendTransition($events, $work);
            if (!$fired && 'after_append' === $boundary && $matches($work)) {
                $fired = true;
                throw new \RuntimeException('Injected maintenance interruption.');
            }

            return $persisted;
        });
        $fault->method('finalizeVerifiedTransition')->willReturnCallback(static function (string $id, string $identity) use ($store, $matches, $boundary, &$fired): void {
            $pending = $store->verifiedPendingTransition($id);
            $store->finalizeVerifiedTransition($id, $identity);
            if (!$fired && 'after_finalize' === $boundary && null !== $pending && $matches($pending->work)) {
                $fired = true;
                throw new \RuntimeException('Injected maintenance interruption.');
            }
        });
        $commit = new \Ineersa\AgentCore\Application\Pipeline\RunCommit($registry, $fault,
            $c->get(\Ineersa\AgentCore\Application\Handler\StepDispatcher::class), new \Psr\Log\NullLogger(),
            $c->get(\Ineersa\AgentCore\Application\Handler\ToolBatchCollector::class),
            $c->get(\Ineersa\AgentCore\Contract\Tool\ToolExecutionAuthorizationInterface::class),
            $c->get(\Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface::class), $acceptance,
            actionValidator: $c->get(\Ineersa\AgentCore\Application\Handler\CoordinationActionValidator::class));
        $processor = new \Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor($registry,
            $c->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class), $commit,
            [$c->get(\Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler::class), $c->get(\Ineersa\AgentCore\Application\Pipeline\RefreshRunContextHandler::class)]);
        $history = new \Ineersa\CodingAgent\Session\History\HistorySelectionService($c->get(EventStoreInterface::class),
            $c->get(\Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface::class), $registry,
            $c->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class), new \Psr\Log\NullLogger(),
            $c->get(\Ineersa\CodingAgent\Session\History\HistoryProjector::class),
            $c->get(\Ineersa\AgentCore\Application\Replay\ReplayEventPreparer::class), $commit);
        $handler = new \Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler($history,
            $c->get(\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface::class), $c->get(InMemoryRuntimeEventSink::class),
            $c->get(\Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink::class), false, new \Psr\Log\NullLogger(),
            $registry, $processor, $sessions, $c->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class),
            $commit, $c->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class));
        $entry = $c->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class);
        $entry->initializeForOwner($run, $command);
        $asyncInterruptedAttach = 'attach' === $kind && 'complete' === $phase && 'before_append' === $boundary;
        $bus = $c->get('agent.command.bus');
        $attachBus = new \Symfony\Component\Messenger\MessageBus([$entry,
            new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware(new \Symfony\Component\Messenger\Handler\HandlersLocator([
                AttachRun::class => [$handler->attach(...)],
            ])),
        ]);
        if ($asyncInterruptedAttach) {
            $bus->dispatch($command);
        }
        if ($command instanceof AttachRun) {
            try {
                if ($asyncInterruptedAttach) {
                    $attachBus->dispatch(new \Symfony\Component\Messenger\Envelope($command, [new ReceivedStamp('run_control')]));
                } else {
                    $handler->attach($command);
                }
                $this->fail('The configured failure must interrupt attach.');
            } catch (\RuntimeException|HandlerFailedException $exception) {
                $this->assertStringContainsString('Injected maintenance interruption.', $exception->getMessage());
            }
        } else {
            $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $handler->select($command)->type);
        }
        $this->assertTrue($fired);
        $this->assertSame('after_finalize' === $boundary && 'complete' === $phase, $acceptance->identityAlreadyAccepted($source));
        if ($asyncInterruptedAttach) {
            // A delayed retry may arrive after a later owner delivery. The
            // already committed cancel constituent must not reject that input.
            $followUp = new ApplyCommand($run, 0, 'new-input', 1, 'new-input', 'follow_up', ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'New input after interrupted attach']]]]);
            $bus->dispatch($followUp);
            $bus->dispatch(new \Symfony\Component\Messenger\Envelope($followUp, [new ReceivedStamp('run_control')]));
            $this->assertTrue($c->get(\Ineersa\AgentCore\Contract\CommandStoreInterface::class)->has($run, 'new-input'));
            $attachBus->dispatch(new \Symfony\Component\Messenger\Envelope($command, [new ReceivedStamp('run_control')]));
            $events = $c->get(EventStoreInterface::class)->allFor($run);
            $this->assertCount(1, array_filter($events, static fn (RunEvent $event): bool => 'agent_command_applied' === $event->type && 'cancel' === ($event->payload['kind'] ?? null)));
            $this->assertCount(1, array_filter($events, static fn (RunEvent $event): bool => 'agent_command_queued' === $event->type && 'new-input' === ($event->payload['idempotency_key'] ?? null)));
            $this->assertNotContains('agent_command_rejected', array_column($events, 'type'));
            $this->assertTrue($acceptance->identityAlreadyAccepted($source));
        }
        $registry->release($run);
        $entry->initializeForOwner($run, $command);
        if ($command instanceof AttachRun) {
            $handler->attach($command);
            $this->assertSame([], $registry->requireLoaded($run)->pendingHumanInputRequests);
            $this->assertSame(RunStatus::Cancelled, $registry->requireLoaded($run)->status);
        } else {
            $handler->select($command);
        }
        $this->assertTrue($acceptance->identityAlreadyAccepted($source));
        $this->assertNull($store->verifiedPendingTransition($run));
        $bytes = file_get_contents($this->archivePath($run));
        $this->assertNotFalse($bytes);
        $c->get('cache.app')->clear();
        $registry->release($run);
        $entry->initializeForOwner($run, $command);
        if ($command instanceof AttachRun) {
            $sessions->claimReasoningBaseline($run, 'test-model', 'medium');
            $baseline = $sessions->findSession($run)->reasoningBaseline;
            $handler->attach($command);
            $this->assertSame($baseline, $sessions->findSession($run)->reasoningBaseline);
            $this->assertSame($bytes, file_get_contents($this->archivePath($run)));
            $handler->attach(new AttachRun($run, [], 'fresh-action'));
            $this->assertCount(2, array_filter($c->get(EventStoreInterface::class)->allFor($run), static fn (RunEvent $event): bool => 'context_refreshed' === $event->type));
        } else {
            $this->assertSame(RuntimeEventTypeEnum::CommandAck->value, $handler->select($command)->type);
            $this->assertSame($bytes, file_get_contents($this->archivePath($run)));
            $handler->select(new SelectHistoryPrompt($run, 1, 'fresh-action'));
            $this->assertCount(3, array_filter($c->get(EventStoreInterface::class)->allFor($run), static fn (RunEvent $event): bool => 'history_position_set' === $event->type));
        }
        $this->assertSame([], $c->get('messenger.transport.llm')->getSent());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function maintenanceInterruptions(): iterable
    {
        yield 'attach cancel appended' => ['attach', 'cancel', 'after_append'];
        yield 'attach refresh not staged' => ['attach', 'complete', 'before_append'];
        yield 'attach refresh appended' => ['attach', 'complete', 'after_append'];
        yield 'attach root finalized' => ['attach', 'complete', 'after_finalize'];
        yield 'selection appended' => ['select', 'complete', 'after_append'];
        yield 'selection root finalized' => ['select', 'complete', 'after_finalize'];
    }

    public function testAcceptedOldHumanAnswerCannotChangeRevisedSuspensionOrDiscardHistory(): void
    {
        $c = self::getContainer();
        $run = $c->get(HatfieldSessionStore::class)->createSession('revised human suspension');
        $registry = $c->get(ActiveRunContextInterface::class);
        $collector = $c->get(\Ineersa\AgentCore\Application\Handler\ToolBatchCollector::class);
        $call = new ExecuteToolCall(runId: $run, turnNo: 1, stepId: 'tools', attempt: 1, idempotencyKey: 'call-key', toolCallId: 'call', orderIndex: 0, toolName: 'read', args: ['path' => './file']);
        $collector->registerExpectedBatch($run, 1, 'tools', [$call]);
        \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::suspend($collector, $run, 1, 'tools', 'call', 'old-question');
        $request = \Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO::toolCallFromPayload(
            ['question_id' => 'old-question', 'prompt' => 'Allow?'],
            ['run_id' => $run, 'turn_no' => 1, 'step_id' => 'tools', 'tool_call_id' => 'call']);
        $state = new RunState($run, RunStatus::WaitingHuman, turnNo: 1, activeStepId: 'tools',
            pendingToolCalls: ['call' => false], pendingHumanInputRequests: [$request], model: 'test-model');
        $registry->loadRecovered($state);
        $commit = $c->get(\Ineersa\AgentCore\Application\Pipeline\RunCommit::class);
        $commit->commit($state, $state, [RunEvent::forAppend($run, 1, 'run_started', ['payload' => ['messages' => []]]),
            RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'tools'])], dispatchAfterTurnHooks: false);
        $answer = new ApplyCommand($run, 1, 'answer-step', 1, 'old-answer', 'human_response', ['question_id' => 'old-question', 'answer' => 'yes']);
        $bus = $c->get('agent.command.bus');
        $bus->dispatch(new \Symfony\Component\Messenger\Envelope($answer, [new ReceivedStamp('run_control')]));
        \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::suspend($collector, $run, 1, 'tools', 'call', 'new-question');
        $request = \Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO::toolCallFromPayload(
            ['question_id' => 'new-question', 'prompt' => 'Revised approval?'],
            ['run_id' => $run, 'turn_no' => 1, 'step_id' => 'tools', 'tool_call_id' => 'call']);
        $state = $registry->requireLoaded($run);
        $next = $state->with(['status' => RunStatus::WaitingHuman, 'pendingHumanInputRequests' => [$request]]);
        $commit->commit($state, $next, [RunEvent::forAppend($run, 2, 'turn_advanced', ['turn_no' => 2, 'step_id' => 'historical-tail']),
            RunEvent::forAppend($run, 1, 'history_position_set', ['position_turn_no' => 1])], dispatchAfterTurnHooks: false);
        $batchStore = $c->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class);
        $before = serialize($batchStore->load($run, 1, 'tools'));
        $bytes = file_get_contents($this->archivePath($run));
        $this->assertNotFalse($bytes);
        $sent = $c->get('messenger.transport.tool')->getSent();
        $this->assertCount(1, $sent, 'The first accepted answer dispatches its continuation.');
        $bus->dispatch(new \Symfony\Component\Messenger\Envelope($answer, [new ReceivedStamp('run_control')]));
        $this->assertSame($bytes, file_get_contents($this->archivePath($run)));
        $this->assertSame($before, serialize($batchStore->load($run, 1, 'tools')));
        $this->assertSame('new-question', $registry->requireLoaded($run)->pendingHumanInputRequests[0]->questionId);
        $this->assertSame($sent, $c->get('messenger.transport.tool')->getSent());
    }

    protected function afterKernelBoot(): void
    {
        $container = self::getContainer();
        $this->autoCompactionBus = new \Ineersa\AgentCore\Tests\Support\TestMessageBus();
        $compaction = $this->createStub(\Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface::class);
        $compaction->method('prepare')->willReturn(\Ineersa\AgentCore\Contract\Compaction\CompactionPrepareResult::ready(
            messagesToSummarize: [new \Ineersa\AgentCore\Domain\Message\AgentMessage('user', [['type' => 'text', 'text' => 'fresh conversation']])],
            retainedTailMessages: [], tokenEstimateBefore: 12000, messagesCompacted: 1, messagesRetained: 0, firstRetainedIndex: 1, priorSummaryPresent: false,
        ));
        $subscriber = new \Ineersa\CodingAgent\Compaction\AutoCompactionHookSubscriber(
            $container->get(\Ineersa\CodingAgent\Compaction\ProviderContextUsageResolver::class),
            new CompactionConfig(autoEnabled: true, compactAfterTokens: 11000, keepRecentTokens: 10),
            $this->createStub(\Ineersa\AgentCore\Contract\Model\RunModelResolverInterface::class),
            $this->autoCompactionBus,
            $compaction,
            $container->get(\Ineersa\CodingAgent\Repository\RunRelationshipReaderInterface::class),
        );
        $observer = new class($this->afterTurnCount) implements \Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface {
            public function __construct(private int &$count)
            {
            }

            public function handleAfterTurnCommit(\Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext $context): \Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext
            {
                ++$this->count;

                return $context;
            }
        };
        $container->set(\Ineersa\AgentCore\Application\Handler\HookDispatcher::class, new \Ineersa\AgentCore\Application\Handler\HookDispatcher([$observer, $subscriber]));
        $container->set(\Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler::class, new \Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler(
            $container->get(\Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface::class),
            $container->get(\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface::class),
            $container->get(InMemoryRuntimeEventSink::class),
            $container->get(\Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink::class),
            false,
            new \Psr\Log\NullLogger(),
            $container->get(ActiveRunContextInterface::class),
            $container->get(\Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor::class),
            $container->get(HatfieldSessionStore::class),
            $container->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class),
            $container->get(\Ineersa\AgentCore\Application\Pipeline\RunCommit::class),
            $container->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class),
        ));
    }

    private function assertMaintenanceDidNotScheduleCompaction(string $run): void
    {
        $this->assertSame([], $this->autoCompactionBus->messages);
        $this->assertSame(0, $this->afterTurnCount, 'Maintenance must not invoke any after-turn subscriber.');
        $state = self::getContainer()->get(ActiveRunContextInterface::class)->requireLoaded($run);
        // Positive control: the same usage and real subscriber remain eligible
        // on an ordinary commit, rather than being disabled by the fixture.
        self::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\RunCommit::class)->commit($state, $state, [RunEvent::forAppend($run, $state->turnNo, 'llm_step_completed', ['usage' => ['input_tokens' => 12000]])]);
        $this->assertSame(1, $this->afterTurnCount);
        $this->assertCount(1, $this->autoCompactionBus->messages);
        $this->assertInstanceOf(CompactRun::class, $this->autoCompactionBus->messages[0]);
    }

    private function seed(): string
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('maintenance');
        $store = self::getContainer()->get(EventStoreInterface::class);
        $store->appendMany([
            RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'First prompt']]]]]]),
            RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'step']),
            RunEvent::forAppend($run, 1, 'llm_step_completed', ['usage' => ['input_tokens' => 12000], 'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Useful work']]]]),
            RunEvent::forAppend($run, 1, 'history_position_set', ['position_turn_no' => 1]),
        ]);
        self::getContainer()->get(ActiveRunContextInterface::class)->loadRecovered(new RunState($run, RunStatus::Running, turnNo: 1, lastSeq: $store->latestSequenceFor($run), model: 'test-model'));

        return $run;
    }

    private function archivePath(string $run): string
    {
        return self::getContainer()->get(HatfieldSessionStore::class)->resolveSessionsBasePath().'/'.$run.'/events.jsonl';
    }

    private function repairReply(string $run): RuntimeEvent
    {
        $replies = array_values(array_filter(iterator_to_array(self::getContainer()->get(InMemoryRuntimeEventSink::class)->drain($run)), static fn ($event): bool => RuntimeEventTypeEnum::SessionRepairCompleted->value === $event->type));
        $this->assertCount(1, $replies);

        return $replies[0];
    }

    private function assertNotAdmitted(string $run): void
    {
        try {
            self::getContainer()->get(ActiveRunContextInterface::class)->requireLoaded($run);
            $this->fail('Refused maintenance must not admit state.');
        } catch (RunContextNotLoadedException $exception) {
            $this->assertStringContainsString($run, $exception->getMessage());
        }
    }
}
