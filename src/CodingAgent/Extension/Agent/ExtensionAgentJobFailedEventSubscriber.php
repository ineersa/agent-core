<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Agent;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Emits a TUI/runtime event when an extension_agent job permanently fails.
 *
 * Scope is intentionally narrow:
 * - receiver `extension_agent` only
 * - message {@see ExtensionAgentJobMessage} only
 * - final failure only (`willRetry() === false`)
 *
 * Correlation uses only a validated non-empty scalar `payload['run_id']`.
 * There is no session/env fallback. Missing run_id produces a structured
 * diagnostic log only — never a TUI event and never a main-run failure.
 *
 * The payload uses the underlying extension handler exception message so the
 * TUI shows the failure the extension received.
 */
final readonly class ExtensionAgentJobFailedEventSubscriber implements EventSubscriberInterface
{
    private const string RECEIVER = 'extension_agent';

    private const string REASON = 'retry_exhausted';

    public function __construct(
        private RuntimeEventSinkInterface $stdoutSink,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onWorkerMessageFailed',
        ];
    }

    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        if (self::RECEIVER !== $event->getReceiverName()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof ExtensionAgentJobMessage) {
            return;
        }

        $retryCount = RedeliveryStamp::getRetryCountFromEnvelope($event->getEnvelope());
        // attempts = initial delivery + redeliveries (max_retries: 1 ⇒ attempts 2 when exhausted).
        $attempts = $retryCount + 1;
        $runId = $this->validatedRunId($message->payload);
        $failure = $this->unwrapFailure($event->getThrowable());

        if (null === $runId) {
            $this->logger->error('extension_agent.job_failed.missing_run_id', [
                'component' => 'extension_agent',
                'event_type' => 'extension_agent.job_failed.missing_run_id',
                'handler_id' => $message->handlerId,
                'job_id' => $message->jobId,
                'retry_count' => $retryCount,
                'attempts' => $attempts,
                'reason' => self::REASON,
                'exception_class' => $failure::class,
            ]);

            return;
        }

        $payload = [
            'message' => $failure->getMessage(),
            'reason' => self::REASON,
            'handler_id' => $message->handlerId,
            'job_id' => $message->jobId,
            'retry_count' => $retryCount,
            'attempts' => $attempts,
        ];

        try {
            $this->stdoutSink->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ExtensionAgentJobFailed->value,
                runId: $runId,
                seq: 0,
                payload: $payload,
            ));
        } catch (\Throwable $e) {
            // Never let emission failures break Messenger failure middleware.
            $this->logger->error('extension_agent.job_failed.emit_failed', [
                'component' => 'extension_agent',
                'event_type' => 'extension_agent.job_failed.emit_failed',
                'run_id' => $runId,
                'handler_id' => $message->handlerId,
                'job_id' => $message->jobId,
                'exception_class' => $e::class,
            ]);
        }
    }

    private function unwrapFailure(\Throwable $throwable): \Throwable
    {
        if (!$throwable instanceof WrappedExceptionsInterface) {
            return $throwable;
        }

        $wrapped = $throwable->getWrappedExceptions(null, true);
        $firstKey = array_key_first($wrapped);

        return null !== $firstKey ? $wrapped[$firstKey] : $throwable;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validatedRunId(array $payload): ?string
    {
        if (!\array_key_exists('run_id', $payload)) {
            return null;
        }

        $runId = $payload['run_id'];
        if (!\is_string($runId) && !\is_int($runId)) {
            return null;
        }

        $normalized = trim((string) $runId);

        return '' !== $normalized ? $normalized : null;
    }
}
