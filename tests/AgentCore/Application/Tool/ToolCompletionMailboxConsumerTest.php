<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Tool;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Handler\CommandMailboxCoordinationFactory;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\CommandApplicationResult;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Application\Pipeline\ReceiveToolCompletionHandler;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Tool\ToolCompletionDeliveryEligibilityPolicy;
use Ineersa\AgentCore\Application\Tool\ToolCompletionDeliveryVerifier;
use Ineersa\AgentCore\Application\Tool\ToolCompletionMailboxConsumer;
use Ineersa\AgentCore\Application\Tool\ToolCompletionNotificationRenderer;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\RetainedToolCompletionEvidenceReaderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCompletionRecipientRepositoryInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ReceiveToolCompletion;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryDispositionEnum;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Tests\Support\ToolCompletionReceiptFixture;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;

final class ToolCompletionMailboxConsumerTest extends IsolatedKernelTestCase
{
    public function testRealDrainFreezesCustomPresentationAndBindsActualAllocation(): void
    {
        [$state, $reference] = $this->receive();
        $path = self::getContainer()->get(HatfieldSessionStore::class)->resolveSessionsBasePath().'/'.$state->runId.'/events.jsonl';
        file_put_contents(FileRunSequenceAllocator::counterPathForEventsLog($path), "105\n");
        $prepared = self::getContainer()->get(CommandMailboxPolicy::class)->applyPendingTurnStartCommands($state);
        $this->assertSame(1, self::getContainer()->get(CommandStoreInterface::class)->countPending($state->runId));
        $message = $prepared->state->messages[0];
        $this->assertSame('tool_completion', $message->role);
        $this->assertNull($message->toolCallId, 'Metadata correlation must not create a second ordinary tool result.');
        $committed = $this->commit($state, $prepared);
        $recipient = self::getContainer()->get(ToolCompletionRecipientRepositoryInterface::class)->findDelivery($state->runId, $reference->messageId);
        $this->assertSame(ToolCompletionDeliveryDispositionEnum::Applied, $recipient?->disposition);
        $this->assertSame(106, $recipient?->appliedSequence);
        $this->assertSame(0, self::getContainer()->get(CommandStoreInterface::class)->countPending($state->runId));
        $events = iterator_to_array(self::getContainer()->get(EventStoreInterface::class)->rangeFor($state->runId, 106, 106));
        $this->assertSame($message->toArray(), array_values($events)[0]->payload['message']);
        $this->assertSame($message->content, $committed->messages[0]->content);
        $projector = new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector(self::getContainer()->get('event_dispatcher'), new \Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState());
        $projector->reset();
        foreach (self::getContainer()->get(EventStoreInterface::class)->rangeFor($state->runId, 106, 107) as $event) {
            $runtimeEvent = self::getContainer()->get(\Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator::class)->translate($event);
            if (null !== $runtimeEvent) {
                $projector->accept($runtimeEvent);
            }
        }
        $this->assertCount(1, $projector->blocks());
        $this->assertSame(\Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum::System, $projector->blocks()[0]->kind);
        $this->assertSame('tool_completion', $projector->blocks()[0]->meta['kind']);
        $this->assertSame('runtime_tool_completion', $projector->blocks()[0]->meta['source']);
        $this->assertStringContainsString('not a new human request', $projector->blocks()[0]->text);
        $replayed = self::getContainer()->get(\Ineersa\AgentCore\Application\Replay\RunStateReducer::class)->replay(new RunState($state->runId, RunStatus::Queued), self::getContainer()->get(EventStoreInterface::class)->rangeFor($state->runId, 1, $committed->lastSeq));
        $this->assertCount(1, $replayed->messages);
        $this->assertSame($message->toArray(), $replayed->messages[0]->toArray());
        $this->assertSame([], $replayed->pendingToolCalls, 'Retained receipts cannot recreate an ordinary foreground result during replay.');
        $bag = self::getContainer()->get(AgentMessageConverter::class)->toMessageBag([
            new AgentMessage('assistant', [['type' => 'tool_call', 'id' => 'call-1', 'name' => 'bash', 'arguments' => []]]),
            new AgentMessage('tool', [['type' => 'text', 'text' => 'running_in_background']], toolCallId: 'call-1', toolName: 'bash'),
            $message,
        ]);
        $this->assertCount(3, $bag);
        $this->assertInstanceOf(\Symfony\AI\Platform\Message\AssistantMessage::class, $bag->getMessages()[0]);
        $this->assertInstanceOf(\Symfony\AI\Platform\Message\ToolCallMessage::class, $bag->getMessages()[1]);
        $providerText = implode('', array_map(static fn ($part): string => $part->getText(), $bag->getMessages()[2]->getContent()));
        $this->assertStringContainsString('[tool_completion]', $providerText);
        $this->assertStringContainsString('not a new human request', $providerText);
        $this->assertSame('tool_completion', $message->role);
        $this->assertSame([], self::getContainer()->get(CommandMailboxPolicy::class)->applyPendingTurnStartCommands($committed)->eventSpecs);
        $evidence = self::getContainer()->get(RetainedToolCompletionEvidenceReaderInterface::class)->read($state->runId, $state->runId, $reference->messageId, 8192, $reference->sourceEvent->seq);
        $this->assertSame('retained completion body', $evidence['body']['inline']['content'][0]['text']);
    }

