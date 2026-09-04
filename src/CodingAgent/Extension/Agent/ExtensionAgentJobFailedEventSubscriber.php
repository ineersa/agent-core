<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Agent;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmProviderErrorClassifier;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Emits a sanitized TUI/runtime event when an extension_agent job permanently fails.
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
 * Payload is privacy-safe: known failure classes and allowlisted provider
 * codes select fixed reasons/messages. Unknown failures keep the generic
 * fallback. No exception message, tool output, prompt, or raw extension
 * payload is emitted.
 */
final readonly class ExtensionAgentJobFailedEventSubscriber implements EventSubscriberInterface
{
    private const string RECEIVER = 'extension_agent';

    private const string SAFE_MESSAGE = 'Extension background job failed after retrying.';

    private const string FALLBACK_REASON = 'retry_exhausted';

    /** @var array<string, string> */
    private const array SAFE_PROVIDER_REASON_MESSAGES = [
        'usage_limit_reached' => 'LLM provider usage limit reached. Check your plan or billing.',
    ];

    /** @var list<string> */
    private const array ACTIONABLE_CATEGORIES = [
        LlmProviderErrorClassifier::CATEGORY_AUTH,
        LlmProviderErrorClassifier::CATEGORY_BAD_REQUEST,
        LlmProviderErrorClassifier::CATEGORY_RATE_LIMIT,
        LlmProviderErrorClassifier::CATEGORY_SERVER,
        LlmProviderErrorClassifier::CATEGORY_TIMEOUT,
        LlmProviderErrorClassifier::CATEGORY_NETWORK,
    ];

    public function __construct(
        private RuntimeEventSinkInterface $stdoutSink,
        private LoggerInterface $logger,
        private LlmProviderErrorClassifier $errorClassifier = new LlmProviderErrorClassifier(),
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
        $failure = $this->classifyFailure($event->getThrowable());

        if (null === $runId) {
            $this->logger->error('extension_agent.job_failed.missing_run_id', [
                'component' => 'extension_agent',
                'event_type' => 'extension_agent.job_failed.missing_run_id',
                'handler_id' => $message->handlerId,
                'job_id' => $message->jobId,
                'retry_count' => $retryCount,
                'attempts' => $attempts,
                'reason' => $failure['reason'] ?? self::FALLBACK_REASON,
                // Privacy: exception class only — never message/stack/prompts.
                'exception_class' => $event->getThrowable()::class,
            ]);

            return;
        }

        $payload = [
            'message' => null === $failure ? self::SAFE_MESSAGE : self::SAFE_MESSAGE.' '.$failure['message'],
            'reason' => $failure['reason'] ?? self::FALLBACK_REASON,
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

    /** @return array{reason: string, message: string}|null */
    private function classifyFailure(\Throwable $throwable): ?array
    {
        $throwables = iterator_to_array($this->throwables($throwable), false);

        foreach ($throwables as $candidate) {
            $providerReason = $this->safeProviderReason($candidate->getMessage());
            if (null !== $providerReason) {
                return [
                    'reason' => $providerReason,
                    'message' => self::SAFE_PROVIDER_REASON_MESSAGES[$providerReason],
                ];
            }
        }

        foreach ($throwables as $candidate) {
            $classified = $this->errorClassifier->classify(['type' => $candidate::class]);
            $category = $classified['error_category'] ?? null;
            $userMessage = $classified['user_message'] ?? null;
            if (!\is_string($category)
                || !\in_array($category, self::ACTIONABLE_CATEGORIES, true)
                || !\is_string($userMessage)
                || '' === trim($userMessage)
            ) {
                continue;
            }

            return [
                'reason' => $category,
                'message' => $userMessage,
            ];
        }

        return null;
    }

    private function safeProviderReason(string $message): ?string
    {
        if (1 > preg_match_all('~\[([a-zA-Z0-9_.-]+(?:/[a-zA-Z0-9_.-]+){0,2})]~', $message, $matches)) {
            return null;
        }

        foreach ($matches[1] as $fields) {
            foreach (explode('/', $fields) as $field) {
                if (isset(self::SAFE_PROVIDER_REASON_MESSAGES[$field])) {
                    return $field;
                }
            }
        }

        return null;
    }

    /** @return iterable<\Throwable> */
    private function throwables(\Throwable $throwable): iterable
    {
        yield $throwable;

        if ($throwable instanceof WrappedExceptionsInterface) {
            foreach ($throwable->getWrappedExceptions(null, true) as $wrapped) {
                yield $wrapped;
            }
        }

        $previous = $throwable->getPrevious();
        while (null !== $previous) {
            yield $previous;
            $previous = $previous->getPrevious();
        }
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
