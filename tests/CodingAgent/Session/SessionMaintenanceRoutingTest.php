<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\ExecuteCompactionStepWorker;
use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\SymfonyAiTestMessages;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Application\Message\AttachRun;
use Ineersa\CodingAgent\Application\Message\RepairSession;
use Ineersa\CodingAgent\Application\Message\SelectHistoryPrompt;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
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
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookResultDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class SessionMaintenanceRoutingTest extends PerMethodIsolatedKernelTestCase
{
    private \Ineersa\AgentCore\Tests\Support\TestMessageBus $autoCompactionBus;
    private int $afterTurnCount = 0;

    public function testActiveWorkCompactsAfterCompleteToolBatchBeforeNextModelRequest(): void
    {
        $container = self::getContainer();
        $activeModel = 'openai-codex/gpt-6.1-sol';
        $threshold = $container->get(CompactionConfig::class)->resolveRuntimeSettings($activeModel)->compactAfterTokens;
        $container->get(AppConfig::class)->compaction = new CompactionConfig(compactAfterTokens: $threshold, keepRecentTokens: 10, model: 'llama_cpp_test/test');
        $bus = $container->get('agent.command.bus');
        $ownerTransport = $container->get('messenger.transport.run_control');
        $llmTransport = $container->get('messenger.transport.llm');
        $toolTransport = $container->get('messenger.transport.tool');
        $registry = $container->get(ActiveRunContextInterface::class);
        $store = $container->get(EventStoreInterface::class);
        $run = $container->get(HatfieldSessionStore::class)->createSession('active compaction');
        $store->append(RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['metadata' => ['model' => $activeModel, 'session' => []], 'messages' => [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'OLD_CONTEXT '.str_repeat('Continue this task through multiple tools. ', 60)]]],
        ]]]));
        $requests = [];
        $responses = [
            new PlatformInvocationResult(SymfonyAiTestMessages::assistantWithToolCalls([['id' => 'first-read', 'name' => 'read', 'arguments' => ['path' => './first.txt']]]), usage: ['input_tokens' => $threshold - 1], stopReason: 'tool_call'),
            new PlatformInvocationResult(SymfonyAiTestMessages::assistantWithToolCalls([
                ['id' => 'second-read', 'name' => 'read', 'arguments' => ['path' => './second.txt']],
                ['id' => 'third-read', 'name' => 'read', 'arguments' => ['path' => './third.txt']],
            ]), usage: ['input_tokens' => $threshold], stopReason: 'tool_call'),
            new PlatformInvocationResult(SymfonyAiTestMessages::assistantText('COMPACTED_HISTORY')),
            new PlatformInvocationResult(SymfonyAiTestMessages::assistantText('Work continued.')),
        ];
        $platform = $this->createMock(PlatformInterface::class);
        $platform->expects($this->exactly(4))->method('invoke')->willReturnCallback(static function (ModelInvocationRequest $request) use (&$requests, $responses): PlatformInvocationResult {
            $requests[] = $request;

            return $responses[\count($requests) - 1];
        });
        $container->set(ExecuteLlmStepWorker::class, new ExecuteLlmStepWorker($platform, $bus));
        $container->set(ExecuteCompactionStepWorker::class, new ExecuteCompactionStepWorker($platform, $bus));
        $consumeOwner = static function () use ($bus, $ownerTransport): void {
            $sent = $ownerTransport->getSent();
            $bus->dispatch($sent[array_key_last($sent)]->with(new ReceivedStamp('run_control')));
        };
        $consumeLlm = static function () use ($container, $llmTransport): void {
            $sent = $llmTransport->getSent();
            $container->get('agent.execution.bus')->dispatch($sent[array_key_last($sent)]->with(new ReceivedStamp('llm')));
        };
        $resolveTool = static function (ExecuteToolCall $tool) use ($bus, $run): void {
            $result = new ToolCallResult($run, $tool->turnNo(), $tool->stepId(), 1, $tool->idempotencyKey(), $tool->toolCallId, $tool->orderIndex,
                result: ['content' => [['type' => 'text', 'text' => str_repeat('Collected tool output. ', 30)]]],
            );
            $bus->dispatch($bus->dispatch($result)->with(new ReceivedStamp('run_control')));
        };
        $bus->dispatch(new AdvanceRun($run, 0, 'first-advance', 1, 'first-advance'));
        $consumeOwner();
        $consumeLlm();
        $consumeOwner();
        $firstTool = $toolTransport->getSent()[0]->getMessage();
        $this->assertInstanceOf(ExecuteToolCall::class, $firstTool);
        $resolveTool($firstTool);
        $consumeOwner();
        $this->assertInstanceOf(ExecuteLlmStep::class, $llmTransport->getSent()[1]->getMessage(), 'Below threshold, the next tool cycle must run without compaction.');
        $consumeLlm();
        $consumeOwner();
        $this->assertCount(3, $toolTransport->getSent());
        $secondTool = $toolTransport->getSent()[1]->getMessage();
        $thirdTool = $toolTransport->getSent()[2]->getMessage();
        $this->assertInstanceOf(ExecuteToolCall::class, $secondTool);
        $this->assertInstanceOf(ExecuteToolCall::class, $thirdTool);
        $resolveTool($secondTool);
        // Even an early scheduler delivery cannot compact an unresolved batch.
        $state = $registry->requireLoaded($run);
        $bus->dispatch(new AdvanceRun($run, $state->turnNo, 'early-advance', 1, 'early-advance'));
        $consumeOwner();
        $this->assertCount(2, $llmTransport->getSent());
        $this->assertSame([], $this->autoCompactionBus->messages);
        $resolveTool($thirdTool);
        $consumeOwner();
        $sent = $ownerTransport->getSent();
        $compact = $sent[array_key_last($sent)]->getMessage();
        $this->assertInstanceOf(CompactRun::class, $compact);
        $this->assertTrue($compact->continueAfterCompaction);
        $consumeOwner();
        $this->assertInstanceOf(ExecuteCompactionStep::class, $llmTransport->getSent()[2]->getMessage());
        $consumeLlm();
        $consumeOwner();
        $consumeOwner();
        $this->assertSame(RunStatus::Running, $registry->requireLoaded($run)->status);
        $this->assertNotContains('agent_end', array_column($store->allFor($run), 'type'));
        $this->assertNotContains('agent_command_applied', array_column($store->allFor($run), 'type'), 'Continuation must not need another user command.');
        $consumeLlm();
        $this->assertFalse($requests[2]->options->toolsEnabled, 'Only the compaction request disables tools.');
        $this->assertNotFalse($requests[3]->options->toolsEnabled);
        $nextHistory = array_map(static fn ($message): array => $message->toArray(), $requests[3]->input->messages);
        $this->assertStringContainsString('COMPACTED_HISTORY', json_encode($nextHistory, \JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('OLD_CONTEXT', json_encode($nextHistory, \JSON_THROW_ON_ERROR));
        $declared = [];
        $resolved = [];
        foreach ($requests[3]->input->messages as $message) {
            foreach ($message->metadata['tool_calls'] ?? [] as $call) {
                $declared[] = $call['id'];
            }
            if ('tool' === $message->role) {
                $resolved[] = $message->toolCallId;
            }
        }
        $this->assertSame(['second-read', 'third-read'], $declared);
        $this->assertSame($declared, $resolved);
    }

    public function testCancellationAheadOfPostToolCompactionStopsAllFurtherExecution(): void
    {
        $container = self::getContainer();
        $run = $this->completeToolBatchAtCompactionThreshold();
        $registry = $container->get(ActiveRunContextInterface::class);
        $ownerTransport = $container->get('messenger.transport.run_control');
        // The tool result queued AdvanceRun first. Its compaction effect must
        // go behind this cancellation on the same FIFO transport.
        $container->get(AgentRunnerInterface::class)->cancel($run);
        $this->assertInstanceOf(AdvanceRun::class, $this->consumeNextOwnerMessage());
        $tail = \array_slice($ownerTransport->getSent(), -2);
        $this->assertInstanceOf(ApplyCommand::class, $tail[0]->getMessage());
        $this->assertInstanceOf(CompactRun::class, $tail[1]->getMessage());
        $this->assertTrue($tail[1]->getMessage()->continueAfterCompaction);
        $this->assertInstanceOf(ApplyCommand::class, $this->consumeNextOwnerMessage());
        $cancelled = $registry->requireLoaded($run);
        $this->assertSame(RunStatus::Cancelled, $cancelled->status);
        $this->assertInstanceOf(CompactRun::class, $this->consumeNextOwnerMessage());
        $settled = $registry->requireLoaded($run);
        $this->assertSame(RunStatus::Cancelled, $settled->status);
        $this->assertSame($cancelled->lastSeq, $settled->lastSeq);
        $this->assertSame([], iterator_to_array($ownerTransport->get()));
        $this->assertSame([], $container->get('messenger.transport.llm')->getSent(), 'Neither compaction nor another model request may be dispatched.');
        $types = array_column($container->get(EventStoreInterface::class)->allFor($run), 'type');
        $this->assertContains('context_compaction_requested', $types);
        $this->assertSame('agent_end', $types[array_key_last($types)]);
        $this->assertNotContains('context_compaction_started', $types);
    }

    #[DataProvider('hookCompactionContinuationCases')]
    public function testHookReplacementSummaryPreservesLifecycleThroughColdOwnerRecovery(bool $continue): void
    {
        $container = self::getContainer();
        $hook = $this->createMock(BeforeCompactionHookInterface::class);
        $hook->expects($this->once())->method('beforeCompaction')->willReturn(BeforeCompactionHookResultDTO::replaceSummary('HOOK_SUMMARY'));
        $container->get(ExtensionHookRegistry::class)->addBeforeCompactionHook($hook);
        $store = $container->get(EventStoreInterface::class);
        if ($continue) {
            $run = $this->completeToolBatchAtCompactionThreshold();
            $this->assertInstanceOf(AdvanceRun::class, $this->consumeNextOwnerMessage());
        } else {
            $container->get(AppConfig::class)->compaction = new CompactionConfig(keepRecentTokens: 10, model: 'llama_cpp_test/test');
            $run = $container->get(HatfieldSessionStore::class)->createSession('hook maintenance');
            $rawMessage = static fn (string $role, string $text): array => ['role' => $role, 'content' => [['type' => 'text', 'text' => $text]]];
            $store->appendMany([
                RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['metadata' => ['model' => 'llama_cpp_test/test', 'session' => []], 'messages' => [
                    $rawMessage('user', 'OLD_CONTEXT '.str_repeat('previous conversation ', 60)),
                    $rawMessage('assistant', str_repeat('previous answer ', 60)),
                    $rawMessage('user', 'Recent question'),
                    $rawMessage('assistant', 'Recent answer'),
                ]]]),
                RunEvent::forAppend($run, 0, 'agent_end', ['reason' => 'completed']),
            ]);
            $container->get(AgentRunnerInterface::class)->compact($run);
            $this->assertInstanceOf(ApplyCommand::class, $this->consumeNextOwnerMessage());
        }
        $request = $this->consumeNextOwnerMessage();
        $this->assertInstanceOf(CompactRun::class, $request);
        $this->assertSame($continue, $request->continueAfterCompaction);
        $registry = $container->get(ActiveRunContextInterface::class);
        $warm = $registry->requireLoaded($run);
        $expectedStatus = $continue ? RunStatus::Running : RunStatus::Completed;
        $this->assertSame($expectedStatus, $warm->status);
        $this->assertSame([], $container->get('messenger.transport.llm')->getSent(), 'A hook replacement must bypass the compaction worker.');
        $registry->release($run);
        $cold = $container->get(RunStateRebuilderInterface::class)->rebuildIfStale(RunState::queued($run), $run)->rebuiltState;
        $this->assertNotNull($cold);
        $this->assertSame($expectedStatus, $cold->status);
        $this->assertEquals($warm->messages, $cold->messages);
        if ($continue) {
            // Leave the registry empty: consuming the queued successor must
            // enter the real owner-initialization middleware and replay again.
            $this->assertInstanceOf(AdvanceRun::class, $this->consumeNextOwnerMessage());
            $this->assertSame(RunStatus::Running, $registry->requireLoaded($run)->status);
            $llmTransport = $container->get('messenger.transport.llm');
            $envelopes = iterator_to_array($llmTransport->get());
            $this->assertCount(1, $envelopes);
            $this->assertInstanceOf(ExecuteLlmStep::class, $envelopes[0]->getMessage());
            $platform = $this->createMock(PlatformInterface::class);
            $platform->expects($this->once())->method('invoke')->willReturnCallback(function (ModelInvocationRequest $request): PlatformInvocationResult {
                $history = json_encode(array_map(static fn ($message): array => $message->toArray(), $request->input->messages), \JSON_THROW_ON_ERROR);
                $this->assertStringContainsString('HOOK_SUMMARY', $history);
                $this->assertStringNotContainsString('OLD_CONTEXT', $history);

                return new PlatformInvocationResult(SymfonyAiTestMessages::assistantText('Work continued.'));
            });
            $container->set(ExecuteLlmStepWorker::class, new ExecuteLlmStepWorker($platform, $container->get('agent.command.bus')));
            $container->get('agent.execution.bus')->dispatch($envelopes[0]->with(new ReceivedStamp('llm')));
            $llmTransport->ack($envelopes[0]);
            $this->assertNotContains('agent_command_applied', array_column($store->allFor($run), 'type'), 'Cold continuation must not require another user command.');
        } else {
            $this->assertSame([], iterator_to_array($container->get('messenger.transport.run_control')->get()));
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function hookCompactionContinuationCases(): iterable
    {
        yield 'post-tool continuation' => [true];
        yield 'manual maintenance' => [false];
    }

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
        $this->assertInstanceOf(ExecuteCompactionStep::class, $workerEnvelope->getMessage());
        $container->get('agent.execution.bus')->dispatch($workerEnvelope->with(new ReceivedStamp('llm')));
        $sent = $ownerTransport->getSent();
        $this->assertCount(3, $sent);
        $this->assertInstanceOf(CompactionStepResult::class, $sent[2]->getMessage());
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
        $replay = $container->get(RunStateRebuilderInterface::class)->rebuildIfStale(RunState::queued($run), $run)->rebuiltState;
        $this->assertEquals($completed->messages, $replay->messages);
        $this->assertSame($completed->status, $replay->status);
        $this->assertSame($completed->lastSeq, $replay->lastSeq);
        // Command and worker-result redelivery cannot compact twice or revive the old work.
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
        $queued = $bus->dispatch(new AttachRun($run, []));
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
        $this->assertMaintenanceDidNotScheduleCompaction($run);
    }

    #[DataProvider('deferredRepairCases')]
    public function testRepairRedrivesExistingDeferredChildrenAfterOwnerRestart(int $children, bool $completedSibling, bool $completedOrdinaryTool = false): void
    {
        [$run, $batchId, $childIds] = $this->seedDeferredRepair($children, $completedSibling, $completedOrdinaryTool);
        $container = self::getContainer();
        $bus = $container->get('agent.command.bus');
        $llm = $container->get('messenger.transport.llm');
        $tools = $container->get('messenger.transport.tool');
        $registry = $container->get(ActiveRunContextInterface::class);
        $store = $container->get(EventStoreInterface::class);
        $before = [];
        foreach ([$run, ...$childIds] as $id) {
            $before[$id] = $store->allFor($id);
            $registry->release($id);
        }
        $keys = array_map($container->get(DeferredSubagentChildRepository::class)->findProviderCacheKey(...), $childIds);
        $this->assertCount(0, $llm->getSent());
        $preview = $bus->dispatch(new RepairSession($run, false, 'deferred-preview'));
        $bus->dispatch($preview->with(new ReceivedStamp('run_control')));
        $this->assertCount(0, $llm->getSent());
        $this->assertCount($completedOrdinaryTool ? 2 : 1, $tools->getSent(), 'Preview must not requeue the parent fork invocation.');
        foreach ($before as $id => $events) {
            $this->assertEquals($events, $store->allFor((string) $id));
            $registry->release((string) $id);
        }
        $apply = $bus->dispatch(new RepairSession($run, true, 'deferred-apply'));
        $handled = $bus->dispatch($apply->with(new ReceivedStamp('run_control')));
        $result = $handled->last(HandledStamp::class)->getResult();
        $expected = $completedSibling ? [$childIds[0]] : $childIds;
        $this->assertCount(\count($expected), $llm->getSent(), 'Repair must retry the stranded child LLM request, not just its parent fork.');
        $this->assertSame(\count($expected), $result->activeOperationsRedriven);
        $this->assertNull($result->refusalReason);
        $this->assertCount($completedOrdinaryTool ? 2 : 1, $tools->getSent(), 'Do not launch or requeue another fork.');
        foreach ($llm->getSent() as $index => $envelope) {
            $message = $envelope->getMessage();
            $this->assertInstanceOf(ExecuteLlmStep::class, $message);
            $this->assertSame($expected[$index], $message->runId());
            $this->assertSame('child-step-'.$index, $message->stepId());
            $this->assertSame('child-key-'.$index, $message->idempotencyKey());
            $this->assertSame(1, $message->attempt());
            $this->assertSame(1, $message->turnNo());
        }
        $rows = $container->get(DeferredSubagentChildRepository::class)->findOrderedByBatchLifecycleId($batchId);
        $this->assertSame($childIds, array_column($rows, 'childRunId'));
        $this->assertSame($keys, array_map($container->get(DeferredSubagentChildRepository::class)->findProviderCacheKey(...), $childIds));
        foreach ($before as $id => $events) {
            $this->assertEquals($events, $store->allFor((string) $id), 'Repair must not fabricate completion or rewind canonical history.');
        }
    }

    public static function deferredRepairCases(): iterable
    {
        yield 'fork' => [1, false];
        yield 'parallel children' => [2, false];
        yield 'completed sibling stays completed' => [2, true];
        yield 'completed ordinary tool does not block child repair' => [1, false, true];
    }

    public function testDeferredRepairCompletionUnblocksParent(): void
    {
        $container = self::getContainer();
        [$run, $batchId, $children] = $this->seedDeferredRepair(1);
        $registry = $container->get(ActiveRunContextInterface::class);
        $registry->release($run);
        $registry->release($children[0]);
        $bus = $container->get('agent.command.bus');
        $owner = $container->get('messenger.transport.run_control');
        $repair = $bus->dispatch(new RepairSession($run, true, 'unblock-parent'));
        $bus->dispatch($repair->with(new ReceivedStamp('run_control')));
        $owner->ack($repair);
        $platform = $this->createMock(PlatformInterface::class);
        $platform->expects($this->once())->method('invoke')->willReturn(new PlatformInvocationResult(SymfonyAiTestMessages::assistantText('Child work completed.'), stopReason: 'stop'));
        $container->set(ExecuteLlmStepWorker::class, new ExecuteLlmStepWorker($platform, $bus));
        $llm = $container->get('messenger.transport.llm');
        $requests = iterator_to_array($llm->get());
        $this->assertCount(1, $requests);
        $container->get('agent.execution.bus')->dispatch($requests[0]->with(new ReceivedStamp('llm')));
        $llm->ack($requests[0]);
        // Drain real owner messages until the resolved fork schedules the next
        // parent model step. This is a bounded queue drain, not a timing race.
        for ($i = 0; $i < 16 && [] !== ($pending = iterator_to_array($owner->get())); ++$i) {
            foreach ($pending as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp('run_control')));
                $owner->ack($envelope);
            }
        }
        $this->assertSame([], iterator_to_array($owner->get()), 'Owner completion messages must drain without a cycle.');
        $this->assertSame([], $registry->requireLoaded($run)->pendingToolCalls);
        $this->assertSame(RunStatus::Completed, $registry->requireLoaded($children[0])->status);
        $this->assertNotNull($container->get(DeferredSubagentBatchRepository::class)->findByLifecycleId($batchId)->terminalCompletionEnqueuedAt);
        $next = iterator_to_array($llm->get());
        $this->assertCount(1, $next);
        $this->assertInstanceOf(ExecuteLlmStep::class, $next[0]->getMessage());
        $this->assertSame($run, $next[0]->getMessage()->runId(), 'The parent must continue after receiving the repaired child result.');
    }

    public function testDeferredRepairRefusesStreamingChild(): void
    {
        [$run, , $children] = $this->seedDeferredRepair(1);
        $container = self::getContainer();
        $initializer = $container->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class);
        $initializer->initializeForOwner($children[0], new RepairSession($children[0], false, 'initialize'));
        $registry = $container->get(ActiveRunContextInterface::class);
        $registry->replaceCurrent($registry->requireLoaded($children[0])->with(['isStreaming' => true]));
        $bus = $container->get('agent.command.bus');
        $repair = $bus->dispatch(new RepairSession($run, true, 'streaming-child'));
        $handled = $bus->dispatch($repair->with(new ReceivedStamp('run_control')));
        $this->assertSame(SessionRepairRefusalReasonEnum::ActiveStreaming, $handled->last(HandledStamp::class)->getResult()->refusalReason);
        $this->assertSame([], $container->get('messenger.transport.llm')->getSent());
        $this->assertCount(1, $container->get('messenger.transport.tool')->getSent(), 'Refusal must not requeue the fork.');
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
        $handler->expects($this->once())->method('handle')->willReturn(new \Ineersa\AgentCore\Application\Pipeline\HandlerResult(postCommit: [function () use ($run): void {
            $this->autoCompactionBus->dispatch(new AdvanceRun($run, 0, 'user-advance', 1, 'user-advance'));
        }]));
        $dispatcher = new \Ineersa\AgentCore\Application\Handler\StepDispatcher($this->autoCompactionBus, $this->autoCompactionBus);
        $processor = new \Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor(
            $active,
            self::getContainer()->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class),
            self::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\RunCommit::class),
            $dispatcher,
            [$handler],
            self::getContainer()->get(\Ineersa\AgentCore\Contract\History\HistoryTailDiscardInterface::class),
        );
        $processor->process('user-command', new ApplyCommand($run, 0, 'steer', 1, 'steer', 'steer'));
        $this->assertCount(1, $this->autoCompactionBus->messages);
        $this->assertInstanceOf(AdvanceRun::class, $this->autoCompactionBus->messages[0]);
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
        $bus->dispatch(new AttachRun($run, []));
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
        $replay = $container->get(RunStateRebuilderInterface::class);
        $registry = $container->get(ActiveRunContextInterface::class);
        $registry->loadRecovered($replay->rebuildIfStale(RunState::queued($run), $run)->rebuiltState);
        $container->get(\Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface::class)->selectPrompt($run, 2);
        $processor = $container->get(\Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor::class);
        $processor->process('test', new ApplyCommand($run, 1, 'append-selected', 1, 'append-selected', 'append_message', ['message' => $rawMessage('user', 'NEW_CONTEXT')]));
        $this->assertContains('history_tail_discarded', array_column($store->allFor($run), 'type'));
        // Persist only the cancellation acceptance to reproduce interruption before terminalization.
        $store->append(RunEvent::forAppend($run, 1, 'agent_command_applied', ['kind' => 'cancel']));
        $registry->loadRecovered($replay->rebuildIfStale(RunState::queued($run), $run)->rebuiltState);
        $result = $container->get(\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface::class)->repair($run, true);
        $this->assertTrue($result->staleCancellationRepaired, $result->message);
        $immediate = $registry->requireLoaded($run);
        $cold = $replay->rebuildIfStale(RunState::queued($run), $run)->rebuiltState;
        $this->assertSame(1, $immediate->turnNo);
        $this->assertSame($store->latestSequenceFor($run), $immediate->lastSeq);
        $this->assertEquals($cold->messages, $immediate->messages);
        $this->assertSame($cold->status, $immediate->status);
        $this->assertSame($cold->turnNo, $immediate->turnNo);
        $processor->process('test', new ApplyCommand($run, 1, 'follow-after-repair', 1, 'follow-after-repair', 'follow_up', ['message' => $rawMessage('user', 'CONTINUE_RETAINED')]));
        $processor->process('test', new AdvanceRun($run, 1, 'invoke-retained', 1, 'invoke-retained'));
        $sent = $container->get('messenger.transport.llm')->getSent();
        $this->assertCount(1, $sent);
        $request = $sent[0]->getMessage();
        $this->assertInstanceOf(ExecuteLlmStep::class, $request);
        $texts = json_encode(array_map(static fn ($message): array => $message->toArray(), $request->messages), \JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('RETAINED_ASSISTANT', $texts);
        $this->assertStringContainsString('CONTINUE_RETAINED', $texts);
        $this->assertStringNotContainsString('DISCARDED_PROMPT', $texts);
        $this->assertStringNotContainsString('DISCARDED_ASSISTANT', $texts);
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
        $childObserver = new \Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation\DeferredSubagentBatchChildTurnHookSubscriber(
            $container->get(DeferredSubagentChildRepository::class),
            $container->get('agent.command.bus'),
            new \Psr\Log\NullLogger(),
        );
        $container->set(\Ineersa\AgentCore\Application\Handler\HookDispatcher::class, new \Ineersa\AgentCore\Application\Handler\HookDispatcher([$observer, $subscriber, $childObserver]));
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
            $container->get(DeferredSubagentBatchRepository::class),
            $container->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Recovery\DeferredSubagentBatchRecoveryService::class),
        ));
    }

    /** @return array{string, string, list<string>} */
    private function seedDeferredRepair(int $children, bool $completedSibling = false, bool $completedOrdinaryTool = false): array
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('deferred repair');
        $store = $container->get(EventStoreInterface::class);
        $store->appendMany([
            RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['metadata' => ['model' => 'llama_cpp_test/test', 'session' => []], 'messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Finish this task.']]],
            ]]]),
            RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'parent-step', 'operation_attempt' => 1, 'operation_idempotency_key' => 'parent-key']),
        ]);
        $bus = $container->get('agent.command.bus');
        $calls = [['id' => 'fork-current', 'name' => 'fork', 'arguments' => ['task' => 'Finish existing work.']]];
        if ($completedOrdinaryTool) {
            array_unshift($calls, ['id' => 'read-done', 'name' => 'read', 'arguments' => ['path' => './notes.txt']]);
        }
        $queued = $bus->dispatch(new LlmStepResult($run, 1, 'parent-step', 1, 'parent-key', assistantMessage: SymfonyAiTestMessages::assistantWithToolCalls($calls), model: 'llama_cpp_test/test', stopReason: 'tool_call'));
        $bus->dispatch($queued->with(new ReceivedStamp('run_control')));
        $container->get('messenger.transport.run_control')->ack($queued);
        if ($completedOrdinaryTool) {
            $read = $container->get('messenger.transport.tool')->getSent()[0]->getMessage();
            $result = $bus->dispatch(new ToolCallResult($run, $read->turnNo(), $read->stepId(), $read->attempt(), $read->idempotencyKey(), $read->toolCallId, $read->orderIndex, result: ['content' => [['type' => 'text', 'text' => 'Notes read.']]]));
            $bus->dispatch($result->with(new ReceivedStamp('run_control')));
            $container->get('messenger.transport.run_control')->ack($result);
        }
        $factory = new DeferredSubagentBatchIdentityFactory();
        $batchId = $factory->batchLifecycleId($run, 'fork-current');
        $call = $container->get('messenger.transport.tool')->getSent()[$completedOrdinaryTool ? 1 : 0]->getMessage();
        $container->get(\Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface::class)->registerPending(new \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation(
            $batchId, $run, $call->turnNo(), $call->stepId(), $call->attempt(), $call->idempotencyKey(), $call->toolCallId, $call->toolName, $call->args, $call->orderIndex,
            $call->toolIdempotencyKey, $call->mode, $call->timeoutSeconds, $call->maxParallelism, $call->assistantMessage, $call->argSchema, $call->toolsRef,
        ));
        $intents = [];
        $childIds = [];
        for ($i = 0; $i < $children; ++$i) {
            $identity = $factory->childIdentity($run, 'fork-current', $i + 1);
            $childIds[] = $child = $identity['childRunId'];
            $container->get(AgentArtifactRegistry::class)->create($run, $identity['artifactId'], $child, 'fork', AgentArtifactKindEnum::Fork);
            $intents[] = ['batchIndex' => $i + 1, 'childRunId' => $child, 'artifactId' => $identity['artifactId'], 'agentName' => 'fork', 'task' => 'Finish existing work.', 'launchModel' => 'llama_cpp_test/test', 'launchReasoning' => 'medium'];
            $store->appendMany([
                RunEvent::forAppend($child, 0, 'run_started', ['payload' => ['metadata' => ['model' => 'llama_cpp_test/test', 'reasoning' => 'medium', 'tools_scope' => ['allowed_tools' => []], 'session' => ['kind' => 'agent_child', 'child_kind' => 'fork', 'parent_run_id' => $run, 'agent_name' => 'fork', 'artifact_id' => $identity['artifactId']]], 'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Finish child work.']]],
                ]]]),
                RunEvent::forAppend($child, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'child-step-'.$i, 'operation_attempt' => 1, 'operation_idempotency_key' => 'child-key-'.$i]),
            ]);
            if ($completedSibling && 1 === $i) {
                $store->append(RunEvent::forAppend($child, 1, 'agent_end', ['reason' => 'completed']));
            }
        }
        $batches = $container->get(DeferredSubagentBatchRepository::class);
        $batches->reserveBatch($batchId, $run, 1, 'fork-current', $call->orderIndex, $children > 1 ? ChildRunBatchExecutionModeEnum::Parallel : ChildRunBatchExecutionModeEnum::Single, $children,
            \Symfony\Component\Clock\Clock::get()->now()->modify('+1 hour'), $intents);
        $batches->applyLaunchSuccessState($run, 'fork-current', $batchId, \Symfony\Component\Clock\Clock::get()->now(), range(1, $children));

        return [$run, $batchId, $childIds];
    }

    private function completeToolBatchAtCompactionThreshold(): string
    {
        $container = self::getContainer();
        $model = 'openai-codex/gpt-6.1-sol';
        $threshold = $container->get(CompactionConfig::class)->resolveRuntimeSettings($model)->compactAfterTokens;
        $container->get(AppConfig::class)->compaction = new CompactionConfig(compactAfterTokens: $threshold, keepRecentTokens: 10, model: 'llama_cpp_test/test');
        $run = $container->get(HatfieldSessionStore::class)->createSession('post-tool compaction');
        $container->get(EventStoreInterface::class)->appendMany([
            RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['metadata' => ['model' => $model, 'session' => []], 'messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'OLD_CONTEXT '.str_repeat('Continue this task through tools. ', 60)]]],
            ]]]),
            RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'tool-step', 'operation_attempt' => 1, 'operation_idempotency_key' => 'tool-step-key']),
        ]);
        $bus = $container->get('agent.command.bus');
        $bus->dispatch(new LlmStepResult($run, 1, 'tool-step', 1, 'tool-step-key',
            assistantMessage: SymfonyAiTestMessages::assistantWithToolCalls([['id' => 'read-call', 'name' => 'read', 'arguments' => ['path' => './file.txt']]]),
            usage: ['input_tokens' => $threshold], stopReason: 'tool_call',
        ));
        $this->assertInstanceOf(LlmStepResult::class, $this->consumeNextOwnerMessage());
        $toolTransport = $container->get('messenger.transport.tool');
        $envelopes = iterator_to_array($toolTransport->get());
        $this->assertCount(1, $envelopes);
        $tool = $envelopes[0]->getMessage();
        $this->assertInstanceOf(ExecuteToolCall::class, $tool);
        $bus->dispatch(new ToolCallResult($run, $tool->turnNo(), $tool->stepId(), 1, $tool->idempotencyKey(), $tool->toolCallId, $tool->orderIndex,
            result: ['content' => [['type' => 'text', 'text' => str_repeat('Collected tool output. ', 30)]]],
        ));
        $toolTransport->ack($envelopes[0]);
        $this->assertInstanceOf(ToolCallResult::class, $this->consumeNextOwnerMessage());

        return $run;
    }

    private function consumeNextOwnerMessage(): object
    {
        $container = self::getContainer();
        $transport = $container->get('messenger.transport.run_control');
        $envelopes = iterator_to_array($transport->get());
        $this->assertCount(1, $envelopes);
        $container->get('agent.command.bus')->dispatch($envelopes[0]->with(new ReceivedStamp('run_control')));
        $transport->ack($envelopes[0]);

        return $envelopes[0]->getMessage();
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