    #[DataProvider('blockedStates')]
    public function testBlockedBoundaryDoesNotReadBodiesOrConsumeQuestions(array $changes): void
    {
        [$state] = $this->receive();
        $state = $state->with($changes);
        $result = $this->noBodyPolicy()->applyPendingTurnStartCommands($state);
        $this->assertSame([], $result->eventSpecs);
        $this->assertSame($state->pendingHumanInputRequests, $result->state->pendingHumanInputRequests);
        $this->assertSame(1, self::getContainer()->get(CommandStoreInterface::class)->countPending($state->runId));
    }

    public static function blockedStates(): iterable
    {
        yield 'question' => [['status' => RunStatus::WaitingHuman]];
        yield 'queued question while running' => [['status' => RunStatus::Running, 'pendingHumanInputRequests' => [\Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO::modelTurnFromInterruptPayload(['question_id' => 'question', 'prompt' => 'preserve me'])]]];
        yield 'streaming' => [['isStreaming' => true]];
        yield 'provider operation' => [['currentOperation' => new \Ineersa\AgentCore\Domain\Run\CurrentOperationDTO(1, 'active', 1, 'request')]];
        yield 'tools' => [['pendingToolCalls' => ['unfinished' => false]]];
        yield 'shell' => [['pendingShellToolCalls' => ['shell' => true]]];
        yield 'compaction' => [['status' => RunStatus::Compacting]];
    }

    public function testRestartAndCancellationParkBeforeBodyReadAndDoNotRevive(): void
    {
        foreach ([false, true] as $cancelled) {
            [$state, $reference] = $this->receive();
            if ($cancelled) {
                $state = $state->with(['status' => RunStatus::Cancelled]);
            } else {
                self::getContainer()->get(Connection::class)->executeStatement('UPDATE hatfield_session SET controller_generation = 1 WHERE id = ?', [$state->runId]);
            }
            $prepared = $this->noBodyPolicy()->applyPendingTurnStartCommands($state);
            $committed = $this->commit($state, $prepared);
            $this->assertSame($state->status, $committed->status);
            $delivery = self::getContainer()->get(ToolCompletionRecipientRepositoryInterface::class)->findDelivery($state->runId, $reference->messageId);
            $this->assertSame(ToolCompletionDeliveryDispositionEnum::Parked, $delivery?->disposition);
            $this->assertSame(0, self::getContainer()->get(CommandStoreInterface::class)->countPending($state->runId));
        }
    }

