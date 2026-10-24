<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DTO\DeferredToolCompletionMetadataDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolBatchScheduleDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Runtime\Messenger\ToolPayloadMiddleware;
use Ineersa\CodingAgent\Session\CanonicalToolInvocationReader;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Ineersa\CodingAgent\Session\ToolPayloadAdmission;
use Ineersa\CodingAgent\Tests\Runtime\Messenger\Support\ToolPayloadWorkerTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validation;

final class ToolPayloadMiddlewareTest extends TestCase
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
        $this->assertSame($transport->received[0]->getMessage(), $transport->rejected[0]->getMessage());
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

    /** @return iterable<array{string}> */
    public static function mismatchedIdentities(): iterable
    {
        foreach (['runId', 'turnNo', 'stepId', 'attempt', 'idempotencyKey', 'toolCallId', 'orderIndex', 'toolName'] as $field) {
            yield $field => [$field];
        }
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

        $fence = new \Ineersa\AgentCore\Domain\Tool\DeferredToolInvocationFence(new \Symfony\Component\Serializer\Serializer());

        return new ToolPayloadMiddleware(
            $payloads, new ToolPayloadAdmission($batches, $registry, $replay, $events, $logger, new CanonicalToolInvocationReader($this->createStub(RunHistorySourceProviderInterface::class)), $deferred, $payloads, $fence), $deferred, $locks,
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(), $commands, $logger,
            new CompleteDeferredToolCallHandler($deferred, $commands, $locks, $batches, $payloads, $fence, $logger, null),
        );
    }
}
