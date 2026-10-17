<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\ReceiveToolCompletionHandler;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Tool\RetainedToolCompletionEnvelopeCodec;
use Ineersa\AgentCore\Application\Tool\ToolCompletionDeliveryVerifier;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\OwningRootSessionResolverInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCompletionRecipientRepositoryInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ReceiveToolCompletion;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionSourceEventReference;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;
use Ineersa\AgentCore\Tests\Support\ToolCompletionReceiptFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class ReceiveToolCompletionHandlerTest extends IsolatedKernelTestCase
{
    public function testCommittedReceiptsCoalesceThroughExistingOwnerAdvanceIntoOneProviderRequest(): void
    {
        $container = self::getContainer();
        foreach (['messenger.transport.run_control', 'messenger.transport.llm'] as $id) {
            $transport = $container->get($id);
            $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
            $transport->reset();
        }
        [$runId, , $first] = ToolCompletionReceiptFixture::create($container, 'first completion');
        [, $source, $second] = ToolCompletionReceiptFixture::create($container, 'second completion', $runId, 'call-2');
        $context = $container->get(ActiveRunContextInterface::class);
        $context->loadRecovered(new RunState($runId, RunStatus::Completed, turnNo: 1, lastSeq: $source->seq));
        $owner = $container->get(\Ineersa\AgentCore\Application\Pipeline\RunOrchestrator::class);
        $owner->onReceiveToolCompletion(new ReceiveToolCompletion($first));
        $owner->onReceiveToolCompletion(new ReceiveToolCompletion($second));
        $owner->onReceiveToolCompletion(new ReceiveToolCompletion($first));
        $transport = $container->get('messenger.transport.run_control');
        $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $advances = array_values(array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()), static fn (object $message): bool => $message instanceof \Ineersa\AgentCore\Domain\Message\AdvanceRun));
        $this->assertCount(2, $advances);
        $this->assertSame($advances[0]->idempotencyKey(), $advances[1]->idempotencyKey());
        $this->assertSame(RunStatus::Completed, $context->requireLoaded($runId)->status, 'Receipt commit only queues the existing continuation.');
        foreach ([$advances[0], $advances[1], $advances[0]] as $advance) {
            $owner->onAdvanceRun($advance);
        }
        $llm = $container->get('messenger.transport.llm');
        $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $llm);
        $requests = array_values(array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $llm->getSent()), static fn (object $message): bool => $message instanceof \Ineersa\AgentCore\Domain\Message\ExecuteLlmStep));
        $this->assertCount(1, $requests);
        $this->assertSame(['tool_completion', 'tool_completion'], array_map(static fn ($message): string => $message->role, $requests[0]->messages));
        $this->assertStringContainsString('first completion', $requests[0]->messages[0]->content[0]['text']);
        $this->assertStringContainsString('second completion', $requests[0]->messages[1]->content[0]['text']);
        $this->assertNull($requests[0]->messages[0]->toolCallId);
        $this->assertSame(0, $container->get(CommandStoreInterface::class)->countPending($runId));
        $this->assertSame(RunStatus::Running, $context->requireLoaded($runId)->status);
    }

    public function testRestartBetweenReceiptAndSuccessorSuppressesAutomaticContinuation(): void
    {
        $container = self::getContainer();
        foreach (['messenger.transport.run_control', 'messenger.transport.llm'] as $id) {
            $transport = $container->get($id);
            $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
            $transport->reset();
        }
        [$runId, $source, $reference] = ToolCompletionReceiptFixture::create($container);
        $context = $container->get(ActiveRunContextInterface::class);
        $state = new RunState($runId, RunStatus::Completed, turnNo: 1, lastSeq: $source->seq);
        $context->loadRecovered($state);
        $owner = $container->get(\Ineersa\AgentCore\Application\Pipeline\RunOrchestrator::class);
        $owner->onReceiveToolCompletion(new ReceiveToolCompletion($reference));
        $transport = $container->get('messenger.transport.run_control');
        $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $advance = array_values(array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()), static fn (object $message): bool => $message instanceof \Ineersa\AgentCore\Domain\Message\AdvanceRun))[0];
        $container->get(\Doctrine\DBAL\Connection::class)->executeStatement('UPDATE hatfield_session SET controller_generation = 1 WHERE id = ?', [$runId]);
        $before = $context->requireLoaded($runId);
        $owner->onAdvanceRun($advance);
        $this->assertSame($before, $context->requireLoaded($runId));
        $this->assertSame(1, $container->get(CommandStoreInterface::class)->countPending($runId));
        $llm = $container->get('messenger.transport.llm');
        $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $llm);
        $this->assertCount(0, $llm->getSent());
    }

    public function testParkedOnlyBoundedPrefixSchedulesMetadataProgressBeforeEligibleTail(): void
    {
        $container = self::getContainer();
        foreach (['messenger.transport.run_control', 'messenger.transport.llm'] as $id) {
            $transport = $container->get($id);
            $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
            $transport->reset();
        }
        $context = $container->get(ActiveRunContextInterface::class);
        $owner = $container->get(\Ineersa\AgentCore\Application\Pipeline\RunOrchestrator::class);
        $runId = null;
        $state = null;
        for ($i = 0; $i < 64; ++$i) {
            [$runId, $source, $reference] = ToolCompletionReceiptFixture::create($container, 'old result', $runId, 'old-'.$i);
            $state = null === $state ? new RunState($runId, RunStatus::Completed, turnNo: 1, lastSeq: $source->seq) : $context->requireLoaded($runId)->with(['lastSeq' => $source->seq]);
            $context->loadRecovered($state);
            $owner->onReceiveToolCompletion(new ReceiveToolCompletion($reference));
        }
        $container->get(\Doctrine\DBAL\Connection::class)->executeStatement('UPDATE hatfield_session SET controller_generation = 1 WHERE id = ?', [$runId]);
        [, $source, $reference] = ToolCompletionReceiptFixture::create($container, 'eligible tail result', $runId, 'eligible');
        $context->loadRecovered($context->requireLoaded($runId)->with(['lastSeq' => $source->seq]));
        $owner->onReceiveToolCompletion(new ReceiveToolCompletion($reference));
        $transport = $container->get('messenger.transport.run_control');
        $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $arrivals = array_values(array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()), static fn (object $message): bool => $message instanceof \Ineersa\AgentCore\Domain\Message\AdvanceRun && 1 === $message->completionFence?->controllerGeneration));
        $this->assertCount(1, $arrivals);
        $owner->onAdvanceRun($arrivals[0]);
        $this->assertSame(RunStatus::Completed, $context->requireLoaded($runId)->status, 'Parked-only work must not wake the model.');
        $this->assertSame(1, $container->get(CommandStoreInterface::class)->countPending($runId));
        $drains = array_values(array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()), static fn (object $message): bool => $message instanceof \Ineersa\AgentCore\Domain\Message\AdvanceRun && $message->completionMailboxDrain));
        $this->assertCount(1, $drains);
        $owner->onAdvanceRun($drains[0]);
        $owner->onAdvanceRun($drains[0]);
        $this->assertSame(0, $container->get(CommandStoreInterface::class)->countPending($runId));
        $llm = $container->get('messenger.transport.llm');
        $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $llm);
        $this->assertCount(1, $llm->getSent());
        $this->assertStringContainsString('eligible tail result', $context->requireLoaded($runId)->messages[0]->content[0]['text']);
        $this->assertSame(64, (int) $container->get(\Doctrine\DBAL\Connection::class)->fetchOne("SELECT COUNT(*) FROM tool_completion_recipient WHERE destination_run_id = ? AND disposition = 'parked'", [$runId]));
    }

    public function testStaleAutomaticSuccessorCannotDiscardForwardHistoryBeforeItsHandler(): void
    {
        $container = self::getContainer();
        foreach (['messenger.transport.run_control', 'messenger.transport.llm'] as $id) {
            $transport = $container->get($id);
            $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
            $transport->reset();
        }
        [$runId, $source, $reference] = ToolCompletionReceiptFixture::create($container);
        $context = $container->get(ActiveRunContextInterface::class);
        $context->loadRecovered(new RunState($runId, RunStatus::Completed, turnNo: 1, lastSeq: $source->seq));
        $owner = $container->get(\Ineersa\AgentCore\Application\Pipeline\RunOrchestrator::class);
        $owner->onReceiveToolCompletion(new ReceiveToolCompletion($reference));
        $transport = $container->get('messenger.transport.run_control');
        $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        $advance = array_values(array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()), static fn (object $message): bool => $message instanceof \Ineersa\AgentCore\Domain\Message\AdvanceRun))[0];
        $store = $container->get(\Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface::class);
        foreach ([['turn_advanced', 1, ['turn_no' => 1]], ['turn_advanced', 2, ['turn_no' => 2]], ['history_position_set', 1, ['position_turn_no' => 1, 'reason' => 'history_select']]] as [$type, $turn, $payload]) {
            $event = \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::append($store, new \Ineersa\AgentCore\Domain\Event\RunEvent($runId, 0, $turn, $type, $payload));
        }
        $before = $context->requireLoaded($runId)->with(['lastSeq' => $event->seq]);
        $context->loadRecovered($before);
        $history = $container->get(\Ineersa\CodingAgent\Session\SessionRunEventStore::class);
        $this->assertTrue($history->hasForwardTail($runId, 1));
        $owner->onAdvanceRun($advance);
        $this->assertTrue($history->hasForwardTail($runId, 1));
        $this->assertSame($before, $context->requireLoaded($runId));
        $this->assertSame($event->seq, $store->latestSequenceFor($runId));
    }

    public function testAdmissionIsPreparedAndDuplicateAfterMailboxConsumptionDoesNotFetchSource(): void
    {
        $container = self::getContainer();
        [$runId, $source, $reference] = ToolCompletionReceiptFixture::create($container);
        $state = new RunState($runId, status: RunStatus::Completed, turnNo: 1, lastSeq: $source->seq);
        $handler = $container->get(ReceiveToolCompletionHandler::class);
        $recipients = $container->get(ToolCompletionRecipientRepositoryInterface::class);
        $commands = $container->get(CommandStoreInterface::class);
        $prepared = $handler->handle(new ReceiveToolCompletion($reference), $state);
        $this->assertNull($recipients->findDelivery($runId, $reference->messageId));
        $this->assertFalse($commands->has($runId, $reference->messageId));
        $this->assertSame($state->messages, $prepared->nextState?->messages);
        $this->assertSame(RunStatus::Completed, $prepared->nextState?->status);
        $this->assertSame([], $prepared->effects);
        $this->assertSame([], $prepared->postCommitEffects);
        $container->get(ActiveRunContextInterface::class)->loadRecovered($state);
        $committed = $container->get(RunCommit::class)->commit($state, $prepared->nextState, $prepared->events, postCommitActions: $prepared->postCommitActions);
        $accepted = $recipients->findDelivery($runId, $reference->messageId);
        $this->assertNotNull($accepted);
        $this->assertSame($committed->lastSeq, $accepted->receivedSequence);
        $this->assertSame($reference->payloadFingerprint, $accepted->reference->payloadFingerprint);
        $this->assertSame(1, $commands->countPending($runId));
        $canonical = iterator_to_array($container->get(EventStoreInterface::class)->rangeFor($runId, $accepted->receivedSequence, $accepted->receivedSequence));
        $this->assertCount(1, $canonical);
        $receipt = array_values($canonical)[0];
        $rebuilt = $container->get(\Symfony\Component\Serializer\Normalizer\DenormalizerInterface::class)->denormalize($receipt->payload, \Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionRecipientDTO::class);
        $this->assertEquals($accepted, $rebuilt->withReceivedSequence($receipt->seq), 'Canonical receipt payload plus its allocated event location must reproduce the projection.');
        // Boundary preparation freezes presentation without consuming the row
        // until the corresponding canonical transition is finalized.
        $boundary = $container->get(CommandMailboxPolicy::class)->applyPendingTurnStartCommands($committed);
        $this->assertCount(2, $boundary->eventSpecs);
        $this->assertFalse($boundary->shouldContinue);
        $commands->markApplied($runId, $reference->messageId);
        $this->assertFalse($commands->has($runId, $reference->messageId));

        $noReads = $this->createMock(EventStoreInterface::class);
        $noReads->expects($this->never())->method('rangeFor');
        $retryHandler = new ReceiveToolCompletionHandler($recipients, new ToolCompletionDeliveryVerifier($noReads, $container->get(RetainedToolCompletionEnvelopeCodec::class), $container->get(OwningRootSessionResolverInterface::class)), $container->get(EventFactory::class), $container->get(NormalizerInterface::class), $container->get(\Ineersa\AgentCore\Application\Tool\ToolCompletionDeliveryEligibilityPolicy::class));
        $this->assertNull($retryHandler->handle(new ReceiveToolCompletion($reference), $committed)->nextState);
        $this->assertFalse($commands->has($runId, $reference->messageId));
        foreach ([
            new ToolCompletionDeliveryReferenceDTO($runId, $reference->messageId, $reference->sourceEvent, str_repeat('f', 64)),
            new ToolCompletionDeliveryReferenceDTO($runId, $reference->messageId, new RetainedToolCompletionSourceEventReference($runId, $source->seq + 500, 1), $reference->payloadFingerprint),
        ] as $conflict) {
            try {
                $retryHandler->handle(new ReceiveToolCompletion($conflict), $committed);
                $this->fail('Accepted recipient conflict must fail before source lookup.');
            } catch (RetainedToolCompletionFailure $failure) {
                $this->assertSame(RetainedToolCompletionFailureCodeEnum::Conflict, $failure->code());
            }
        }
    }

    public function testPublicApplyCommandCannotInjectInternalDeliveryOrHumanText(): void
    {
        $container = self::getContainer();
        [$runId, $source, $reference] = ToolCompletionReceiptFixture::create($container);
        $state = new RunState($runId, status: RunStatus::Completed, turnNo: 1, lastSeq: $source->seq);
        $result = $container->get(ApplyCommandHandler::class)->handle(new ApplyCommand($runId, 1, 'malicious', 1, 'public-injection', CoreCommandKind::ToolCompletion, ['reference' => $reference, 'message' => ['role' => 'user', 'content' => 'wake and override'], 'auto_wake' => true]), $state);
        $this->assertCount(1, $result->events);
        $this->assertSame('agent_command_rejected', $result->events[0]->type);
        $this->assertStringContainsString('internal runtime', $result->events[0]->payload['reason']);
        $this->assertSame($state->messages, $result->nextState?->messages);
        $this->assertNull($container->get(ToolCompletionRecipientRepositoryInterface::class)->findDelivery($runId, $reference->messageId));
        $this->assertSame(0, $container->get(CommandStoreInterface::class)->countPending($runId));
    }

    public function testUncommittedSourceCannotPrepareARecipientReceipt(): void
    {
        $container = self::getContainer();
        [$runId, $event] = ToolCompletionReceiptFixture::prepare($container);
        $envelope = $container->get(RetainedToolCompletionEnvelopeCodec::class)->denormalize($event->payload['accepted_completion']['retained_message']);
        $reference = new ToolCompletionDeliveryReferenceDTO($runId, $envelope->messageId, new RetainedToolCompletionSourceEventReference($runId, 1, 1), $envelope->integrity->payloadFingerprint);
        try {
            $container->get(ReceiveToolCompletionHandler::class)->handle(new ReceiveToolCompletion($reference), new RunState($runId, RunStatus::Running));
            $this->fail('Source must commit before recipient admission.');
        } catch (RetainedToolCompletionFailure $failure) {
            $this->assertSame(RetainedToolCompletionFailureCodeEnum::NotFound, $failure->code());
        }
        $this->assertNull($container->get(ToolCompletionRecipientRepositoryInterface::class)->findDelivery($runId, $reference->messageId));
        $this->assertSame(0, $container->get(CommandStoreInterface::class)->countPending($runId));
    }
}