    public function testByteBudgetDrainsFifoWithoutDroppingExcess(): void
    {
        $state = null;
        $expected = [];
        for ($i = 0; $i < 8; ++$i) {
            [$state, $reference] = $this->receive(str_repeat('x', 6000), $state?->runId, 'call-'.$i);
            $expected[] = $reference->messageId;
        }
        $observed = [];
        $rounds = 0;
        while (0 !== self::getContainer()->get(CommandStoreInterface::class)->countPending($state->runId)) {
            $prepared = self::getContainer()->get(CommandMailboxPolicy::class)->applyPendingTurnStartCommands($state);
            $this->assertGreaterThan(0, $prepared->completionBytes);
            $this->assertLessThanOrEqual(16384, $prepared->completionBytes);
            foreach ($prepared->eventSpecs as $spec) {
                if ('agent_command_applied' === $spec['type']) {
                    $observed[] = $spec['payload']['idempotency_key'];
                }
            }
            $state = $this->commit($state, $prepared);
            $this->assertLessThan(9, ++$rounds);
        }
        $this->assertGreaterThan(1, $rounds);
        $this->assertSame($expected, $observed);
    }

    public function testFailedMailboxDeleteRollsBackRecipientThenRecoveryAppliesFrozenDecision(): void
    {
        [$state, $reference] = $this->receive();
        $prepared = self::getContainer()->get(CommandMailboxPolicy::class)->applyPendingTurnStartCommands($state);
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("CREATE TRIGGER completion_delete_failure BEFORE DELETE ON run_command BEGIN SELECT RAISE(ABORT, 'mailbox delete refused'); END");
        try {
            try {
                $this->commit($state, $prepared);
                $this->fail('Metadata failure must propagate.');
            } catch (\Doctrine\DBAL\Exception $exception) {
                $this->assertStringContainsString('mailbox delete refused', $exception->getMessage());
            }
            $this->assertSame(ToolCompletionDeliveryDispositionEnum::Pending, self::getContainer()->get(ToolCompletionRecipientRepositoryInterface::class)->findDelivery($state->runId, $reference->messageId)?->disposition);
            $this->assertSame(1, self::getContainer()->get(CommandStoreInterface::class)->countPending($state->runId));
        } finally {
            $connection->executeStatement('DROP TRIGGER completion_delete_failure');
        }
        self::getContainer()->get(RunLockManager::class)->synchronized($state->runId, static fn () => self::getContainer()->get(PendingTransitionRecovery::class)->recover($state->runId));
        $this->assertSame(ToolCompletionDeliveryDispositionEnum::Applied, self::getContainer()->get(ToolCompletionRecipientRepositoryInterface::class)->findDelivery($state->runId, $reference->messageId)?->disposition);
        $this->assertSame(0, self::getContainer()->get(CommandStoreInterface::class)->countPending($state->runId));
    }

