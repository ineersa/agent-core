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
    public function testOriginalDetachmentThroughTrueTerminalPublishesAllocatedSourceAndOneIdleSuccessor(): void
    {
        $container = self::getContainer();
        $this->resetTransports();
        $sessions = $container->get(\Ineersa\CodingAgent\Session\HatfieldSessionStore::class);
        $runId = $sessions->createSession('detachment-to-provider');
        $store = $container->get(\Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface::class);
        $call = new \Ineersa\AgentCore\Domain\Message\ExecuteToolCall($runId, 1, 'tools', 1, 'native-key', 'call-native', 'bash', ['command' => 'sleep 30'], 0, mode: 'sequential');
        foreach ([
            ['run_started', ['payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Run the command.']]]]]]],
            ['turn_advanced', ['turn_no' => 1, 'step_id' => 'tools']],
            ['llm_step_completed', ['step_id' => 'tools', 'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'tool_call', 'tool_call_index' => 0]], 'tool_calls' => [['id' => 'call-native', 'name' => 'bash', 'arguments' => $call->args]]]]],
            ['tool_execution_start', ['tool_call_id' => 'call-native', 'tool_name' => 'bash', 'order_index' => 0]],
        ] as [$type, $payload]) {
            \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::append($store, new \Ineersa\AgentCore\Domain\Event\RunEvent($runId, 0, 1, $type, $payload));
        }
        $reducer = $container->get(\Ineersa\AgentCore\Application\Replay\RunStateReducer::class);
        $state = $reducer->replay(RunState::queued($runId), $store->rangeFor($runId, 1, $store->latestSequenceFor($runId)));
        $this->assertSame(['call-native' => false], $state->pendingToolCalls);
        $context = $container->get(ActiveRunContextInterface::class);
        $context->loadRecovered($state);
        $collector = $container->get(\Ineersa\AgentCore\Application\Handler\ToolBatchCollector::class);
        $commit = $container->get(RunCommit::class);
        $locks = $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class);
        $locks->synchronized($runId, static fn () => $commit->commit($state, $state, [], dispatchAfterTurnHooks: false, postCommitActions: [$collector->prepareRegistration($runId, 1, 'tools', [$call])]));
        $native = new \Ineersa\AgentCore\Domain\Tool\ToolInvocationOwnerReference('bash', 'native-record', 4242, '/tmp/native-proof.log');
        $repo = $container->get(\Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface::class);
        $correlation = $repo->registerPending(new \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation('550e8400-e29b-41d4-a716-446655440799', $runId, 1, 'tools', 1, 'native-key', 'call-native', 'bash', $call->args, 0, mode: 'sequential', invocationId: 'native-invocation', controllerRunId: $runId, executionOwnerKind: 'bash', executionOwnerReference: $native, originalExecutionMode: 'sequential'));
        $ack = $collector->prepareBackgroundAcknowledgement(new \Ineersa\AgentCore\Domain\Tool\ToolInvocationBackgroundReadiness($runId, 1, 'tools', 'call-native', 'bash', $native, $correlation->invocationId, $runId, $correlation->envelopeFingerprint));
        $this->assertTrue($ack->accepted);
        $this->assertTrue($ack->complete);
        $this->assertNotNull($ack->acknowledgementResult);
        $codec = $container->get(\Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec::class);
        $ackEvents = $container->get(EventFactory::class)->eventsFromSpecs($runId, 1, $state->lastSeq + 1, [
            ['type' => 'tool_execution_end', 'payload' => $codec->toEventPayload($ack->acknowledgementResult)],
            ['type' => 'tool_batch_committed', 'payload' => ['count' => 1]],
        ]);
        $afterAck = $reducer->replay($state, [...$store->rangeFor($runId, 1, $state->lastSeq), ...$ackEvents]);
        $locks->synchronized($runId, static fn () => $commit->commit($state, $afterAck, $ackEvents, postCommitActions: $ack->localActions));
        $this->assertSame('pending', $repo->status($correlation->deferredId), 'Original wait resolution cannot finish native execution.');
        $this->assertSame(\Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $repo->findByDeferredId($correlation->deferredId)?->callerDisposition);
        $owner = $container->get(\Ineersa\AgentCore\Application\Pipeline\RunOrchestrator::class);
        $owner->onAdvanceRun(new \Ineersa\AgentCore\Domain\Message\AdvanceRun($runId, 1, 'after-ack', 1, 'after-ack'));
        $beforeCompletion = $this->transportMessages('llm', \Ineersa\AgentCore\Domain\Message\ExecuteLlmStep::class);
        $this->assertCount(1, $beforeCompletion);
        $request = $beforeCompletion[0];
        $owner->onLlmStepResult(new \Ineersa\AgentCore\Domain\Message\LlmStepResult($runId, $request->turnNo(), $request->stepId(), $request->attempt(), $request->idempotencyKey(), assistantMessage: new \Symfony\AI\Platform\Message\AssistantMessage(new \Symfony\AI\Platform\Message\Content\Text('The command continues in the background.')), stopReason: 'stop'));
        $this->assertSame(RunStatus::Completed, $context->requireLoaded($runId)->status);

        $path = $sessions->resolveSessionsBasePath().'/'.$runId.'/events.jsonl';
        file_put_contents(\Ineersa\CodingAgent\Session\FileRunSequenceAllocator::counterPathForEventsLog($path), "105\n");
        $body = str_repeat('durable native result; ', 450);
        $terminal = new \Ineersa\AgentCore\Domain\Message\ToolCallResult($runId, 1, 'tools', 1, 'native-key', 'call-native', 0, ['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => $body]]]);
        $owner->onToolCallResult($terminal);
        $this->assertSame('completed', $repo->status($correlation->deferredId));
        $published = $this->transportMessages('run_control', ReceiveToolCompletion::class);
        $this->assertCount(1, $published);
        $delivery = $published[0];
        $this->assertSame(106, $delivery->reference->sourceEvent->seq);
        $source = array_values(iterator_to_array($store->rangeFor($runId, 106, 106)))[0];
        $this->assertSame(106, $source->payload['accepted_completion']['retained_message']['origin']['accepted_source_event_reference']['seq']);
        $this->assertNull($store->verifiedPendingTransition($runId), 'Publication follows verified finalization, not native send.');
        $this->assertNull($container->get(ToolCompletionRecipientRepositoryInterface::class)->findDelivery($runId, $delivery->reference->messageId));
        $owner->onReceiveToolCompletion($delivery);
        $owner->onReceiveToolCompletion($delivery);
        $advances = $this->transportMessages('run_control', \Ineersa\AgentCore\Domain\Message\AdvanceRun::class);
        $this->assertCount(1, $advances);
        $this->assertSame(RunStatus::Completed, $context->requireLoaded($runId)->status);
        $owner->onAdvanceRun($advances[0]);
        $owner->onAdvanceRun($advances[0]);
        $owner->onToolCallResult($terminal);
        $requests = $this->transportMessages('llm', \Ineersa\AgentCore\Domain\Message\ExecuteLlmStep::class);
        $this->assertCount(2, $requests, 'One post-ack request and exactly one idle-root completion successor.');
        $messages = $requests[1]->messages;
        $tools = array_values(array_filter($messages, static fn ($message): bool => 'tool' === $message->role));
        $custom = array_values(array_filter($messages, static fn ($message): bool => 'tool_completion' === $message->role));
        $this->assertCount(1, $tools);
        $this->assertSame('call-native', $tools[0]->toolCallId);
        $this->assertStringContainsString('running_in_background', $tools[0]->content[0]['text']);
        $this->assertCount(1, $custom);
        $this->assertNull($custom[0]->toolCallId);
        $this->assertSame('runtime_tool_completion', $custom[0]->details['model_notifications'][0]['source']);
        $this->assertStringContainsString($body, $custom[0]->content[0]['text']);
        $container->get(\Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator::class)->validate($messages);
        $this->assertSame(0, $container->get(CommandStoreInterface::class)->countPending($runId));
        $evidence = $container->get(\Ineersa\AgentCore\Contract\Tool\RetainedToolCompletionEvidenceReaderInterface::class)->read($runId, $runId, $delivery->reference->messageId, 16384, 106);
        $this->assertSame('owned_sealed', $evidence['body']['kind']);
        $this->assertSame($body, $evidence['body']['json']['content'][0]['text']);
        $recipient = $container->get(ToolCompletionRecipientRepositoryInterface::class)->findDelivery($runId, $delivery->reference->messageId);
        $this->assertSame(\Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryDispositionEnum::Applied, $recipient?->disposition);
        $this->assertNotNull($recipient?->appliedSequence);
        $appliedEvent = array_values(iterator_to_array($store->rangeFor($runId, $recipient->appliedSequence, $recipient->appliedSequence)))[0];
        $this->assertSame($custom[0]->toArray(), $appliedEvent->payload['message'], 'Provider context uses the canonically frozen presentation.');
        $this->assertCount(1, $this->transportMessages('run_control', ReceiveToolCompletion::class), 'Native terminal redelivery cannot publish a second completion.');
        $replayed = $reducer->replay(RunState::queued($runId), $store->rangeFor($runId, 1, $context->requireLoaded($runId)->lastSeq));
        $this->assertSame(array_map(static fn ($message): array => $message->toArray(), $messages), array_map(static fn ($message): array => $message->toArray(), $replayed->messages));
        $this->assertSame([], $replayed->pendingToolCalls);
    }

    public function testAcceptedOwnerCancellationKeepsQueuedCompletionStaleAfterExplicitContinuation(): void
    {
        $container = self::getContainer();
        $this->resetTransports();
        [$runId, $source, $reference] = ToolCompletionReceiptFixture::create($container);
        $context = $container->get(ActiveRunContextInterface::class);
        $context->loadRecovered(new RunState($runId, RunStatus::Completed, turnNo: 1, lastSeq: $source->seq));
        $owner = $container->get(\Ineersa\AgentCore\Application\Pipeline\RunOrchestrator::class);

        // Cancel is deliberately rejected for Completed runs. Start genuine
        // user-authorized work, then accept the receipt while its model runs.
        $owner->onApplyCommand(new ApplyCommand($runId, 1, 'user-start', 1, 'user-start', CoreCommandKind::FollowUp, ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Start authorized work.']]]]));
        $owner->onAdvanceRun($this->transportMessages('run_control', \Ineersa\AgentCore\Domain\Message\AdvanceRun::class)[0]);
        $requests = $this->transportMessages('llm', \Ineersa\AgentCore\Domain\Message\ExecuteLlmStep::class);
        $this->assertCount(1, $requests);
        $owner->onReceiveToolCompletion(new ReceiveToolCompletion($reference));
        $request = $requests[0];
        $owner->onLlmStepResult(new \Ineersa\AgentCore\Domain\Message\LlmStepResult($runId, $request->turnNo(), $request->stepId(), $request->attempt(), $request->idempotencyKey(), assistantMessage: new \Symfony\AI\Platform\Message\AssistantMessage(new \Symfony\AI\Platform\Message\Content\Text('Stopped at a safe boundary.')), stopReason: 'stop'));
        $automatic = array_values(array_filter($this->transportMessages('run_control', \Ineersa\AgentCore\Domain\Message\AdvanceRun::class), static fn ($advance): bool => null !== $advance->completionFence));
        $this->assertCount(1, $automatic);
        $this->assertSame(RunStatus::Running, $context->requireLoaded($runId)->status);
        $this->assertNull($context->requireLoaded($runId)->currentOperation);

        // Receipt application has queued, but has not executed, its successor.
        $owner->onApplyCommand(new ApplyCommand($runId, $context->requireLoaded($runId)->turnNo, 'cancel', 1, 'accepted-cancel', CoreCommandKind::Cancel));
        $this->assertSame(RunStatus::Cancelled, $context->requireLoaded($runId)->status);
        $this->assertGreaterThan($reference->deliveryFence?->wakeGeneration, $container->get(\Ineersa\AgentCore\Contract\Tool\ToolCompletionDeliveryFenceProviderInterface::class)->capture($runId)->wakeGeneration);
        $this->assertSame(0, $reference->deliveryFence?->wakeGeneration);
        $owner->onAdvanceRun($automatic[0]);
        $this->assertCount(1, $this->transportMessages('llm', \Ineersa\AgentCore\Domain\Message\ExecuteLlmStep::class));

        $owner->onApplyCommand(new ApplyCommand($runId, $context->requireLoaded($runId)->turnNo, 'user-resume', 1, 'user-resume', CoreCommandKind::FollowUp, ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Explicitly continue after cancellation.']]]]));
        $advances = $this->transportMessages('run_control', \Ineersa\AgentCore\Domain\Message\AdvanceRun::class);
        $owner->onAdvanceRun($advances[array_key_last($advances)]);
        $resumed = $context->requireLoaded($runId);
        $this->assertSame(RunStatus::Running, $resumed->status);
        $this->assertCount(2, $this->transportMessages('llm', \Ineersa\AgentCore\Domain\Message\ExecuteLlmStep::class), 'Both requests were explicitly user-authorized; none came from the completion successor.');
        $this->assertSame('stale_cancellation_generation', $container->get(\Ineersa\AgentCore\Application\Tool\ToolCompletionDeliveryEligibilityPolicy::class)->ineligibleReason($resumed, $reference->deliveryFence));
        $owner->onAdvanceRun($automatic[0]);
        $owner->onReceiveToolCompletion(new ReceiveToolCompletion($reference));
        $this->assertSame($resumed, $context->requireLoaded($runId));
        $this->assertSame($resumed->lastSeq, $container->get(\Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface::class)->latestSequenceFor($runId), 'Stale wake cannot append or discard canonical history.');
        $this->assertCount(2, $this->transportMessages('llm', \Ineersa\AgentCore\Domain\Message\ExecuteLlmStep::class));
        $events = iterator_to_array($container->get(EventStoreInterface::class)->rangeFor($runId, 1, $resumed->lastSeq));
        $this->assertCount(1, array_filter($events, static fn ($event): bool => 'agent_command_applied' === $event->type && 'cancel' === ($event->payload['kind'] ?? null)));
        $this->assertCount(0, array_filter($events, static fn ($event): bool => 'agent_command_rejected' === $event->type));
        $evidence = $container->get(\Ineersa\AgentCore\Contract\Tool\RetainedToolCompletionEvidenceReaderInterface::class)->read($runId, $runId, $reference->messageId, 8192, $source->seq);
        $this->assertSame('retained completion body', $evidence['body']['inline']['content'][0]['text']);
    }

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

    private function resetTransports(): void
    {
        foreach (['run_control', 'llm'] as $name) {
            $transport = self::getContainer()->get('messenger.transport.'.$name);
            $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
            $transport->reset();
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function transportMessages(string $name, string $class): array
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        $this->assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);

        return array_values(array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()), static fn (object $message): bool => $message instanceof $class));
    }
}
