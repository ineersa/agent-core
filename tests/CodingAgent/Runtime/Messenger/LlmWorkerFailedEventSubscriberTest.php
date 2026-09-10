<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Runtime\Messenger\LlmWorkerFailedEventSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

final class LlmWorkerFailedEventSubscriberTest extends TestCase
{
    private const string RUN_ID = 'run-llm-failed';
    private const string RAW_MARKER = 'RAW_PROVIDER_SECRET_MARKER::websocket-idle';

    #[Test]
    public function skipsWhenRetryWillHappen(): void
    {
        $commandBus = new TestMessageBus();
        $subscriber = new LlmWorkerFailedEventSubscriber($commandBus, new TestLogger());
        $event = new WorkerMessageFailedEvent(
            new Envelope($this->executeLlmStep()),
            'llm',
            new UnrecoverableMessageHandlingException(self::RAW_MARKER),
        );
        $event->setForRetry();

        $subscriber->onWorkerMessageFailed($event);

        $this->assertSame([], $commandBus->messages);
    }

    #[Test]
    public function skipsNonLlmReceiver(): void
    {
        $commandBus = new TestMessageBus();
        $subscriber = new LlmWorkerFailedEventSubscriber($commandBus, new TestLogger());

        $subscriber->onWorkerMessageFailed(new WorkerMessageFailedEvent(
            new Envelope($this->executeLlmStep()),
            'tool',
            new UnrecoverableMessageHandlingException(self::RAW_MARKER),
        ));

        $this->assertSame([], $commandBus->messages);
    }

    #[Test]
    public function skipsNonExecuteLlmStepMessage(): void
    {
        $commandBus = new TestMessageBus();
        $subscriber = new LlmWorkerFailedEventSubscriber($commandBus, new TestLogger());

        $subscriber->onWorkerMessageFailed(new WorkerMessageFailedEvent(
            new Envelope(new \stdClass()),
            'llm',
            new UnrecoverableMessageHandlingException(self::RAW_MARKER),
        ));

        $this->assertSame([], $commandBus->messages);
    }

    #[Test]
    public function dispatchesGenericTerminalResultForFinalWorkerFailure(): void
    {
        $commandBus = new TestMessageBus();
        $logger = new TestLogger();
        $subscriber = new LlmWorkerFailedEventSubscriber($commandBus, $logger);

        $subscriber->onWorkerMessageFailed(new WorkerMessageFailedEvent(
            new Envelope($this->executeLlmStep()),
            'llm',
            new UnrecoverableMessageHandlingException(self::RAW_MARKER),
        ));

        $this->assertCount(1, $commandBus->messages);
        $result = $commandBus->messages[0];
        $this->assertInstanceOf(LlmStepResult::class, $result);
        $this->assertSame(self::RUN_ID, $result->runId());
        $this->assertSame('tools-ref-1', $result->toolsRef);
        $this->assertSame('', $result->model);
        $this->assertSame('llm_step_delivery_failed', $result->error['type'] ?? null);
        $this->assertSame('messenger', $result->error['error_category'] ?? null);
        $this->assertSame('LLM step result could not be delivered.', $result->error['user_message'] ?? null);
        $this->assertFalse($result->error['retryable'] ?? true);
        $this->assertStringNotContainsString(self::RAW_MARKER, json_encode($result->error, \JSON_THROW_ON_ERROR));

        $records = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'llm.worker_failed.terminal_result_dispatched' === $record['message'],
        ));
        $this->assertCount(1, $records);
        $this->assertStringNotContainsString(self::RAW_MARKER, json_encode($records[0], \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function rethrowsWhenTerminalResultDispatchFails(): void
    {
        $dispatchFailure = new TransportException('command bus unavailable');
        $handlerBus = $this->createStub(MessageBusInterface::class);
        $handlerBus->method('dispatch')->willThrowException(
            new UnrecoverableMessageHandlingException(self::RAW_MARKER),
        );
        $terminalBus = $this->createMock(MessageBusInterface::class);
        $terminalBus->expects($this->once())->method('dispatch')->willThrowException($dispatchFailure);

        $message = $this->executeLlmStep();
        $receiver = new class($message) implements \Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface {
            /** @var list<Envelope> */
            public array $acked = [];
            /** @var list<Envelope> */
            public array $rejected = [];

            public function __construct(private readonly object $message)
            {
            }

            public function get(): iterable
            {
                yield new Envelope($this->message);
            }

            public function ack(Envelope $envelope): void
            {
                $this->acked[] = $envelope;
            }

            public function reject(Envelope $envelope): void
            {
                $this->rejected[] = $envelope;
            }
        };

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new LlmWorkerFailedEventSubscriber($terminalBus, new TestLogger()));
        $worker = new Worker(['llm' => $receiver], $handlerBus, $dispatcher);

        try {
            $worker->run(['sleep' => 0]);
            $this->fail('Worker must propagate terminal result dispatch failure.');
        } catch (TransportException $exception) {
            $this->assertSame($dispatchFailure, $exception);
        }

        $this->assertSame([], $receiver->acked);
        $this->assertSame([], $receiver->rejected);
    }

    private function executeLlmStep(): ExecuteLlmStep
    {
        return new ExecuteLlmStep(
            runId: self::RUN_ID,
            turnNo: 2,
            stepId: 'step-llm-1',
            attempt: 1,
            idempotencyKey: 'idem-llm-1',
            toolsRef: 'tools-ref-1',
        );
    }
}
