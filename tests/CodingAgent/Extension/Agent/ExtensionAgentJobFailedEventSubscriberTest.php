<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Agent;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobFailedEventSubscriber;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobMessage;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobWorker;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Thesis: final extension_agent delivery with validated payload.run_id emits
 * exactly one transient event containing the unwrapped handler error; retrying
 * failure emits none; missing run_id logs only.
 */
final class ExtensionAgentJobFailedEventSubscriberTest extends TestCase
{
    #[Test]
    public function finalFailureWithRunIdEmitsUnderlyingError(): void
    {
        $sink = new CollectingRuntimeEventSink();
        $logger = new TestLogger();
        $subscriber = new ExtensionAgentJobFailedEventSubscriber($sink, $logger);

        $message = new ExtensionAgentJobMessage(
            handlerId: 'observational_memory.observe_boundary',
            payload: [
                'run_id' => 'run-abc',
                'session_id' => 'session-should-not-leak',
                'secret_prompt' => 'never-include',
            ],
            jobId: 'job-1',
            correlationId: 'corr-should-not-leak',
        );
        $envelope = (new Envelope($message))->with(new RedeliveryStamp(1));
        $event = new WorkerMessageFailedEvent(
            $envelope,
            'extension_agent',
            new TransportException('[account_secret_code]: sensitive provider error with stack'),
        );

        $subscriber->onWorkerMessageFailed($event);

        $this->assertCount(1, $sink->events);
        $emitted = $sink->events[0];
        $this->assertSame(RuntimeEventTypeEnum::ExtensionAgentJobFailed->value, $emitted->type);
        $this->assertSame('run-abc', $emitted->runId);
        $this->assertSame(0, $emitted->seq);
        $this->assertSame('[account_secret_code]: sensitive provider error with stack', $emitted->payload['message']);
        $this->assertSame('retry_exhausted', $emitted->payload['reason']);
        $this->assertSame('observational_memory.observe_boundary', $emitted->payload['handler_id']);
        $this->assertSame('job-1', $emitted->payload['job_id']);
        $this->assertSame(1, $emitted->payload['retry_count']);
        $this->assertSame(2, $emitted->payload['attempts']);

        $encoded = json_encode($emitted->toArray(), \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('session-should-not-leak', $encoded);
        $this->assertStringNotContainsString('never-include', $encoded);
        $this->assertStringNotContainsString('corr-should-not-leak', $encoded);
        $this->assertArrayNotHasKey('session_id', $emitted->payload);
        $this->assertArrayNotHasKey('exception', $emitted->payload);
        $this->assertArrayNotHasKey('exception_class', $emitted->payload);
        $this->assertSame([], $logger->records);
    }

    #[Test]
    public function wrappedFailureEmitsUnderlyingProviderError(): void
    {
        $sink = new CollectingRuntimeEventSink();
        $logger = new TestLogger();
        $subscriber = new ExtensionAgentJobFailedEventSubscriber($sink, $logger);
        $message = new ExtensionAgentJobMessage(
            handlerId: 'observational_memory.observe_boundary',
            payload: ['run_id' => 'run-usage-limit'],
            jobId: 'job-usage-limit',
        );
        $providerFailure = new \RuntimeException(
            '[usage_limit_reached/insufficient_quota]: RAW_PROVIDER_ACCOUNT_DETAIL',
        );
        $wrapped = new HandlerFailedException(new Envelope($message), [
            ExtensionAgentJobWorker::class => $providerFailure,
        ]);

        $subscriber->onWorkerMessageFailed(new WorkerMessageFailedEvent(
            new Envelope($message),
            'extension_agent',
            $wrapped,
        ));

        $this->assertCount(1, $sink->events);
        $payload = $sink->events[0]->payload;
        $this->assertSame('retry_exhausted', $payload['reason']);
        $this->assertSame(
            '[usage_limit_reached/insufficient_quota]: RAW_PROVIDER_ACCOUNT_DETAIL',
            $payload['message'],
        );
        $this->assertSame([], $logger->records);
    }

    #[Test]
    public function retryingFailureEmitsNothing(): void
    {
        $sink = new CollectingRuntimeEventSink();
        $logger = new TestLogger();
        $subscriber = new ExtensionAgentJobFailedEventSubscriber($sink, $logger);

        $message = new ExtensionAgentJobMessage(
            handlerId: 'h',
            payload: ['run_id' => 'run-abc'],
            jobId: 'job-1',
        );
        $event = new WorkerMessageFailedEvent(
            new Envelope($message),
            'extension_agent',
            new \RuntimeException('temporary'),
        );
        $event->setForRetry();

        $subscriber->onWorkerMessageFailed($event);

        $this->assertSame([], $sink->events);
        $this->assertSame([], $logger->records);
    }

    #[Test]
    public function missingRunIdLogsOnlyWithoutTuiEvent(): void
    {
        $sink = new CollectingRuntimeEventSink();
        $logger = new TestLogger();
        $subscriber = new ExtensionAgentJobFailedEventSubscriber($sink, $logger);

        $message = new ExtensionAgentJobMessage(
            handlerId: 'h',
            payload: ['session_id' => 'session-only-no-run'],
            jobId: 'job-2',
        );
        $event = new WorkerMessageFailedEvent(
            new Envelope($message),
            'extension_agent',
            new \RuntimeException('boom'),
        );

        $subscriber->onWorkerMessageFailed($event);

        $this->assertSame([], $sink->events);
        $this->assertCount(1, $logger->records);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame('extension_agent.job_failed.missing_run_id', $logger->records[0]['message']);
        $this->assertSame('h', $logger->records[0]['context']['handler_id'] ?? null);
        $this->assertSame('job-2', $logger->records[0]['context']['job_id'] ?? null);
        $this->assertSame('RuntimeException', $logger->records[0]['context']['exception_class'] ?? null);
        $encoded = json_encode($logger->records[0], \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('boom', $encoded);
        $this->assertStringNotContainsString('session-only-no-run', $encoded);
    }

    #[Test]
    public function emptyRunIdAndNonExtensionReceiverAreIgnoredForEmission(): void
    {
        $sink = new CollectingRuntimeEventSink();
        $logger = new TestLogger();
        $subscriber = new ExtensionAgentJobFailedEventSubscriber($sink, $logger);

        $emptyRun = new ExtensionAgentJobMessage(
            handlerId: 'h',
            payload: ['run_id' => '   '],
            jobId: 'job-3',
        );
        $subscriber->onWorkerMessageFailed(new WorkerMessageFailedEvent(
            new Envelope($emptyRun),
            'extension_agent',
            new \RuntimeException('x'),
        ));

        $otherReceiver = new ExtensionAgentJobMessage(
            handlerId: 'h',
            payload: ['run_id' => 'run-abc'],
            jobId: 'job-4',
        );
        $subscriber->onWorkerMessageFailed(new WorkerMessageFailedEvent(
            new Envelope($otherReceiver),
            'run_control',
            new \RuntimeException('x'),
        ));

        $nonMessage = new WorkerMessageFailedEvent(
            new Envelope(new \stdClass()),
            'extension_agent',
            new \RuntimeException('x'),
        );
        $subscriber->onWorkerMessageFailed($nonMessage);

        $this->assertSame([], $sink->events);
        $this->assertCount(1, $logger->records, 'only empty run_id logs');
    }
}

/**
 * @internal
 */
final class CollectingRuntimeEventSink implements RuntimeEventSinkInterface
{
    /** @var list<RuntimeEvent> */
    public array $events = [];

    public function emit(RuntimeEvent $event): void
    {
        $this->events[] = $event;
    }
}
