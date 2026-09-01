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
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\TransportException;

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
    public function skipsWhenThrowableIsNotRetryableLlmFailure(): void
    {
        $commandBus = new TestMessageBus();
        $logger = new TestLogger();
        $subscriber = new LlmWorkerFailedEventSubscriber($commandBus, $logger);

        $subscriber->onWorkerMessageFailed(new WorkerMessageFailedEvent(
            new Envelope($this->executeLlmStep()),
            'llm',
            new \RuntimeException(self::RAW_MARKER),
        ));

        $this->assertSame([], $commandBus->messages);
        $records = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'llm.worker_failed.ignored_non_retryable_exception' === $record['message'],
        ));
        $this->assertCount(1, $records);
        $this->assertSame(\RuntimeException::class, $records[0]['context']['exception_class'] ?? null);
        $this->assertArrayNotHasKey('exception_message', $records[0]['context']);
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
    public function logsDispatchFailureWithoutRawMessageAndDoesNotRethrow(): void
    {
        $rawMarker = self::RAW_MARKER;
        $commandBus = new class($rawMarker) implements \Symfony\Component\Messenger\MessageBusInterface {
            public function __construct(private readonly string $rawMarker)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new TransportException($this->rawMarker);
            }
        };
        $logger = new TestLogger();
        $subscriber = new LlmWorkerFailedEventSubscriber($commandBus, $logger);

        $subscriber->onWorkerMessageFailed(new WorkerMessageFailedEvent(
            new Envelope($this->executeLlmStep()),
            'llm',
            $this->providerFailure(),
        ));

        $records = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'llm.worker_failed.terminal_result_dispatch_failed' === $record['message'],
        ));
        $this->assertCount(1, $records);
        $this->assertSame(TransportException::class, $records[0]['context']['exception_class'] ?? null);
        $this->assertArrayNotHasKey('exception_message', $records[0]['context']);
        $this->assertStringNotContainsString(self::RAW_MARKER, json_encode($records[0], \JSON_THROW_ON_ERROR));
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