    public function testAcceptedProviderStopClosesStreamingOperationBeforeActualDrain(): void
    {
        [$state] = $this->receive();
        $state = $state->with(['status' => RunStatus::Running, 'isStreaming' => true, 'activeStepId' => 'request', 'currentOperation' => new \Ineersa\AgentCore\Domain\Run\CurrentOperationDTO(1, 'request', 1, 'request-key')]);
        $arbitrary = self::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler::class)->handle(new \Ineersa\AgentCore\Domain\Message\AdvanceRun($state->runId, 1, 'arbitrary', 1, 'arbitrary'), $state);
        $this->assertNull($arbitrary->nextState);
        $result = self::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\LlmStepResultHandler::class)->handle(new \Ineersa\AgentCore\Domain\Message\LlmStepResult($state->runId, 1, 'request', 1, 'request-key', assistantMessage: new \Symfony\AI\Platform\Message\AssistantMessage(new \Symfony\AI\Platform\Message\Content\Text('done')), stopReason: 'stop'), $state);
        $this->assertSame('tool_completion', $result->nextState?->messages[1]->role);
        $this->assertFalse($result->nextState?->isStreaming);
        $this->assertNull($result->nextState?->currentOperation);
        $this->assertCount(1, array_filter($result->postCommitActions, static fn (object $action): bool => $action instanceof \Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO));
        $advance = array_values(array_filter($result->postCommitActions, static fn (object $action): bool => $action instanceof \Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO))[0]->message;
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\AdvanceRun::class, $advance);
        $this->assertNotNull($advance->appliedCompletion);
        $this->assertNotNull($advance->completionFence);
        $handler = self::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler::class);
        $this->assertNull($handler->handle($advance, $result->nextState)->nextState, 'An unfinalized application is not successor permission.');
        self::getContainer()->get(ActiveRunContextInterface::class)->loadRecovered($state);
        $committed = self::getContainer()->get(RunCommit::class)->commit($state, $result->nextState, $result->events, postCommitActions: $result->postCommitActions);
        $this->assertCount(1, $handler->handle($advance, $committed)->effects, 'The finalized applied reference permits exactly the ordinary successor.');
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE hatfield_session SET controller_generation = 1 WHERE id = ?', [$state->runId]);
        $this->assertNull($handler->handle($advance, $committed)->nextState, 'A restart suppresses even a completion already applied at provider stop.');
    }

    public function testEveryStaleFenceIsParkedBeforeAnyBodyRead(): void
    {
        foreach ([new \Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO(1, 0, 0), new \Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO(0, 1, 0), new \Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO(0, 0, 1)] as $fence) {
            [$state, $reference] = $this->receive();
            $prepared = $this->noBodyPolicy($fence)->applyPendingTurnStartCommands($state);
            $this->commit($state, $prepared);
            $this->assertSame(ToolCompletionDeliveryDispositionEnum::Parked, self::getContainer()->get(ToolCompletionRecipientRepositoryInterface::class)->findDelivery($state->runId, $reference->messageId)?->disposition);
        }
    }

    /** @return array{RunState, \Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO} */
    private function receive(string $text = 'retained completion body', ?string $runId = null, string $callId = 'call-1'): array
    {
        $container = self::getContainer();
        [$runId, $source, $reference] = ToolCompletionReceiptFixture::create($container, $text, $runId, $callId);
        $state = new RunState($runId, RunStatus::Completed, turnNo: 1, lastSeq: $source->seq);
        $container->get(ActiveRunContextInterface::class)->loadRecovered($state);
        $result = $container->get(ReceiveToolCompletionHandler::class)->handle(new ReceiveToolCompletion($reference), $state);

        return [$container->get(RunCommit::class)->commit($state, $result->nextState, $result->events, postCommitActions: $result->postCommitActions), $reference];
    }

    private function commit(RunState $state, CommandApplicationResult $prepared): RunState
    {
        $events = self::getContainer()->get(EventFactory::class)->eventsFromSpecs($state->runId, $state->turnNo, $state->lastSeq + 1, $prepared->eventSpecs);
        $result = CommandMailboxCoordinationFactory::finalize(new HandlerResult($prepared->state, $events, postCommitActions: $prepared->postCommitActions));
        self::getContainer()->get(ActiveRunContextInterface::class)->loadRecovered($state);

        return self::getContainer()->get(RunCommit::class)->commit($state, $prepared->state, $events, postCommitActions: $result->postCommitActions);
    }

    private function noBodyPolicy(?\Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO $fence = null): CommandMailboxPolicy
    {
        $container = self::getContainer();
        $reader = $this->createMock(RetainedToolCompletionEvidenceReaderInterface::class);
        $reader->expects($this->never())->method('read');
        $renderer = new ToolCompletionNotificationRenderer($container->get(ToolCompletionDeliveryVerifier::class), $reader, $container->get(SerializerInterface::class));

        $eligibility = $container->get(ToolCompletionDeliveryEligibilityPolicy::class);
        if (null !== $fence) {
            $provider = $this->createMock(\Ineersa\AgentCore\Contract\Tool\ToolCompletionDeliveryFenceProviderInterface::class);
            $provider->expects($this->once())->method('capture')->willReturn($fence);
            $eligibility = new ToolCompletionDeliveryEligibilityPolicy($provider);
        }

        return new CommandMailboxPolicy($container->get(CommandStoreInterface::class), $container->get(CommandRouter::class), new ToolCompletionMailboxConsumer($container->get(ToolCompletionRecipientRepositoryInterface::class), $eligibility, $renderer, $container->get(NormalizerInterface::class)), $eligibility);
    }
}
