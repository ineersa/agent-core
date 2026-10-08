<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\ExecuteCompactionStepWorker;
use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
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
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\SymfonyAiTestMessages;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
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
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class SessionMaintenanceRoutingTest extends PerMethodIsolatedKernelTestCase
{
    /** @var list<object> */
    private array $autoCompactionBusMessages = [];
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
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $run = $container->get(HatfieldSessionStore::class)->createSession('active compaction');
        PreparedEventStoreSeeder::append($store, RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['metadata' => ['model' => $activeModel, 'session' => []], 'messages' => [
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
        $container->set(ExecuteLlmStepWorker::class, new ExecuteLlmStepWorker($platform));
        $container->set(ExecuteCompactionStepWorker::class, new ExecuteCompactionStepWorker($platform));
        $consumeOwner = static function () use ($bus, $ownerTransport): void {
            $sent = $ownerTransport->getSent();
            $bus->dispatch($sent[array_key_last($sent)]->with(new ReceivedStamp('run_control')));
        };
        $consumeLlm = static function () use ($container, $llmTransport): void {
            $sent = $llmTransport->getSent();
            $container->get('agent.execution.bus')->dispatch($sent[array_key_last($sent)]->with(new ReceivedStamp('llm')));
        };
        $resolveTool = function (Envelope $envelope) use ($run): void {
            $tool = $this->peekExecutionRequest($envelope);
            $this->assertInstanceOf(ExecuteToolCall::class, $tool);
            $this->completeAuthorizedExecution($envelope, new ToolCallResult(
                $run,
                $tool->turnNo(),
                $tool->stepId(),
                1,
                $tool->idempotencyKey(),
                $tool->toolCallId,
                $tool->orderIndex,
                result: ['content' => [['type' => 'text', 'text' => str_repeat('Collected tool output. ', 30)]]],
            ));
        };
        $bus->dispatch(new AdvanceRun($run, 0, 'first-advance', 1, 'first-advance'));
        $consumeOwner();
        $consumeLlm();
        $consumeOwner();
        $resolveTool($toolTransport->getSent()[0]);
        $consumeOwner();
        $belowThreshold = $llmTransport->getSent()[1]->getMessage();
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ExecutionRequest::class, $belowThreshold, 'Below threshold, the next tool cycle must run without compaction.');
        $this->assertSame(ExecuteLlmStep::class, $belowThreshold->requestType);
        $consumeLlm();
        $consumeOwner();
        $this->assertCount(3, $toolTransport->getSent());
        $secondTool = $this->peekExecutionRequest($toolTransport->getSent()[1]);
        $thirdTool = $this->peekExecutionRequest($toolTransport->getSent()[2]);
        $this->assertInstanceOf(ExecuteToolCall::class, $secondTool);
        $this->assertInstanceOf(ExecuteToolCall::class, $thirdTool);
        $resolveTool($toolTransport->getSent()[1]);
        // Even an early scheduler delivery cannot compact an unresolved batch.
        $state = $registry->requireLoaded($run);
        $bus->dispatch(new AdvanceRun($run, $state->turnNo, 'early-advance', 1, 'early-advance'));
        $consumeOwner();
        $this->assertCount(2, $llmTransport->getSent());
        $this->assertSame([], $this->autoCompactionBusMessages);
        $resolveTool($toolTransport->getSent()[2]);
        $consumeOwner();
        $sent = $ownerTransport->getSent();
        $compact = $sent[array_key_last($sent)]->getMessage();
        $this->assertInstanceOf(CompactRun::class, $compact);
        $this->assertTrue($compact->continueAfterCompaction);
        $consumeOwner();
        $compactionRequest = $llmTransport->getSent()[2]->getMessage();
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ExecutionRequest::class, $compactionRequest);
        $this->assertSame(ExecuteCompactionStep::class, $compactionRequest->requestType);
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
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        if ($continue) {
            $run = $this->completeToolBatchAtCompactionThreshold();
            $this->assertInstanceOf(AdvanceRun::class, $this->consumeNextOwnerMessage());
        } else {
            $container->get(AppConfig::class)->compaction = new CompactionConfig(keepRecentTokens: 10, model: 'llama_cpp_test/test');
            $run = $container->get(HatfieldSessionStore::class)->createSession('hook maintenance');
            $rawMessage = static fn (string $role, string $text): array => ['role' => $role, 'content' => [['type' => 'text', 'text' => $text]]];
            PreparedEventStoreSeeder::appendMany($store, [
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
            $continuation = $envelopes[0]->getMessage();
            $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ExecutionRequest::class, $continuation);
            $this->assertSame(ExecuteLlmStep::class, $continuation->requestType);
            $platform = $this->createMock(PlatformInterface::class);
            $platform->expects($this->once())->method('invoke')->willReturnCallback(function (ModelInvocationRequest $request): PlatformInvocationResult {
                $history = json_encode(array_map(static fn ($message): array => $message->toArray(), $request->input->messages), \JSON_THROW_ON_ERROR);
                $this->assertStringContainsString('HOOK_SUMMARY', $history);
                $this->assertStringNotContainsString('OLD_CONTEXT', $history);

                return new PlatformInvocationResult(SymfonyAiTestMessages::assistantText('Work continued.'));
            });
            $container->set(ExecuteLlmStepWorker::class, new ExecuteLlmStepWorker($platform));
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
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $registry = $container->get(ActiveRunContextInterface::class);
        $rawMessage = static fn (string $role, string $text): array => ['role' => $role, 'content' => [['type' => 'text', 'text' => $text]]];
        PreparedEventStoreSeeder::appendMany($store, [
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
        $toolEnvelope = $toolTransport->getSent()[0];
        $tool = $this->peekExecutionRequest($toolEnvelope);
        $this->assertInstanceOf(ExecuteToolCall::class, $tool);
        $cancel = new ApplyCommand($run, 1, 'cancel', 1, 'cancel-key', CoreCommandKind::Cancel);
        $bus->dispatch($bus->dispatch($cancel)->with(new ReceivedStamp('run_control')));
        $this->assertSame(RunStatus::Cancelling, $registry->requireLoaded($run)->status);
        // Resolve the cancelled worker without executing its read or starting another turn.
        $this->completeAuthorizedExecution($toolEnvelope, new ToolCallResult(
            $run,
            1,
            $tool->stepId(),
            1,
            $tool->idempotencyKey(),
            $tool->toolCallId,
            0,
            result: ['content' => [['type' => 'text', 'text' => 'Tool call cancelled.']], 'details' => ['cancelled' => true]],
            isError: true,
        ));
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
        $container->set(ExecuteCompactionStepWorker::class, new ExecuteCompactionStepWorker($platform));

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
        $this->assertSame([], array_values(array_filter($ownerTransport->getSent(), static fn ($envelope): bool => ($message = $envelope->getMessage()) instanceof CompactRun ? 'auto' === $message->trigger : ($message instanceof \Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO && $message->message instanceof CompactRun && 'auto' === $message->message->trigger))));
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
        $store = self::getContainer()->get(PreparedTransitionEventStoreInterface::class);
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

    #[DataProvider('deferredRepairCases')]
    public function testRepairCancelsExistingDeferredChildrenAfterOwnerRestart(int $children, bool $completedSibling, bool $completedOrdinaryTool = false): void
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
        $this->assertCount(0, $llm->getSent(), 'Repair must cancel unfinished children without retrying their provider requests.');
        $this->assertStringContainsString('cancelled', $result->message);
        $this->assertNull($result->refusalReason);
        $this->assertCount($completedOrdinaryTool ? 2 : 1, $tools->getSent(), 'Do not launch or requeue another fork.');
        foreach ($expected as $childId) {
            $this->assertSame(RunStatus::Cancelled, $registry->requireLoaded($childId)->status);
            $cold = $container->get(RunStateRebuilderInterface::class)->rebuildIfStale(RunState::queued($childId), $childId)->rebuiltState;
            $this->assertSame(RunStatus::Cancelled, $cold->status, 'Cancellation must survive owner recreation.');
            $this->assertNull($cold->currentOperation);
        }
        $rows = $container->get(DeferredSubagentChildRepository::class)->findOrderedByBatchLifecycleId($batchId);
        $this->assertSame($childIds, array_column($rows, 'childRunId'));
        $this->assertSame($keys, array_map($container->get(DeferredSubagentChildRepository::class)->findProviderCacheKey(...), $childIds));
        foreach ($before as $id => $events) {
            $after = $store->allFor((string) $id);
            $this->assertEquals($events, \array_slice($after, 0, \count($events)), 'Repair must preserve all prior canonical events.');
            if (!\in_array((string) $id, $expected, true)) {
                $this->assertEquals($events, $after, 'Parent and completed sibling histories must remain unchanged.');
            }
        }
    }

    public static function deferredRepairCases(): iterable
    {
        yield 'fork' => [1, false];
        yield 'parallel children' => [2, false];
        yield 'completed sibling stays completed' => [2, true];
        yield 'completed ordinary tool does not block child repair' => [1, false, true];
    }

    public function testDeferredRepairCancellationUnblocksQueuedParentInputAndRejectsLateChildResult(): void
    {
        $container = self::getContainer();
        [$run, $batchId, $children] = $this->seedDeferredRepair(1);
        $registry = $container->get(ActiveRunContextInterface::class);
        $registry->release($run);
        $registry->release($children[0]);
        $bus = $container->get('agent.command.bus');
        $owner = $container->get('messenger.transport.run_control');
        $followUp = $bus->dispatch(new ApplyCommand($run, 1, 'queued-input', 1, 'queued-input', CoreCommandKind::FollowUp, ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Continue after abandoning the fork.']]]]));
        $bus->dispatch($followUp->with(new ReceivedStamp('run_control')));
        $owner->ack($followUp);
        $repair = $bus->dispatch(new RepairSession($run, true, 'unblock-parent'));
        $bus->dispatch($repair->with(new ReceivedStamp('run_control')));
        $owner->ack($repair);
        $llm = $container->get('messenger.transport.llm');
        $this->assertSame([], $llm->getSent(), 'No child provider request may be retried.');
        $store = $container->get(EventStoreInterface::class);
        $beforeLateResult = $store->allFor($children[0]);
        $late = $bus->dispatch(new LlmStepResult($children[0], 1, 'child-step-0', 1, 'child-key-0', assistantMessage: SymfonyAiTestMessages::assistantText('Late child response.'), stopReason: 'stop'));
        $bus->dispatch($late->with(new ReceivedStamp('run_control')));
        $owner->ack($late);
        $this->assertEquals($beforeLateResult, $store->allFor($children[0]), 'Late results from an abandoned request must not revive the child.');
        // Drain real cancellation/completion messages without invoking a child
        // provider. This is a bounded queue drain, not a timing race.
        for ($i = 0; $i < 16 && [] !== ($pending = iterator_to_array($owner->get())); ++$i) {
            foreach ($pending as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp('run_control')));
                $owner->ack($envelope);
            }
        }
        $this->assertSame([], iterator_to_array($owner->get()), 'Owner completion messages must drain without a cycle.');
        $this->assertSame([], $registry->requireLoaded($run)->pendingToolCalls);
        $this->assertSame(RunStatus::Cancelled, $registry->requireLoaded($children[0])->status);
        $this->assertSame(RunStatus::Running, $registry->requireLoaded($run)->status, 'Repair must not cancel the parent.');
        $this->assertNotNull($container->get(DeferredSubagentBatchRepository::class)->findByLifecycleId($batchId)->terminalCompletionEnqueuedAt);
        $next = iterator_to_array($llm->get());
        $this->assertCount(1, $next);
        $parentRequest = $this->resolveExecutionRequest($next[0]);
        $this->assertInstanceOf(ExecuteLlmStep::class, $parentRequest);
        $this->assertSame($run, $parentRequest->runId(), 'The parent must continue after receiving the repaired child result.');
        $messages = json_encode(array_map(static fn ($message): array => $message->toArray(), $parentRequest->messages), \JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Continue after abandoning the fork.', $messages);
        $this->assertStringContainsString('cancelled', $messages);
        $this->assertStringNotContainsString('Late child response.', $messages);
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

    #[DataProvider('deferredChildMaintenanceInterruptions')]
    public function testDeferredChildMaintenanceRecoversCapturedObligationsWithoutFreshPolicy(string $boundary): void
    {
        [$run, $batchId, $childIds] = $this->seedDeferredRepair(2);
        $c = self::getContainer();
        $store = $c->get(PreparedTransitionEventStoreInterface::class);
        $registry = $c->get(ActiveRunContextInterface::class);
        $acceptance = $c->get(\Ineersa\AgentCore\Application\Pipeline\SourceAcceptance::class);
        $source = \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance::actionIdentity(RepairSession::class, $run, 'captured-children');
        $fired = (object) ['value' => false];
        $matches = static fn (array $work): bool => ($work['source']['type'] ?? null) === RepairSession::class
            && ($work['source']['idempotency_key'] ?? null) === 'captured-children'
            && array_any($work['actions'] ?? [], static fn (mixed $action): bool => $action instanceof \Ineersa\CodingAgent\Application\Message\RepairDeferredChildrenDTO);
        $fault = $this->createStub(PreparedTransitionEventStoreInterface::class);
        $fault->method('assertTransitionReady')->willReturnCallback($store->assertTransitionReady(...));
        $fault->method('verifiedPendingTransition')->willReturnCallback($store->verifiedPendingTransition(...));
        $fault->method('appendTransition')->willReturnCallback(static function (array $events, array $work) use ($store, $matches, $boundary, $fired): array {
            $persisted = $store->appendTransition($events, $work);
            if (!$fired->value && 'after_append' === $boundary && $matches($work)) {
                $fired->value = true;
                throw new \RuntimeException('Injected child-maintenance interruption.');
            }

            return $persisted;
        });
        $fault->method('finalizeVerifiedTransition')->willReturnCallback(static function (string $id, string $identity) use ($store, $matches, $boundary, $fired): void {
            $pending = $store->verifiedPendingTransition($id);
            $store->finalizeVerifiedTransition($id, $identity);
            if (!$fired->value && 'after_finalize' === $boundary && null !== $pending && $matches($pending->work)) {
                $fired->value = true;
                throw new \RuntimeException('Injected child-maintenance interruption.');
            }
        });
        $inner = $c->get(\Ineersa\CodingAgent\Application\Pipeline\RepairDeferredChildrenHandler::class);
        $coordinationBus = new \Symfony\Component\Messenger\MessageBus([
            new \Symfony\Component\Messenger\Middleware\HandleMessageMiddleware(new \Symfony\Component\Messenger\Handler\HandlersLocator([
                \Ineersa\CodingAgent\Application\Message\RepairDeferredChildrenDTO::class => [static function (\Ineersa\CodingAgent\Application\Message\RepairDeferredChildrenDTO $action) use ($inner, $boundary, $fired, $c): void {
                    if ('after_first_cancel' !== $boundary || $fired->value) {
                        $inner($action);

                        return;
                    }
                    $cancels = array_values(array_filter($action->obligations, static fn ($obligation): bool => \Ineersa\CodingAgent\Application\Message\RepairDeferredChildObligationDTO::KIND_CANCEL === $obligation->kind));
                    if (\count($cancels) < 2) {
                        throw new \RuntimeException('Expected multiple captured child cancellations.');
                    }
                    // Complete only the first captured cancel outside the verified full plan,
                    // then fail before the remaining obligations run.
                    $first = $cancels[0];
                    $childCommand = new RepairSession($first->childRunId, true, $action->commandId);
                    $c->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class)->initializeForOwner($first->childRunId, $childCommand);
                    $state = $c->get(ActiveRunContextInterface::class)->requireLoaded($first->childRunId);
                    $key = 'repair-cancel-'.$action->commandId.'-'.$first->childRunId;
                    $c->get(\Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor::class)->process('repair', new ApplyCommand($first->childRunId, $state->turnNo, $key, 1, $key, CoreCommandKind::Cancel, ['reason' => 'Cancelled by session repair.']));
                    $childResult = $c->get(\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface::class)->repair($first->childRunId, true, $action->commandId);
                    if (null !== $childResult->refusalReason) {
                        throw new \RuntimeException($childResult->message);
                    }
                    $fired->value = true;
                    throw new \RuntimeException('Injected child-maintenance interruption.');
                }],
            ])),
        ]);
        $commit = new \Ineersa\AgentCore\Application\Pipeline\RunCommit(
            activeRunContext: $registry,
            eventStore: $fault,
            logger: new \Psr\Log\NullLogger(),
            finalizer: TestTransitionFinalizerFactory::create(
                $fault,
                new \Ineersa\AgentCore\Application\Handler\StepDispatcher($coordinationBus, $coordinationBus, new \Ineersa\AgentCore\Tests\Support\TestLogger()),
                batches: $c->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class),
                commands: $c->get(\Ineersa\AgentCore\Contract\CommandStoreInterface::class),
            ),
            actionValidator: $c->get(\Ineersa\AgentCore\Application\Handler\CoordinationActionValidator::class),
        );
        $repair = new \Ineersa\CodingAgent\Session\Repair\SessionRepairService(
            eventStore: $c->get(PreparedTransitionEventStoreInterface::class),
            activeRunContext: $registry,
            runStateReducer: $c->get(\Ineersa\AgentCore\Application\Replay\RunStateReducer::class),
            replayEventPreparer: $c->get(\Ineersa\AgentCore\Application\Replay\ReplayEventPreparer::class),
            eventFactory: $c->get(\Ineersa\AgentCore\Domain\Event\EventFactory::class),
            toolCallSequenceValidator: $c->get(\Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator::class),
            lockManager: $c->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class),
            logger: new \Psr\Log\NullLogger(),
            toolBatchStore: $c->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class),
            serializer: $c->get('serializer'),
            runCommit: $commit,
            historyReplayFilter: $c->get(\Ineersa\CodingAgent\Session\History\HistoryReplayFilter::class),
            deferredBatches: $c->get(DeferredSubagentBatchRepository::class),
        );
        $handler = new \Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler(
            $c->get(\Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface::class),
            $repair,
            $c->get(InMemoryRuntimeEventSink::class),
            $c->get(\Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink::class),
            false,
            new \Psr\Log\NullLogger(),
            $registry,
            $c->get(\Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor::class),
            $c->get(HatfieldSessionStore::class),
            $c->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class),
            $commit,
            $c->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class),
            $c->get(DeferredSubagentBatchRepository::class),
        );
        $command = new RepairSession($run, true, 'captured-children');
        $c->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class)->initializeForOwner($run, $command);
        try {
            $handler->repair($command);
            $this->fail('The configured failure must interrupt child maintenance.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Injected child-maintenance interruption.', $exception->getMessage());
        }
        $this->assertTrue($fired->value);
        $this->assertSame('after_finalize' === $boundary, $acceptance->identityAlreadyAccepted($source));
        if ('after_finalize' !== $boundary) {
            $this->assertNotNull($store->verifiedPendingTransition($run));
        }
        foreach ($childIds as $childId) {
            $registry->release($childId);
        }
        $registry->release($run);
        $c->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class)->initializeForOwner($run, $command);
        $result = $c->get(\Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler::class)->repair($command);
        $this->assertNull($result->refusalReason);
        $this->assertTrue($acceptance->identityAlreadyAccepted($source));
        $this->assertNull($store->verifiedPendingTransition($run));
        $rebuilder = $c->get(RunStateRebuilderInterface::class);
        foreach ($childIds as $childId) {
            $c->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class)->initializeForOwner($childId, new RepairSession($childId, false, 'assert-'.$childId));
            $this->assertSame(RunStatus::Cancelled, $registry->requireLoaded($childId)->status);
            $this->assertSame(RunStatus::Cancelled, $rebuilder->rebuildIfStale(RunState::queued($childId), $childId)->rebuiltState->status);
        }
        $bus = $c->get('agent.command.bus');
        for ($i = 0; $i < 16 && [] !== ($pending = iterator_to_array($c->get('messenger.transport.run_control')->get())); ++$i) {
            foreach ($pending as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp('run_control')));
                $c->get('messenger.transport.run_control')->ack($envelope);
            }
        }
        $this->assertNotNull($c->get(DeferredSubagentBatchRepository::class)->findByLifecycleId($batchId)->terminalCompletionEnqueuedAt);
        $this->assertSame([], $registry->requireLoaded($run)->pendingToolCalls);
        $llm = $c->get('messenger.transport.llm')->getSent();
        $this->assertCount(1, $llm, 'Settled parent repair must continue the parent turn exactly once.');
        $parentRequest = $this->resolveExecutionRequest($llm[0]);
        $this->assertInstanceOf(ExecuteLlmStep::class, $parentRequest);
        $this->assertSame($run, $parentRequest->runId());
    }

    /** @return iterable<string, array{string}> */
    public static function deferredChildMaintenanceInterruptions(): iterable
    {
        yield 'root append captured' => ['after_append'];
        yield 'midway through child cancels' => ['after_first_cancel'];
        yield 'root finalized before ack' => ['after_finalize'];
    }

    public function testAcceptedOldParentRepairDoesNotCancelNewerChildGeneration(): void
    {
        [$run, $oldBatchId, $oldChildren] = $this->seedDeferredRepair(1);
        $c = self::getContainer();
        $bus = $c->get('agent.command.bus');
        $registry = $c->get(ActiveRunContextInterface::class);
        $old = $bus->dispatch(new RepairSession($run, true, 'old-parent-repair'));
        $bus->dispatch($old->with(new ReceivedStamp('run_control')));
        $this->assertSame(RunStatus::Cancelled, $registry->requireLoaded($oldChildren[0])->status);
        for ($i = 0; $i < 16 && [] !== ($pending = iterator_to_array($c->get('messenger.transport.run_control')->get())); ++$i) {
            foreach ($pending as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp('run_control')));
                $c->get('messenger.transport.run_control')->ack($envelope);
            }
        }
        $this->assertNotNull($c->get(DeferredSubagentBatchRepository::class)->findByLifecycleId($oldBatchId)->terminalCompletionEnqueuedAt);
        $next = iterator_to_array($c->get('messenger.transport.llm')->get());
        $this->assertCount(1, $next);
        $parentRequest = $this->resolveExecutionRequest($next[0]);
        $this->assertInstanceOf(ExecuteLlmStep::class, $parentRequest);
        $c->get('messenger.transport.llm')->ack($next[0]);
        $follow = $bus->dispatch(new LlmStepResult($run, $parentRequest->turnNo(), $parentRequest->stepId(), $parentRequest->attempt(), $parentRequest->idempotencyKey(), assistantMessage: SymfonyAiTestMessages::assistantWithToolCalls([['id' => 'fork-next', 'name' => 'fork', 'arguments' => ['task' => 'Start newer work.']]]), model: 'llama_cpp_test/test', stopReason: 'tool_call'));
        $bus->dispatch($follow->with(new ReceivedStamp('run_control')));
        $c->get('messenger.transport.run_control')->ack($follow);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $newBatchId = $factory->batchLifecycleId($run, 'fork-next');
        $callEnvelope = $c->get('messenger.transport.tool')->getSent()[array_key_last($c->get('messenger.transport.tool')->getSent())];
        $call = $this->peekExecutionRequest($callEnvelope);
        $registration = $c->get(\Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface::class)->registerPending(new \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation(
            $newBatchId, $run, $call->turnNo(), $call->stepId(), $call->attempt(), $call->idempotencyKey(), $call->toolCallId, $call->toolName, $call->args, $call->orderIndex,
            $call->toolIdempotencyKey, $call->mode, $call->timeoutSeconds, $call->maxParallelism, null, $call->argSchema, $call->toolsRef,
        ));
        $reference = $callEnvelope->getMessage();
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ExecutionRequest::class, $reference);
        $authorization = $callEnvelope->last(\Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp::class);
        $this->assertNotNull($authorization);
        $operations = $c->get(\Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface::class);
        $claim = $operations->claim($reference, $authorization);
        $this->assertIsString($claim);
        $operations->resolveRequest($reference, $authorization, $claim);
        $operations->transferToDeferred($reference, $authorization, $claim, $registration->deferredId);
        $identity = $factory->childIdentity($run, 'fork-next', 1);
        $newChild = $identity['childRunId'];
        $c->get(AgentArtifactRegistry::class)->create($run, $identity['artifactId'], $newChild, 'fork', AgentArtifactKindEnum::Fork);
        PreparedEventStoreSeeder::appendMany($c->get(PreparedTransitionEventStoreInterface::class), [
            RunEvent::forAppend($newChild, 0, 'run_started', ['payload' => ['metadata' => ['model' => 'llama_cpp_test/test', 'reasoning' => 'medium', 'tools_scope' => ['allowed_tools' => []], 'session' => ['kind' => 'agent_child', 'child_kind' => 'fork', 'parent_run_id' => $run, 'agent_name' => 'fork', 'artifact_id' => $identity['artifactId']]], 'messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Finish newer child work.']]],
            ]]]),
            RunEvent::forAppend($newChild, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'child-next', 'operation_attempt' => 1, 'operation_idempotency_key' => 'child-next-key']),
        ]);
        $batches = $c->get(DeferredSubagentBatchRepository::class);
        $batches->reserveBatch($newBatchId, $run, $call->turnNo(), 'fork-next', $call->orderIndex, ChildRunBatchExecutionModeEnum::Single, 1,
            \Symfony\Component\Clock\Clock::get()->now()->modify('+1 hour'), [['batchIndex' => 1, 'childRunId' => $newChild, 'artifactId' => $identity['artifactId'], 'agentName' => 'fork', 'task' => 'Start newer work.', 'launchModel' => 'llama_cpp_test/test', 'launchReasoning' => 'medium']]);
        $batches->applyLaunchSuccessState($run, 'fork-next', $newBatchId, \Symfony\Component\Clock\Clock::get()->now(), [1]);
        foreach ([$run, $newChild] as $id) {
            $registry->release($id);
        }
        $before = $c->get(EventStoreInterface::class)->allFor($newChild);
        $redeliver = $bus->dispatch(new RepairSession($run, true, 'old-parent-repair'));
        $handled = $bus->dispatch($redeliver->with(new ReceivedStamp('run_control')));
        $this->assertStringContainsString('already accepted', $handled->last(HandledStamp::class)->getResult()->message);
        $this->assertEquals($before, $c->get(EventStoreInterface::class)->allFor($newChild));
        $c->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class)->initializeForOwner($newChild, new RepairSession($newChild, false, 'inspect'));
        $this->assertSame(RunStatus::Running, $registry->requireLoaded($newChild)->status);
        $fresh = $bus->dispatch(new RepairSession($run, true, 'fresh-parent-repair'));
        $freshHandled = $bus->dispatch($fresh->with(new ReceivedStamp('run_control')));
        $this->assertStringContainsString('cancelled', $freshHandled->last(HandledStamp::class)->getResult()->message);
        $this->assertSame(RunStatus::Cancelled, $registry->requireLoaded($newChild)->status);
    }

    public function testDeferredRepairPreviewLeavesHumanWaitAndCompletedSiblingUntouched(): void
    {
        [$run, $batchId, $childIds] = $this->seedDeferredRepair(3, true);
        $c = self::getContainer();
        $human = $childIds[0];
        $active = $childIds[2];
        $completed = $childIds[1];
        $registry = $c->get(ActiveRunContextInterface::class);
        $store = $c->get(EventStoreInterface::class);
        $c->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class)->initializeForOwner($human, new RepairSession($human, false, 'human-setup'));
        $humanRequest = \Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO::toolCallFromPayload(
            ['question_id' => 'child-human', 'prompt' => 'Need confirmation?'],
            ['run_id' => $human, 'turn_no' => 1, 'step_id' => 'child-step-0', 'tool_call_id' => 'ask'],
        );
        $registry->replaceCurrent($registry->requireLoaded($human)->with([
            'status' => RunStatus::WaitingHuman,
            'pendingHumanInputRequests' => [$humanRequest],
            'pendingToolCalls' => ['ask' => false],
            'activeStepId' => 'child-step-0',
        ]));
        $before = [
            (string) $run => $store->allFor($run),
            (string) $human => $store->allFor($human),
            (string) $completed => $store->allFor($completed),
            (string) $active => $store->allFor($active),
        ];
        $bus = $c->get('agent.command.bus');
        $preview = $bus->dispatch(new RepairSession($run, false, 'human-preview'));
        $previewHandled = $bus->dispatch($preview->with(new ReceivedStamp('run_control')));
        $this->assertStringContainsString('would be cancelled', $previewHandled->last(HandledStamp::class)->getResult()->message);
        foreach ($before as $id => $events) {
            $this->assertEquals($events, $store->allFor((string) $id));
        }
        $this->assertSame(RunStatus::WaitingHuman, $registry->requireLoaded($human)->status);
        $this->assertSame(RunStatus::Completed, $registry->requireLoaded($completed)->status);
        foreach ([$run, $human, $completed, $active] as $id) {
            $registry->release($id);
        }
        $c->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class)->initializeForOwner($human, new RepairSession($human, false, 'human-restore'));
        $registry->replaceCurrent($registry->requireLoaded($human)->with([
            'status' => RunStatus::WaitingHuman,
            'pendingHumanInputRequests' => [$humanRequest],
            'pendingToolCalls' => ['ask' => false],
            'activeStepId' => 'child-step-0',
        ]));
        $apply = $bus->dispatch(new RepairSession($run, true, 'human-apply'));
        $applyHandled = $bus->dispatch($apply->with(new ReceivedStamp('run_control')));
        $this->assertStringContainsString('cancelled', $applyHandled->last(HandledStamp::class)->getResult()->message);
        $this->assertSame(RunStatus::WaitingHuman, $registry->requireLoaded($human)->status);
        $this->assertSame(RunStatus::Completed, $registry->requireLoaded($completed)->status);
        $this->assertSame(RunStatus::Cancelled, $registry->requireLoaded($active)->status);
        $this->assertEquals($before[(string) $completed], $store->allFor($completed));
        for ($i = 0; $i < 16 && [] !== ($pending = iterator_to_array($c->get('messenger.transport.run_control')->get())); ++$i) {
            foreach ($pending as $envelope) {
                $bus->dispatch($envelope->with(new ReceivedStamp('run_control')));
                $c->get('messenger.transport.run_control')->ack($envelope);
            }
        }
        $this->assertNull($c->get(DeferredSubagentBatchRepository::class)->findByLifecycleId($batchId)->terminalCompletionEnqueuedAt, 'A live human wait must keep the parent batch unsettled.');
        $this->assertNotSame([], $registry->requireLoaded($run)->pendingToolCalls);
    }

    public function testControllerRepairPreviewReturnsCorrelatedResponseOnlyAfterOwnerConsumption(): void
    {
        $run = $this->seed();
        $store = self::getContainer()->get(PreparedTransitionEventStoreInterface::class);
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
        $store = self::getContainer()->get(PreparedTransitionEventStoreInterface::class);
        PreparedEventStoreSeeder::append($store, RunEvent::forAppend($run, 1, 'agent_command_applied', ['kind' => 'cancel']));
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
        $handler->expects($this->once())->method('handle')->willReturn(new \Ineersa\AgentCore\Application\Pipeline\HandlerResult(postCommitActions: [new \Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO(new AdvanceRun($run, 0, 'user-advance', 1, 'user-advance'))]));
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
        $this->assertInstanceOf(AdvanceRun::class, $sent[0]->getMessage());
        $this->assertSame(0, $this->afterTurnCount);
        $this->assertSame(['continuation_generation' => $sessions->continuationGeneration($run)], $sessions->findSession($run)->reasoningBaseline);
        $this->assertMaintenanceDidNotScheduleCompaction($run);
    }

    public function testAsyncFifoAttachFinishesCleanupBeforeAlreadyQueuedFollowUp(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('attach FIFO');
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        PreparedEventStoreSeeder::appendMany($events, [
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
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        PreparedEventStoreSeeder::append($store, RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['messages' => []]]));
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
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        // Sequence integrity is valid; execution recovery rejects an invalid human request.
        PreparedEventStoreSeeder::appendMany($store, [
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
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $rawMessage = static fn (string $role, string $text): array => ['role' => $role, 'content' => [['type' => 'text', 'text' => $text]]];
        PreparedEventStoreSeeder::appendMany($store, [
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
        $maintenance = $container->get(\Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler::class);
        $attach = new AttachRun($run, [], 'attach-before-discard');
        $selection = new SelectHistoryPrompt($run, 2, 'selection-before-discard');
        $maintenance->attach($attach);
        $this->assertSame(RuntimeEventTypeEnum::RunHistoryPositionChanged->value, $maintenance->select($selection)->type);
        $processor = $container->get(\Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor::class);
        $processor->process('test', new ApplyCommand($run, 1, 'append-selected', 1, 'append-selected', 'append_message', ['message' => $rawMessage('user', 'NEW_CONTEXT')]));
        $this->assertContains('history_tail_discarded', array_column($store->allFor($run), 'type'));
        // Persist only the cancellation acceptance to reproduce interruption before terminalization.
        PreparedEventStoreSeeder::append($store, RunEvent::forAppend($run, 1, 'agent_command_applied', ['kind' => 'cancel']));
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
        $processor->process('test', new AdvanceRun($run, 1, 'invoke-retained', 1, 'invoke-retained'));
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
        $this->assertInstanceOf(ExecuteLlmStep::class, $request);
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

    #[DataProvider('maintenanceInterruptions')]
    public function testMaintenanceRecoveryFencesTheOriginalActionButAllowsFreshIdentity(string $kind, string $phase, string $boundary): void
    {
        $c = self::getContainer();
        $store = $c->get(PreparedTransitionEventStoreInterface::class);
        $registry = $c->get(ActiveRunContextInterface::class);
        $sessions = $c->get(HatfieldSessionStore::class);
        if ('attach' === $kind) {
            $run = $sessions->createSession('attach recovery');
            PreparedEventStoreSeeder::appendMany($c->get(PreparedTransitionEventStoreInterface::class), [
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
        $fault = $this->createStub(PreparedTransitionEventStoreInterface::class);
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
        $commit = new \Ineersa\AgentCore\Application\Pipeline\RunCommit(
            activeRunContext: $registry,
            eventStore: $fault,
            logger: new \Psr\Log\NullLogger(),
            finalizer: TestTransitionFinalizerFactory::create(
                $fault,
                $c->get(\Ineersa\AgentCore\Application\Handler\StepDispatcher::class),
                batches: $c->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class),
                commands: $c->get(\Ineersa\AgentCore\Contract\CommandStoreInterface::class),
            ),
            actionValidator: $c->get(\Ineersa\AgentCore\Application\Handler\CoordinationActionValidator::class));
        $processor = new \Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor($registry,
            $c->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class), $commit,
            [$c->get(\Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler::class), $c->get(\Ineersa\AgentCore\Application\Pipeline\RefreshRunContextHandler::class)]);
        $history = new \Ineersa\CodingAgent\Session\History\HistorySelectionService($c->get(PreparedTransitionEventStoreInterface::class),
            $c->get(RunStateRebuilderInterface::class), $registry,
            $c->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class), new \Psr\Log\NullLogger(),
            $c->get(\Ineersa\CodingAgent\Session\History\HistoryProjector::class),
            $c->get(\Ineersa\AgentCore\Application\Replay\ReplayEventPreparer::class), $commit);
        $handler = new \Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler($history,
            $c->get(\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface::class), $c->get(InMemoryRuntimeEventSink::class),
            $c->get(\Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink::class), false, new \Psr\Log\NullLogger(),
            $registry, $processor, $sessions, $c->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class),
            $commit, $c->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class),
            $c->get(DeferredSubagentBatchRepository::class));
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
                    $attachBus->dispatch(new Envelope($command, [new ReceivedStamp('run_control')]));
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
            $bus->dispatch(new Envelope($followUp, [new ReceivedStamp('run_control')]));
            $this->assertTrue($c->get(\Ineersa\AgentCore\Contract\CommandStoreInterface::class)->has($run, 'new-input'));
            $attachBus->dispatch(new Envelope($command, [new ReceivedStamp('run_control')]));
            $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
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
            $this->assertCount(2, array_filter($c->get(PreparedTransitionEventStoreInterface::class)->allFor($run), static fn (RunEvent $event): bool => 'context_refreshed' === $event->type));
        } else {
            $this->assertSame(RuntimeEventTypeEnum::CommandAck->value, $handler->select($command)->type);
            $this->assertSame($bytes, file_get_contents($this->archivePath($run)));
            $handler->select(new SelectHistoryPrompt($run, 1, 'fresh-action'));
            $this->assertCount(3, array_filter($c->get(PreparedTransitionEventStoreInterface::class)->allFor($run), static fn (RunEvent $event): bool => 'history_position_set' === $event->type));
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
        $batchStore = $c->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class);
        $call = new ExecuteToolCall(runId: $run, turnNo: 1, stepId: 'tools', attempt: 1, idempotencyKey: 'call-key', toolCallId: 'call', orderIndex: 0, toolName: 'read', args: ['path' => './file']);
        TestToolBatchRegistration::register(
            $collector,
            $batchStore,
            $run,
            1,
            'tools',
            [$call],
            $c->get(\Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface::class),
        );
        \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::suspend($collector, $batchStore, $run, 1, 'tools', 'call', 'old-question');
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
        $tools = $c->get('messenger.transport.tool');
        $tools->reset();
        $bus->dispatch(new Envelope($answer, [new ReceivedStamp('run_control')]));
        $tools->reset();
        \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::suspend($collector, $batchStore, $run, 1, 'tools', 'call', 'new-question');
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
        $bus->dispatch(new Envelope($answer, [new ReceivedStamp('run_control')]));
        $this->assertSame($bytes, file_get_contents($this->archivePath($run)));
        $this->assertSame($before, serialize($batchStore->load($run, 1, 'tools')));
        $this->assertSame('new-question', $registry->requireLoaded($run)->pendingHumanInputRequests[0]->questionId);
        $this->assertSame($sent, $c->get('messenger.transport.tool')->getSent());
    }

    protected function afterKernelBoot(): void
    {
        $container = self::getContainer();
        $compaction = $this->createStub(\Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface::class);
        $compaction->method('prepare')->willReturn(\Ineersa\AgentCore\Contract\Compaction\CompactionPrepareResult::ready(
            messagesToSummarize: [new \Ineersa\AgentCore\Domain\Message\AgentMessage('user', [['type' => 'text', 'text' => 'fresh conversation']])],
            retainedTailMessages: [], tokenEstimateBefore: 12000, messagesCompacted: 1, messagesRetained: 0, firstRetainedIndex: 1, priorSummaryPresent: false,
        ));
        $subscriber = new \Ineersa\CodingAgent\Compaction\AutoCompactionHookSubscriber(
            $container->get(\Ineersa\CodingAgent\Compaction\ProviderContextUsageResolver::class),
            new CompactionConfig(autoEnabled: true, compactAfterTokens: 11000, keepRecentTokens: 10),
            $this->createStub(\Ineersa\AgentCore\Contract\Model\RunModelResolverInterface::class),
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
            $container->get(\Ineersa\AgentCore\Application\Pipeline\RunCommit::class),
            $container->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class),
            $container->get(DeferredSubagentBatchRepository::class),
        ));
    }

    /** @return array{string, string, list<string>} */
    private function seedDeferredRepair(int $children, bool $completedSibling = false, bool $completedOrdinaryTool = false): array
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('deferred repair');
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        PreparedEventStoreSeeder::appendMany($store, [
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
            $readEnvelope = $container->get('messenger.transport.tool')->getSent()[0];
            $read = $this->peekExecutionRequest($readEnvelope);
            $this->assertInstanceOf(ExecuteToolCall::class, $read);
            $this->completeAuthorizedExecution($readEnvelope, new ToolCallResult(
                $run,
                $read->turnNo(),
                $read->stepId(),
                $read->attempt(),
                $read->idempotencyKey(),
                $read->toolCallId,
                $read->orderIndex,
                result: ['content' => [['type' => 'text', 'text' => 'Notes read.']]],
            ));
        }
        $factory = new DeferredSubagentBatchIdentityFactory();
        $batchId = $factory->batchLifecycleId($run, 'fork-current');
        $callEnvelope = $container->get('messenger.transport.tool')->getSent()[$completedOrdinaryTool ? 1 : 0];
        $call = $this->peekExecutionRequest($callEnvelope);
        $registration = $container->get(\Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface::class)->registerPending(new \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation(
            $batchId, $run, $call->turnNo(), $call->stepId(), $call->attempt(), $call->idempotencyKey(), $call->toolCallId, $call->toolName, $call->args, $call->orderIndex,
            $call->toolIdempotencyKey, $call->mode, $call->timeoutSeconds, $call->maxParallelism, null, $call->argSchema, $call->toolsRef,
        ));
        $reference = $callEnvelope->getMessage();
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ExecutionRequest::class, $reference);
        $authorization = $callEnvelope->last(\Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp::class);
        $this->assertNotNull($authorization);
        $operations = $container->get(\Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface::class);
        $claim = $operations->claim($reference, $authorization);
        $this->assertIsString($claim);
        $operations->resolveRequest($reference, $authorization, $claim);
        $operations->transferToDeferred($reference, $authorization, $claim, $registration->deferredId);
        $intents = [];
        $childIds = [];
        for ($i = 0; $i < $children; ++$i) {
            $identity = $factory->childIdentity($run, 'fork-current', $i + 1);
            $childIds[] = $child = $identity['childRunId'];
            $container->get(AgentArtifactRegistry::class)->create($run, $identity['artifactId'], $child, 'fork', AgentArtifactKindEnum::Fork);
            $intents[] = ['batchIndex' => $i + 1, 'childRunId' => $child, 'artifactId' => $identity['artifactId'], 'agentName' => 'fork', 'task' => 'Finish existing work.', 'launchModel' => 'llama_cpp_test/test', 'launchReasoning' => 'medium'];
            PreparedEventStoreSeeder::appendMany($store, [
                RunEvent::forAppend($child, 0, 'run_started', ['payload' => ['metadata' => ['model' => 'llama_cpp_test/test', 'reasoning' => 'medium', 'tools_scope' => ['allowed_tools' => []], 'session' => ['kind' => 'agent_child', 'child_kind' => 'fork', 'parent_run_id' => $run, 'agent_name' => 'fork', 'artifact_id' => $identity['artifactId']]], 'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Finish child work.']]],
                ]]]),
                RunEvent::forAppend($child, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'child-step-'.$i, 'operation_attempt' => 1, 'operation_idempotency_key' => 'child-key-'.$i]),
            ]);
            if ($completedSibling && 1 === $i) {
                PreparedEventStoreSeeder::append($store, RunEvent::forAppend($child, 1, 'agent_end', ['reason' => 'completed']));
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
        PreparedEventStoreSeeder::appendMany($container->get(PreparedTransitionEventStoreInterface::class), [
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
        $tool = $this->peekExecutionRequest($envelopes[0]);
        $this->assertInstanceOf(ExecuteToolCall::class, $tool);
        $this->completeAuthorizedExecution($envelopes[0], new ToolCallResult(
            $run,
            $tool->turnNo(),
            $tool->stepId(),
            1,
            $tool->idempotencyKey(),
            $tool->toolCallId,
            $tool->orderIndex,
            result: ['content' => [['type' => 'text', 'text' => str_repeat('Collected tool output. ', 30)]]],
        ));
        $toolTransport->ack($envelopes[0]);
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\DurableExecutionResult::class, $this->consumeNextOwnerMessage());

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
        $owner = self::getContainer()->get('messenger.transport.run_control');
        $before = array_values(array_filter(
            $owner->getSent(),
            static fn ($envelope): bool => $envelope->getMessage() instanceof CompactRun
                || ($envelope->getMessage() instanceof \Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO
                    && $envelope->getMessage()->message instanceof CompactRun),
        ));
        $this->assertSame(0, $this->afterTurnCount, 'Maintenance must not invoke any after-turn subscriber.');
        $state = self::getContainer()->get(ActiveRunContextInterface::class)->requireLoaded($run);
        // Positive control: the same usage and real subscriber remain eligible
        // on an ordinary commit, rather than being disabled by the fixture.
        self::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\RunCommit::class)->commit($state, $state, [RunEvent::forAppend($run, $state->turnNo, 'llm_step_completed', ['usage' => ['input_tokens' => 12000]])]);
        $this->assertSame(1, $this->afterTurnCount);
        $after = array_values(array_filter(
            $owner->getSent(),
            static fn ($envelope): bool => $envelope->getMessage() instanceof CompactRun
                || ($envelope->getMessage() instanceof \Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO
                    && $envelope->getMessage()->message instanceof CompactRun),
        ));
        $this->assertCount(\count($before) + 1, $after);
        $message = $after[array_key_last($after)]->getMessage();
        if ($message instanceof \Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO) {
            $message = $message->message;
        }
        $this->assertInstanceOf(CompactRun::class, $message);
        $this->assertSame('auto', $message->trigger);
    }

    private function seed(): string
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('maintenance');
        $store = self::getContainer()->get(PreparedTransitionEventStoreInterface::class);
        PreparedEventStoreSeeder::appendMany($store, [
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

    private function peekExecutionRequest(Envelope $envelope): object
    {
        $reference = $envelope->getMessage();
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ExecutionRequest::class, $reference);
        $this->assertNotNull($envelope->last(\Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp::class));
        $request = self::getContainer()->get(\Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface::class)->peekRequest($reference);
        $this->assertIsObject($request);

        return $request;
    }

    private function completeAuthorizedExecution(Envelope $envelope, \Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $result): void
    {
        $reference = $envelope->getMessage();
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ExecutionRequest::class, $reference);
        $authorization = $envelope->last(\Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp::class);
        $this->assertNotNull($authorization);
        $operations = self::getContainer()->get(\Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface::class);
        $claim = $operations->claim($reference, $authorization);
        $this->assertIsString($claim);
        $request = $operations->resolveRequest($reference, $authorization, $claim);
        $this->assertIsObject($request);
        $durable = $operations->saveResult($request, $authorization, $claim, $result);
        $bus = self::getContainer()->get('agent.command.bus');
        $bus->dispatch($bus->dispatch($durable)->with(new ReceivedStamp('run_control')));
    }

    private function resolveExecutionRequest(Envelope $envelope): object
    {
        $reference = $envelope->getMessage();
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ExecutionRequest::class, $reference);
        $authorization = $envelope->last(\Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp::class);
        $this->assertNotNull($authorization);
        $operations = self::getContainer()->get(\Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface::class);
        $claim = $operations->claim($reference, $authorization);
        $this->assertIsString($claim);
        $request = $operations->resolveRequest($reference, $authorization, $claim);
        $this->assertIsObject($request);

        return $request;
    }
}
