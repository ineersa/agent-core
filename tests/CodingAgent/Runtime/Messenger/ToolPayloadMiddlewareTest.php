<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DTO\DeferredToolCompletionMetadataDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolBatchScheduleDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeExecutionService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Messenger\SubagentExecuteToolCallRoutingMiddleware;
use Ineersa\CodingAgent\Application\Pipeline\ChildInvocationLifecycleService;
use Ineersa\CodingAgent\Application\Pipeline\CodeModeLifecycleService;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChild;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Runtime\Messenger\ToolPayloadMiddleware;
use Ineersa\CodingAgent\Session\CanonicalToolInvocationReader;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\ToolPayloadAdmission;
use Ineersa\CodingAgent\Tests\Runtime\Messenger\Support\ToolPayloadWorkerTransport;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\ValidationFailedException as MessageValidationFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\AckStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validation;

final class ToolPayloadMiddlewareTest extends IsolatedKernelTestCase
{
    #[DataProvider('ackModes')]
    public function testNativeWorkerReleasesExactReferenceIdentityAndPreservesHandlerOutcome(bool $explicitAck): void
    {
        $transport = new ToolPayloadWorkerTransport(new PhpSerializer());
        $transport->send(new Envelope(new ToolPayloadMessage($this->reference('call'))));
        $transport->send(new Envelope(new ToolPayloadMessage($this->reference('call'))));
        $payloads = $this->createMock(ToolPayloadStoreInterface::class);
        $payloads->expects($this->exactly(2))->method('read')->willReturn($this->call());
        $middleware = $this->middleware($payloads);
        $ack = new class($explicitAck) implements MiddlewareInterface {
            public function __construct(private bool $explicitAck)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $handled = $stack->next()->handle($envelope, $stack);
                if ($this->explicitAck) {
                    $handled->last(AckStamp::class)->ack($handled);
                }

                return $handled;
            }
        };
        $bus = new MessageBus([$middleware, $ack, new HandleMessageMiddleware(new HandlersLocator([
            ExecuteToolCall::class => [static fn (ExecuteToolCall $call): string => $call->args['large']],
        ]))]);
        $events = new EventDispatcher();
        $worker = new Worker(['tool' => $transport], $bus, $events);
        $handled = 0;
        $events->addListener(WorkerMessageHandledEvent::class, static function () use ($worker, &$handled): void {
            if (2 === ++$handled) {
                $worker->stop();
            }
        });
        $worker->run(['sleep' => 0]);
        $worker->keepalive(5);
        $this->assertCount(2, $transport->acked);
        foreach ($transport->acked as $index => $envelope) {
            $this->assertSame($transport->received[$index]->getMessage(), $envelope->getMessage());
            $this->assertSame($this->call()->args['large'], $envelope->last(HandledStamp::class)->getResult());
        }
        $this->assertSame([], $transport->keepaliveRequests, 'Acknowledged references must leave native SplObjectStorage.');
    }

    /** @return iterable<array{bool}> */
    public static function ackModes(): iterable
    {
        yield 'returned envelope' => [false];
        yield 'AckStamp callback' => [true];
    }

    #[DataProvider('failureModes')]
    public function testNativeRetryKeepsReferenceAndExceptionPolicy(bool $explicitAck, bool $recoverable): void
    {
        $serializer = new PhpSerializer();
        $transport = new ToolPayloadWorkerTransport($serializer);
        $transport->send(new Envelope(new ToolPayloadMessage($this->reference('call'))));
        $payloads = $this->createStub(ToolPayloadStoreInterface::class);
        $payloads->method('read')->willReturn($this->call());
        $failure = $recoverable ? new RecoverableMessageHandlingException('retry', retryDelay: 37)
            : new UnrecoverableMessageHandlingException('stop');
        $downstream = new class($explicitAck, $failure) implements MiddlewareInterface {
            public function __construct(private bool $explicitAck, private \Throwable $failure)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $exception = new HandlerFailedException($envelope, ['tool-handler' => $this->failure]);
                if ($this->explicitAck) {
                    $envelope->last(AckStamp::class)->ack($envelope, $exception);

                    return $envelope;
                }
                throw $exception;
            }
        };
        $bus = new MessageBus([$this->middleware($payloads), $downstream]);
        $events = new EventDispatcher();
        $events->addSubscriber(new SendFailedMessageForRetryListener(
            new ServiceLocator(['tool' => static fn () => $transport]),
            new ServiceLocator(['tool' => static fn () => new MultiplierRetryStrategy(0)]),
        ));
        $worker = new Worker(['tool' => $transport], $bus, $events);
        $events->addListener(WorkerMessageFailedEvent::class, static function (WorkerMessageFailedEvent $event) use ($worker, $failure): void {
            self::assertInstanceOf(HandlerFailedException::class, $event->getThrowable());
            self::assertSame(['tool-handler' => $failure], $event->getThrowable()->getWrappedExceptions());
            $worker->stop();
        }, -100);
        $worker->run(['sleep' => 0]);
        $worker->keepalive(5);
        // Symfony Messenger 8.2 ACKs envelopes that will retry and rejects only
        // terminal failures. Keepalive must still release either path.
        if ($recoverable) {
            $this->assertSame([], $transport->rejected);
            $this->assertSame($transport->received[0]->getMessage(), $transport->acked[0]->getMessage());
        } else {
            $this->assertSame($transport->received[0]->getMessage(), $transport->rejected[0]->getMessage());
            $this->assertSame([], $transport->acked);
        }
        $this->assertSame([], $transport->keepaliveRequests);
        $this->assertCount($recoverable ? 2 : 1, $transport->getSent());
        if ($recoverable) {
            $retry = $transport->getSent()[1];
            $this->assertInstanceOf(ToolPayloadMessage::class, $retry->getMessage());
            $this->assertSame(37, $retry->last(DelayStamp::class)->getDelay());
            $this->assertSame(1, $retry->last(RedeliveryStamp::class)->getRetryCount());
            $this->assertLessThan(8192, \strlen($serializer->encode($retry)['body']));
            $accepted = new MessageBus([$this->middleware($payloads), new HandleMessageMiddleware(new HandlersLocator([
                ExecuteToolCall::class => [static fn (): string => 'retried'],
            ]))]);
            $this->assertSame($retry->getMessage(), $accepted->dispatch($retry->with(new ReceivedStamp('tool')))->getMessage());
        }
    }

    /** @return iterable<array{bool, bool}> */
    public static function failureModes(): iterable
    {
        yield 'thrown recoverable' => [false, true];
        yield 'callback recoverable' => [true, true];
        yield 'thrown unrecoverable' => [false, false];
        yield 'callback unrecoverable' => [true, false];
    }

    #[DataProvider('envelopeFailures')]
    public function testOtherNativeEnvelopeExceptionsRestoreReference(string $kind): void
    {
        $payloads = $this->createStub(ToolPayloadStoreInterface::class);
        $payloads->method('read')->willReturn($this->call());
        $original = new ToolPayloadMessage($this->reference('call'));
        $downstream = new class($kind) implements MiddlewareInterface {
            public function __construct(private string $kind)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $envelope = $envelope->with(new HandledStamp('retained', 'earlier-handler'));
                throw 'delayed' === $this->kind ? new DelayedMessageHandlingException([new \LogicException('delayed')], $envelope) : new MessageValidationFailedException($envelope->getMessage(), new ConstraintViolationList(), $envelope);
            }
        };
        try {
            new MessageBus([$this->middleware($payloads), $downstream])->dispatch(new Envelope($original, [new ReceivedStamp('tool')]));
            $this->fail('Expected envelope-aware failure.');
        } catch (DelayedMessageHandlingException|MessageValidationFailedException $exception) {
            $this->assertSame($original, $exception->getEnvelope()->getMessage());
            $this->assertSame('retained', $exception->getEnvelope()->last(HandledStamp::class)->getResult());
        }
    }

    /** @return iterable<array{string}> */
    public static function envelopeFailures(): iterable
    {
        yield ['delayed'];
        yield ['validation'];
    }

    public function testObsoleteDeferredReceiverSettlesWithoutReadingAnyBodiesAndReleasesChild(): void
    {
        $payloads = $this->createMock(ToolPayloadStoreInterface::class);
        $payloads->expects($this->never())->method('read');
        $deferred = $this->createMock(DeferredToolCompletionRepositoryInterface::class);
        $deferred->method('findMetadataByDeferredId')->willReturn(new DeferredToolCompletionMetadataDTO('deferred', $this->reference('correlation'), 'pending'));
        $deferred->expects($this->never())->method('findByDeferredId');
        $deferred->expects($this->exactly(2))->method('markCompleted')->with('deferred');
        $batches = $this->createMock(ToolBatchStoreInterface::class);
        $batches->expects($this->exactly(2))->method('delete')->with('run', 1, 'step');
        $registry = $this->createMock(ActiveRunContextInterface::class);
        $registry->method('requireLoaded')->willReturn(new RunState('run', RunStatus::Cancelled, turnNo: 1, parentRunId: 'parent'));
        $registry->expects($this->exactly(2))->method('release')->with('run');
        $bus = new MessageBus([$this->middleware($payloads, $deferred, $batches, $registry)]);
        $message = new ToolPayloadMessage($this->reference('result'), 'deferred');
        for ($i = 0; $i < 2; ++$i) {
            $this->assertSame($message, $bus->dispatch(new Envelope($message, [new ReceivedStamp('run_control')]))->getMessage());
        }
    }

    public function testObsoleteDeferredProducerSettlesBeforeCorrelationOrResultPublication(): void
    {
        $payloads = $this->createMock(ToolPayloadStoreInterface::class);
        $payloads->expects($this->never())->method('read');
        $payloads->expects($this->never())->method('publish');
        $deferred = $this->createMock(DeferredToolCompletionRepositoryInterface::class);
        $deferred->method('findMetadataByDeferredId')->willReturn(new DeferredToolCompletionMetadataDTO('deferred', $this->reference('correlation'), 'pending'));
        $deferred->expects($this->never())->method('findByDeferredId');
        $deferred->expects($this->once())->method('markCompleted')->with('deferred');
        $batches = $this->createMock(ToolBatchStoreInterface::class);
        $batches->expects($this->once())->method('delete')->with('run', 1, 'step');
        $replay = $this->createMock(RunStateRebuilderInterface::class);
        $replay->expects($this->never())->method('rebuildIfStale');
        $registry = new \Ineersa\AgentCore\Tests\Support\TestActiveRunContext();
        $registry->loadRecovered(new RunState('run', RunStatus::Cancelled, turnNo: 1, lastSeq: 7));
        $result = new MessageBus([$this->middleware($payloads, $deferred, $batches, registry: $registry, replay: $replay)])->dispatch(new CompleteDeferredToolCall('deferred', [['type' => 'text', 'text' => 'discard']]));
        $this->assertNotNull($result->last(HandledStamp::class));
    }

    public function testProducerPreparesCodeModeBeforeSealingExecutionAndReusesIdentity(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('owner prepared code_mode');
        $code = new ExecuteToolCall($run, 1, 'step', 1, 'key', 'code-call', 'code_mode', ['script' => 'return 1;'], 0, mode: 'parallel', maxParallelism: 2);
        $other = new ExecuteToolCall($run, 1, 'step', 1, 'other-key', 'echo-call', 'echo', ['text' => 'hi'], 1, mode: 'parallel', maxParallelism: 2);
        $batches = $container->get(ToolBatchStoreInterface::class);
        $admitted = TestToolBatchRegistration::register(
            new ToolBatchCollector($batches),
            $batches,
            $run,
            1,
            'step',
            [$code, $other],
        );
        $this->assertCount(2, $admitted);

        $deferred = $container->get(DeferredToolCompletionRepository::class);
        $this->assertNull($deferred->findMetadataByRunAndToolCall($run, 'code-call'));
        $this->assertNull($deferred->findMetadataByRunAndToolCall($run, 'echo-call'));

        $published = [];
        $bus = new MessageBus([
            $container->get(ToolPayloadMiddleware::class),
            new HandleMessageMiddleware(new HandlersLocator([
                ToolPayloadMessage::class => [function (ToolPayloadMessage $message) use (&$published, $deferred, $run): ToolPayloadMessage {
                    $published[] = $message;
                    if ('code-call' === $message->reference->toolCallId) {
                        $metadata = $deferred->findMetadataByRunAndToolCall($run, 'code-call');
                        $this->assertNotNull($metadata, 'Deferred row must exist inside the outgoing ToolPayloadMessage path.');
                        $this->assertSame('code_mode', $metadata->reference->toolName);
                        $this->assertNull($metadata->readyAt, 'Owner preparation must leave readyAt null.');
                    }

                    return $message;
                }],
            ])),
        ]);

        $sealed = $bus->dispatch($code);
        $this->assertInstanceOf(ToolPayloadMessage::class, $sealed->getMessage());
        $metadata = $deferred->findMetadataByRunAndToolCall($run, 'code-call');
        $this->assertNotNull($metadata);
        $firstId = $metadata->deferredId;
        $this->assertNull($deferred->findMetadataByRunAndToolCall($run, 'echo-call'));

        $repeat = $bus->dispatch($code);
        $this->assertInstanceOf(ToolPayloadMessage::class, $repeat->getMessage());
        $this->assertSame($firstId, $deferred->findMetadataByRunAndToolCall($run, 'code-call')?->deferredId);

        $otherSealed = $bus->dispatch($other);
        $this->assertInstanceOf(ToolPayloadMessage::class, $otherSealed->getMessage());
        $this->assertNull($deferred->findMetadataByRunAndToolCall($run, 'echo-call'));

        $this->assertGreaterThanOrEqual(2, \count($published));
        $this->assertNull($published[0]->deferredId);
        $this->assertSame($firstId, $container->get(CodeModeLifecycleService::class)->prepare($code));
    }

    public function testProducerPreparesAgentResumeAndRoutesRefusalToRunControlDespiteAgentStamp(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('owner prepared agent_resume');
        $artifact = 'agent_resume_prepare';
        $child = 'child-resume-prepare';
        $registry = $container->get(AgentArtifactRegistry::class);
        $registry->create($run, $artifact, $child, 'scout', AgentArtifactKindEnum::Subagent);
        $registry->update($run, $artifact, status: AgentArtifactStatusEnum::Completed, summary: 'done');
        $this->seedPreparedChildProjection($run, $artifact, $child);
        $container->get(RunOperationalProjectionRepository::class)->replace(new RunState($run, RunStatus::Running));

        $winner = new ExecuteToolCall($run, 1, 'resume-step', 1, hash('sha256', 'winner'), 'resume-winner', 'agent_resume', [
            'artifact_id' => $artifact,
            'task' => 'continue',
        ], 0, mode: 'parallel', maxParallelism: 3, parentModel: 'test/model');
        $duplicate = new ExecuteToolCall($run, 1, 'resume-step', 1, hash('sha256', 'dup'), 'resume-dup', 'agent_resume', [
            'artifact_id' => $artifact,
            'task' => 'duplicate',
        ], 1, mode: 'parallel', maxParallelism: 3, parentModel: 'test/model');
        $invalid = new ExecuteToolCall($run, 1, 'resume-step', 1, hash('sha256', 'missing'), 'resume-missing', 'agent_resume', [
            'artifact_id' => 'agent_missing',
            'task' => 'continue',
        ], 2, mode: 'parallel', maxParallelism: 3, parentModel: 'test/model');
        $batches = $container->get(ToolBatchStoreInterface::class);
        TestToolBatchRegistration::register(
            new ToolBatchCollector($batches),
            $batches,
            $run,
            1,
            'resume-step',
            [$winner, $duplicate, $invalid],
        );

        $published = [];
        $bus = new MessageBus([
            new SubagentExecuteToolCallRoutingMiddleware(),
            $container->get(ToolPayloadMiddleware::class),
            new HandleMessageMiddleware(new HandlersLocator([
                ToolPayloadMessage::class => [function (ToolPayloadMessage $message) use (&$published, $container, $run, $artifact, $child): ToolPayloadMessage {
                    $published[] = $message;
                    if ('resume-winner' === $message->reference->toolCallId) {
                        $this->assertSame('call', $message->reference->kind);
                        $batch = $container->get(DeferredSubagentBatchRepository::class)->findByParentRunAndToolCall($run, 'resume-winner');
                        $this->assertNotNull($batch, 'Reserved batch must exist inside the outgoing call path.');
                        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Reserved, $batch->launchStatus);
                        $this->assertCount(1, $batch->children);
                        $this->assertSame($child, $batch->children[0]->childRunId);
                        $this->assertSame($artifact, $batch->children[0]->artifactId);
                    }

                    return $message;
                }],
            ])),
        ]);

        $winnerSealed = $bus->dispatch($winner);
        $winnerMessage = $winnerSealed->getMessage();
        $this->assertInstanceOf(ToolPayloadMessage::class, $winnerMessage);
        $this->assertSame('call', $winnerMessage->reference->kind);
        $this->assertSame(['agent'], $winnerSealed->last(TransportNamesStamp::class)?->getTransportNames());

        $duplicateSealed = $bus->dispatch($duplicate);
        $duplicateMessage = $duplicateSealed->getMessage();
        $this->assertInstanceOf(ToolPayloadMessage::class, $duplicateMessage);
        $this->assertSame('result', $duplicateMessage->reference->kind);
        $this->assertSame(['run_control'], $duplicateSealed->last(TransportNamesStamp::class)?->getTransportNames());
        $duplicateBody = $container->get(\Ineersa\CodingAgent\Session\SessionToolPayloadStore::class)->read($duplicateMessage->reference);
        $this->assertInstanceOf(ToolCallResult::class, $duplicateBody);
        $this->assertTrue($duplicateBody->isError);
        $this->assertStringContainsString('Duplicate agent_resume', (string) ($duplicateBody->result['content'][0]['text'] ?? ''));
        $this->assertNull($container->get(DeferredSubagentBatchRepository::class)->findByParentRunAndToolCall($run, 'resume-dup'));

        $invalidSealed = $bus->dispatch($invalid);
        $invalidMessage = $invalidSealed->getMessage();
        $this->assertInstanceOf(ToolPayloadMessage::class, $invalidMessage);
        $this->assertSame('result', $invalidMessage->reference->kind);
        $this->assertSame(['run_control'], $invalidSealed->last(TransportNamesStamp::class)?->getTransportNames());
        $invalidBody = $container->get(\Ineersa\CodingAgent\Session\SessionToolPayloadStore::class)->read($invalidMessage->reference);
        $this->assertInstanceOf(ToolCallResult::class, $invalidBody);
        $this->assertTrue($invalidBody->isError);
        $this->assertStringContainsString('Unknown artifact_id', (string) ($invalidBody->result['content'][0]['text'] ?? ''));

        $this->assertGreaterThanOrEqual(3, \count($published));
    }

    public function testProducerPreparesSubagentAndForkBeforeSealingExecutionAndRetiresFailedReservation(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('owner prepared subagent fork');
        $container->get(RunOperationalProjectionRepository::class)->replace(new RunState($run, RunStatus::Running));
        $subagent = new ExecuteToolCall($run, 1, 'child-step', 1, hash('sha256', 'sub'), 'sub-call', 'subagent', [
            'agent' => 'scout',
            'task' => 'inspect',
        ], 0, mode: 'parallel', maxParallelism: 2, parentModel: 'test/model');
        $fork = new ExecuteToolCall($run, 1, 'child-step', 1, hash('sha256', 'fork'), 'fork-call', 'fork', [
            'task' => 'continue',
        ], 1, mode: 'parallel', maxParallelism: 2, parentModel: 'test/model');
        $batches = $container->get(ToolBatchStoreInterface::class);
        TestToolBatchRegistration::register(
            new ToolBatchCollector($batches),
            $batches,
            $run,
            1,
            'child-step',
            [$subagent, $fork],
        );
        $published = [];
        $bus = new MessageBus([
            new SubagentExecuteToolCallRoutingMiddleware(),
            $container->get(ToolPayloadMiddleware::class),
            new HandleMessageMiddleware(new HandlersLocator([
                ToolPayloadMessage::class => [function (ToolPayloadMessage $message) use (&$published, $container, $run): ToolPayloadMessage {
                    $published[] = $message;
                    if ('call' === $message->reference->kind) {
                        $batch = $container->get(DeferredSubagentBatchRepository::class)->findByParentRunAndToolCall($run, $message->reference->toolCallId);
                        $this->assertNotNull($batch, 'Reserved batch must exist inside the outgoing call path.');
                        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Reserved, $batch->launchStatus);
                        $deferred = $container->get(DeferredToolCompletionRepositoryInterface::class)
                            ->findMetadataByRunAndToolCall($run, $message->reference->toolCallId);
                        $this->assertNotNull($deferred, 'Prepared deferred correlation must exist before send.');
                        $this->assertSame($batch->lifecycleId, $deferred->deferredId);
                        $this->assertNull($deferred->readyAt);
                    }

                    return $message;
                }],
            ])),
        ]);
        $subagentSealed = $bus->dispatch($subagent);
        $this->assertSame('call', $subagentSealed->getMessage()->reference->kind);
        $this->assertSame(['agent'], $subagentSealed->last(TransportNamesStamp::class)?->getTransportNames());
        $forkSealed = $bus->dispatch($fork);
        $this->assertSame('call', $forkSealed->getMessage()->reference->kind);
        $failed = \Ineersa\AgentCore\Application\Handler\ToolCallResultFactory::fromExecuteToolCallAndThrowable(
            $subagent,
            new \Ineersa\AgentCore\Contract\Tool\ToolCallException('Fork compaction failed before child launch: boom', retryable: false),
        );
        // Force tool_name to subagent for retirement path coverage of reserved row.
        $failed = new ToolCallResult(
            $failed->runId(),
            $failed->turnNo(),
            $failed->stepId(),
            $failed->attempt(),
            $failed->idempotencyKey(),
            $failed->toolCallId,
            $failed->orderIndex,
            [
                'tool_name' => 'subagent',
                'content' => [['type' => 'text', 'text' => 'Fork compaction failed before child launch: boom']],
                'details' => ['retryable' => false],
            ],
            true,
            ['message' => 'Fork compaction failed before child launch: boom', 'retryable' => false],
        );
        $resultBus = new MessageBus([
            $container->get(ToolPayloadMiddleware::class),
            new HandleMessageMiddleware(new HandlersLocator([
                ToolPayloadMessage::class => [static fn (ToolPayloadMessage $message): ToolPayloadMessage => $message],
            ])),
        ]);
        $resultSealed = $resultBus->dispatch($failed);
        $this->assertSame('result', $resultSealed->getMessage()->reference->kind);
        $this->assertSame(['run_control'], $resultSealed->last(TransportNamesStamp::class)?->getTransportNames());
        $batchAfterPublish = $container->get(DeferredSubagentBatchRepository::class)->findByParentRunAndToolCall($run, 'sub-call');
        $this->assertSame(
            DeferredSubagentBatchLaunchStatusEnum::Reserved,
            $batchAfterPublish?->launchStatus,
            'Remote/worker publication must leave the reserved batch untouched.',
        );

        $container->get(ActiveRunContextInterface::class)->loadRecovered(new RunState(
            $run,
            RunStatus::Running,
            turnNo: 1,
            activeStepId: 'child-step',
            pendingToolCalls: ['sub-call' => false, 'fork-call' => false],
        ));
        $ownerBus = new MessageBus([
            $container->get(ToolPayloadMiddleware::class),
            new HandleMessageMiddleware(new HandlersLocator([
                ToolCallResult::class => [static fn (ToolCallResult $message): ToolCallResult => $message],
            ])),
        ]);
        $ownerBus->dispatch($resultSealed->with(new ReceivedStamp('run_control')));
        $batch = $container->get(DeferredSubagentBatchRepository::class)->findByParentRunAndToolCall($run, 'sub-call');
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Failed, $batch?->launchStatus);
        $this->assertGreaterThanOrEqual(2, \count($published));
    }

    #[DataProvider('mismatchedIdentities')]
    public function testDeferredReceiverRefusesMismatchedIdentityEvenAfterCompletion(string $field): void
    {
        $payloads = $this->createMock(ToolPayloadStoreInterface::class);
        $payloads->expects($this->never())->method('read');
        $deferred = $this->createMock(DeferredToolCompletionRepositoryInterface::class);
        $metadata = new DeferredToolCompletionMetadataDTO('deferred', $this->reference('correlation'), 'completed');
        $deferred->method('findMetadataByDeferredId')->willReturn($metadata);
        $deferred->expects($this->never())->method('markCompleted');
        $data = get_object_vars($this->reference('result'));
        $data[$field] = \is_int($data[$field]) ? $data[$field] + 1 : $data[$field].'-wrong';
        $message = new ToolPayloadMessage(new ToolPayloadReferenceDTO(...$data), 'deferred');
        $this->expectException(ToolPayloadException::class);
        new MessageBus([$this->middleware($payloads, $deferred)])->dispatch(new Envelope($message, [new ReceivedStamp('run_control')]));
    }

    public function testOwnerReceiveRetiresReservedAgentResumeAndIgnoresDelayedFailureAfterLaunch(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('owner retire agent_resume');
        $artifact = 'agent_resume_retire';
        $child = 'child-resume-retire';
        $registry = $container->get(AgentArtifactRegistry::class);
        $registry->create($run, $artifact, $child, 'scout', AgentArtifactKindEnum::Subagent);
        $registry->update($run, $artifact, status: AgentArtifactStatusEnum::Completed, summary: 'done');
        $this->seedPreparedChildProjection($run, $artifact, $child);
        $container->get(RunOperationalProjectionRepository::class)->replace(new RunState($run, RunStatus::Running));

        $call = new ExecuteToolCall($run, 1, 'resume-retire-step', 1, hash('sha256', 'resume-retire'), 'resume-retire', 'agent_resume', [
            'artifact_id' => $artifact,
            'task' => 'continue',
        ], 0, parentModel: 'test/model');
        $batches = $container->get(ToolBatchStoreInterface::class);
        TestToolBatchRegistration::register(
            new ToolBatchCollector($batches),
            $batches,
            $run,
            1,
            'resume-retire-step',
            [$call],
        );

        $bus = new MessageBus([
            new SubagentExecuteToolCallRoutingMiddleware(),
            $container->get(ToolPayloadMiddleware::class),
            new HandleMessageMiddleware(new HandlersLocator([
                ToolPayloadMessage::class => [static fn (ToolPayloadMessage $message): ToolPayloadMessage => $message],
            ])),
        ]);
        $sealedCall = $bus->dispatch($call);
        $this->assertSame('call', $sealedCall->getMessage()->reference->kind);

        $failed = \Ineersa\AgentCore\Application\Handler\ToolCallResultFactory::fromExecuteToolCallAndThrowable(
            $call,
            new \Ineersa\AgentCore\Contract\Tool\ToolCallException('agent_resume followUp failed', retryable: false),
        );
        $failed = new ToolCallResult(
            $failed->runId(),
            $failed->turnNo(),
            $failed->stepId(),
            $failed->attempt(),
            $failed->idempotencyKey(),
            $failed->toolCallId,
            $failed->orderIndex,
            [
                'tool_name' => 'agent_resume',
                'content' => [['type' => 'text', 'text' => 'agent_resume followUp failed']],
                'details' => ['retryable' => false],
            ],
            true,
            ['message' => 'agent_resume followUp failed', 'retryable' => false],
        );

        $resultBus = new MessageBus([
            $container->get(ToolPayloadMiddleware::class),
            new HandleMessageMiddleware(new HandlersLocator([
                ToolPayloadMessage::class => [static fn (ToolPayloadMessage $message): ToolPayloadMessage => $message],
            ])),
        ]);
        $resultSealed = $resultBus->dispatch($failed);
        $this->assertSame('result', $resultSealed->getMessage()->reference->kind);
        $batchAfterPublish = $container->get(DeferredSubagentBatchRepository::class)->findByParentRunAndToolCall($run, 'resume-retire');
        $this->assertSame(
            DeferredSubagentBatchLaunchStatusEnum::Reserved,
            $batchAfterPublish?->launchStatus,
            'Remote/worker publication must leave the reserved resume batch untouched.',
        );

        $container->get(ActiveRunContextInterface::class)->loadRecovered(new RunState(
            $run,
            RunStatus::Running,
            turnNo: 1,
            activeStepId: 'resume-retire-step',
            pendingToolCalls: ['resume-retire' => false],
        ));
        $ownerBus = new MessageBus([
            $container->get(ToolPayloadMiddleware::class),
            new HandleMessageMiddleware(new HandlersLocator([
                ToolCallResult::class => [static fn (ToolCallResult $message): ToolCallResult => $message],
            ])),
        ]);
        $ownerBus->dispatch($resultSealed->with(new ReceivedStamp('run_control')));
        $failedBatch = $container->get(DeferredSubagentBatchRepository::class)->findByParentRunAndToolCall($run, 'resume-retire');
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Failed, $failedBatch?->launchStatus);

        $launchedCall = new ExecuteToolCall($run, 1, 'resume-launched-step', 1, hash('sha256', 'resume-launched'), 'resume-launched', 'agent_resume', [
            'artifact_id' => $artifact,
            'task' => 'continue',
        ], 0, parentModel: 'test/model');
        TestToolBatchRegistration::register(
            new ToolBatchCollector($batches),
            $batches,
            $run,
            1,
            'resume-launched-step',
            [$launchedCall],
        );
        $bus->dispatch($launchedCall);
        $lifecycle = new ChildInvocationLifecycleService(
            $container->get(DeferredSubagentBatchRepository::class),
        );
        $batch = $container->get(DeferredSubagentBatchRepository::class)->findByParentRunAndToolCall($run, 'resume-launched');
        $this->assertNotNull($batch);
        $lifecycle->applyLaunchSuccessState($run, 'resume-launched', $batch->lifecycleId, new \DateTimeImmutable());
        $this->assertSame(
            DeferredSubagentBatchLaunchStatusEnum::Launched,
            $container->get(DeferredSubagentBatchRepository::class)->findByParentRunAndToolCall($run, 'resume-launched')?->launchStatus,
        );

        $delayed = \Ineersa\AgentCore\Application\Handler\ToolCallResultFactory::fromExecuteToolCallAndThrowable(
            $launchedCall,
            new \Ineersa\AgentCore\Contract\Tool\ToolCallException('late failure after launch', retryable: false),
        );
        $delayed = new ToolCallResult(
            $delayed->runId(),
            $delayed->turnNo(),
            $delayed->stepId(),
            $delayed->attempt(),
            $delayed->idempotencyKey(),
            $delayed->toolCallId,
            $delayed->orderIndex,
            [
                'tool_name' => 'agent_resume',
                'content' => [['type' => 'text', 'text' => 'late failure after launch']],
                'details' => ['retryable' => false],
            ],
            true,
            ['message' => 'late failure after launch', 'retryable' => false],
        );
        $delayedSealed = $resultBus->dispatch($delayed);
        $container->get(ActiveRunContextInterface::class)->loadRecovered(new RunState(
            $run,
            RunStatus::Running,
            turnNo: 1,
            activeStepId: 'resume-launched-step',
            pendingToolCalls: ['resume-launched' => false],
        ));
        $ownerBus->dispatch($delayedSealed->with(new ReceivedStamp('run_control')));
        $this->assertSame(
            DeferredSubagentBatchLaunchStatusEnum::Launched,
            $container->get(DeferredSubagentBatchRepository::class)->findByParentRunAndToolCall($run, 'resume-launched')?->launchStatus,
            'Delayed failed results must not overwrite Launched reservations.',
        );
    }

    /** @return iterable<array{string}> */
    public static function mismatchedIdentities(): iterable
    {
        foreach (['runId', 'turnNo', 'stepId', 'attempt', 'idempotencyKey', 'toolCallId', 'orderIndex', 'toolName'] as $field) {
            yield $field => [$field];
        }
    }

    protected static function configureIsolatedProjectBeforeKernelBoot(string $classCwd): void
    {
        // Isolated CWD starts without installed agents; seed the bundled scout
        // definition so owner-prepared subagent preparation can reserve metadata.
        $agentsDir = $classCwd.'/.hatfield/agents';
        TestDirectoryIsolation::ensureDirectory($agentsDir);
        $source = \dirname(__DIR__, 4).'/src/CodingAgent/Resources/agents/scout.md';
        if (!is_file($source)) {
            throw new \RuntimeException('Bundled scout agent definition is missing: '.$source);
        }
        if (!copy($source, $agentsDir.'/scout.md')) {
            throw new \RuntimeException('Failed to install scout agent fixture into '.$agentsDir);
        }
    }

    private function seedPreparedChildProjection(string $parent, string $artifactId, string $childRunId): void
    {
        $serializer = self::getContainer()->get(SerializerInterface::class);
        $projection = new DeferredChildRunLifecycleProjectionDTO(
            childStatus: RunStatus::Completed,
            childTurnNo: 2,
            lastCommittedSeq: 5,
            model: 'test/model',
            reasoning: 'medium',
            latestInputTokens: 10,
            contextWindow: 200_000,
        );
        $projectionArray = $serializer->normalize(
            $projection,
            null,
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );
        if (!\is_array($projectionArray)) {
            throw new \RuntimeException('Expected normalized lifecycle projection array.');
        }

        $em = self::getContainer()->get('doctrine')->getManager();
        $row = new DeferredSubagentChild();
        $row->batchLifecycleId = 'batch-'.$childRunId;
        $row->batchIndex = 1;
        $row->childRunId = $childRunId;
        $row->artifactId = $artifactId;
        $row->agentName = 'scout';
        $row->task = 'seed';
        $row->launchModel = 'test/model';
        $row->launchReasoning = 'medium';
        $row->launchStatus = DeferredSubagentChildLaunchStatusEnum::Launched;
        $row->childEventCursor = 5;
        $row->childLifecycleProjection = $projectionArray;
        $row->terminalCompletedAt = new \DateTimeImmutable('2026-08-21T00:01:00Z');
        $row->terminalStatus = 'completed';
        $em->persist($row);
        $em->flush();
        $store = self::getContainer()->get(AgentChildRunEventStoreFactory::class)->create($parent, $childRunId, $artifactId);
        PreparedEventStoreSeeder::append($store, RunEvent::forAppend($childRunId, 1, 'run_started'));
        $terminal = PreparedEventStoreSeeder::append($store, RunEvent::forAppend($childRunId, 1, 'agent_end', ['status' => 'completed']));
        self::getContainer()->get(RunOperationalProjectionRepository::class)->replace(
            new RunState($childRunId, RunStatus::Completed, parentRunId: $parent, lastSeq: $terminal->seq),
        );
    }

    private function reference(string $kind): ToolPayloadReferenceDTO
    {
        return new ToolPayloadReferenceDTO($kind, 'run', 1, 'step', 1, 'key', 'call', 0, 'echo', str_repeat('a', 64), 100);
    }

    private function call(): ExecuteToolCall
    {
        return new ExecuteToolCall('run', 1, 'step', 1, 'key', 'call', 'echo', ['large' => str_repeat('body-marker', 20000)], 0);
    }

    private function middleware(
        ToolPayloadStoreInterface $payloads,
        ?DeferredToolCompletionRepositoryInterface $deferred = null,
        ?ToolBatchStoreInterface $batches = null,
        ?ActiveRunContextInterface $registry = null,
        ?RunStateRebuilderInterface $replay = null,
    ): ToolPayloadMiddleware {
        $deferred ??= $this->createStub(DeferredToolCompletionRepositoryInterface::class);
        if (null === $batches) {
            $batches = $this->createStub(ToolBatchStoreInterface::class);
            $batches->method('loadCallReference')->willReturn($this->reference('call'));
            $batches->method('loadSchedule')->willReturn(new ToolBatchScheduleDTO(['call' => 0], ['call' => 'sequential'], [], ['call' => true], [], false, 1));
        }
        if (null === $registry) {
            $registry = $this->createStub(ActiveRunContextInterface::class);
            $registry->method('requireLoaded')->willThrowException(new RunContextNotLoadedException('Not loaded'));
        }
        $replay ??= $this->createStub(RunStateRebuilderInterface::class);
        $locks = new RunLockManager(new LockFactory(new InMemoryStore()));
        $commands = new TestMessageBus();
        $logger = new TestLogger();
        $events = $this->createStub(EventStoreInterface::class);
        $events->method('latestSequenceFor')->willReturn(7);

        return new ToolPayloadMiddleware(
            $payloads, new ToolPayloadAdmission($batches, $registry, $replay, $events, $logger, new CanonicalToolInvocationReader($this->createStub(RunHistorySourceProviderInterface::class))), $deferred, $locks,
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(), $commands, $logger,
            new CompleteDeferredToolCallHandler($deferred, $commands, $locks, $batches, $logger), \Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory::denormalizer(),
            self::getContainer()->get(CodeModeLifecycleService::class),
            self::getContainer()->get(AgentResumeExecutionService::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Application\AgentCommunication\SubmissionFactory::class),
            self::getContainer()->get(ChildInvocationLifecycleService::class),
            self::getContainer()->get(DeferredSubagentBatchRepository::class));
    }
}
