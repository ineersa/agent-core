<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\RetryableLlmStepFailureException;
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
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
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
            $this->providerFailure(),
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
            $this->providerFailure(),
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
            $this->providerFailure(),
        ));

        $this->assertSame([], $commandBus->messages);
    }

    #[Test]
    public function dispatchesGenericTerminalResultForFinalNonProviderFailure(): void
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
    public function dispatchesSanitizedTerminalResultAfterFinalRetryableFailure(): void
    {
        $commandBus = new TestMessageBus();
        $logger = new TestLogger();
        $subscriber = new LlmWorkerFailedEventSubscriber($commandBus, $logger);
        $message = $this->executeLlmStep();
        $wrapped = new HandlerFailedException(
            new Envelope($message),
            [$this->providerFailure()],
        );

        $subscriber->onWorkerMessageFailed(new WorkerMessageFailedEvent(
            new Envelope($message),
            'llm',
            $wrapped,
        ));

        $this->assertCount(1, $commandBus->messages);
        $result = $commandBus->messages[0];
        $this->assertInstanceOf(LlmStepResult::class, $result);
        $this->assertSame(self::RUN_ID, $result->runId());
        $this->assertSame(2, $result->turnNo());
        $this->assertSame('step-llm-1', $result->stepId());
        $this->assertSame(1, $result->attempt());
        $this->assertSame('idem-llm-1', $result->idempotencyKey());
        $this->assertSame('tools-ref-1', $result->toolsRef);
        $this->assertSame('openai-codex/gpt-5.6-luna', $result->model);
        $this->assertSame('medium', $result->reasoning);
        $this->assertSame(['bash', 'edit'], $result->availableTools);
        $this->assertSame(12, $result->availableToolsSchemaTokensEstimate);
        $this->assertSame('error', $result->stopReason);
        $this->assertFalse($result->error['retryable'] ?? true);
        $this->assertSame('LLM provider request failed.', $result->error['user_message'] ?? null);
        $this->assertSame('LLM provider request failed.', $result->error['message'] ?? null);
        $this->assertArrayNotHasKey('response_body_preview', $result->error);
        $this->assertArrayNotHasKey('response_error_message', $result->error);
        $this->assertStringNotContainsString(self::RAW_MARKER, json_encode($result->error, \JSON_THROW_ON_ERROR));

        $records = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'llm.worker_failed.terminal_result_dispatched' === $record['message'],
        ));
        $this->assertCount(1, $records);
        $this->assertStringNotContainsString(self::RAW_MARKER, json_encode($records[0], \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function logsDispatchFailureWithoutRawMessageAndRethrowsExactException(): void
    {
        $dispatchFailure = new TransportException(self::RAW_MARKER);
        $commandBus = new class($dispatchFailure) implements MessageBusInterface {
            public function __construct(private readonly TransportException $dispatchFailure)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw $this->dispatchFailure;
            }
        };
        $logger = new TestLogger();
        $subscriber = new LlmWorkerFailedEventSubscriber($commandBus, $logger);

        try {
            $subscriber->onWorkerMessageFailed(new WorkerMessageFailedEvent(
                new Envelope($this->executeLlmStep()),
                'llm',
                $this->providerFailure(),
            ));
            $this->fail('Terminal result dispatch failure must escape the subscriber.');
        } catch (TransportException $exception) {
            $this->assertSame($dispatchFailure, $exception);
        }

        $records = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'llm.worker_failed.terminal_result_dispatch_failed' === $record['message'],
        ));
        $this->assertCount(1, $records);
        $this->assertSame(TransportException::class, $records[0]['context']['exception_class'] ?? null);
        $this->assertArrayNotHasKey('exception_message', $records[0]['context']);
        $this->assertStringNotContainsString(self::RAW_MARKER, json_encode($records[0], \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function workerDoesNotRejectOriginalEnvelopeWhenTerminalResultDispatchFails(): void
    {
        $message = $this->executeLlmStep();
        $providerFailure = $this->providerFailure();
        $handlerBus = new class($providerFailure) implements MessageBusInterface {
            public function __construct(private readonly RetryableLlmStepFailureException $providerFailure)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw $this->providerFailure;
            }
        };

        $dispatchFailure = new TransportException(self::RAW_MARKER);
        $terminalBus = new class($dispatchFailure) implements MessageBusInterface {
            public function __construct(private readonly TransportException $dispatchFailure)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw $this->dispatchFailure;
            }
        };

        $receiver = new class(new Envelope($message)) implements ReceiverInterface {
            /** @var list<Envelope> */
            public array $acked = [];

            /** @var list<Envelope> */
            public array $rejected = [];

            private bool $delivered = false;

            public function __construct(private readonly Envelope $envelope)
            {
            }

            public function get(): iterable
            {
                if ($this->delivered) {
                    return;
                }

                $this->delivered = true;
                yield $this->envelope;
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

    private function providerFailure(): RetryableLlmStepFailureException
    {
        return new RetryableLlmStepFailureException(
            runId: self::RUN_ID,
            stepId: 'step-llm-1',
            error: [
                'type' => 'provider_error',
                'message' => self::RAW_MARKER,
                'retryable' => true,
                'error_category' => 'provider',
                'user_message' => 'LLM provider request failed.',
                'response_body_preview' => self::RAW_MARKER,
                'response_error_message' => self::RAW_MARKER,
            ],
            toolsRef: 'tools-ref-1',
            model: 'openai-codex/gpt-5.6-luna',
            reasoning: 'medium',
            availableTools: ['bash', 'edit'],
            availableToolsSchemaTokensEstimate: 12,
        );
    }
}
