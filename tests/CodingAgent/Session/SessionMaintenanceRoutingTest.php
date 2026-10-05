<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\Message\AttachRun;
use Ineersa\CodingAgent\Application\Message\RepairSession;
use Ineersa\CodingAgent\Application\Message\SelectHistoryPrompt;
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
        $replay = $container->get(\Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface::class);
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
            new \Ineersa\CodingAgent\Config\CompactionConfig(autoEnabled: true, compactAfterTokens: 11000, keepRecentTokens: 10),
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
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\CompactRun::class, $this->autoCompactionBus->messages[0]);
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
