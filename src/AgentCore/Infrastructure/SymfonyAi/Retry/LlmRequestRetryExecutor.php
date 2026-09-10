<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi\Retry;

use Ineersa\AgentCore\Contract\Hook\LlmRequestRetryObserverInterface;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmProviderErrorClassifier;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Retries a platform invocation when the classifier marks the error retryable.
 *
 * Partial failed attempts are discarded: only the final attempt's result is
 * returned. Cancellation checks happen before each retry delay and attempt.
 * Backoff deadlines use the injected clock so MockClock tests can advance time.
 */
final class LlmRequestRetryExecutor
{
    public function __construct(
        private readonly LlmRequestRetryPolicy $policy = new LlmRequestRetryPolicy(),
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly ?LlmRequestRetryObserverInterface $retryObserver = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param callable(): PlatformInvocationResult $invoke
     * @param callable(): bool                     $isCancelled
     */
    public function execute(callable $invoke, callable $isCancelled, string $runId, ?string $stepId): PlatformInvocationResult
    {
        $maxAttempts = $this->policy->maxAttempts();
        $maxRetries = $this->policy->maxRetries;
        $attempt = 0;
        $lastResult = null;

        while (true) {
            // Do not poll cancellation before the first attempt: stream/status
            // accounting belongs to the invoke path. Check cancellation only
            // between attempts (after a failed result) and during backoff.
            if (null !== $lastResult && $isCancelled()) {
                return $this->cancelledResult($lastResult);
            }

            $result = $invoke();
            $lastResult = $result;

            if (null === $result->error) {
                return $result;
            }

            if (true !== ($result->error['retryable'] ?? false)) {
                return $this->withAttemptMetadata($result, $attempt, $maxAttempts, exhausted: false);
            }

            if (!$this->policy->canRetry($attempt)) {
                return $this->withAttemptMetadata($result, $attempt, $maxAttempts, exhausted: true);
            }

            $delayMs = $this->policy->delayMsForAttempt($attempt);
            $reason = $this->reason($result->error);
            $category = \is_string($result->error['error_category'] ?? null)
                ? $result->error['error_category']
                : LlmProviderErrorClassifier::CATEGORY_PROVIDER;
            $errorType = \is_string($result->error['type'] ?? null)
                ? $result->error['type']
                : 'unknown';

            // attempt/max_attempts are the retry index and retry budget (1/5), not
            // total platform invocations (which would be 1/6 with default five retries).
            $retryPayload = [
                'attempt' => $attempt + 1,
                'max_attempts' => $maxRetries,
                'delay_ms' => $delayMs,
                'reason' => $reason,
                'error_category' => $category,
                'error_type' => $errorType,
            ];

            $this->logger->warning('llm.request.retrying', [
                'run_id' => $runId,
                'session_id' => $runId,
                'component' => 'llm',
                'event_type' => 'llm.request.retrying',
                'step_id' => $stepId,
                ...$retryPayload,
            ]);

            if (null !== $this->retryObserver && '' !== $runId) {
                try {
                    $this->retryObserver->onRequestRetry($runId, $stepId, $retryPayload);
                } catch (\Throwable $exception) {
                    $this->logger->warning('llm.request.retry_observer_failed', [
                        'run_id' => $runId,
                        'session_id' => $runId,
                        'component' => 'llm',
                        'event_type' => 'llm.request.retry_observer_failed',
                        'step_id' => $stepId,
                        'exception_class' => $exception::class,
                    ]);
                }
            }

            if ($delayMs > 0) {
                $cancelledDuringDelay = $this->sleepUntilDeadline($delayMs, $isCancelled, $result);
                if (null !== $cancelledDuringDelay) {
                    return $cancelledDuringDelay;
                }
            }

            ++$attempt;
        }
    }

    /**
     * @param callable(): bool $isCancelled
     */
    private function sleepUntilDeadline(int $delayMs, callable $isCancelled, PlatformInvocationResult $result): ?PlatformInvocationResult
    {
        $deadlineUs = ((int) $this->clock->now()->format('Uu')) + ($delayMs * 1_000);
        while (((int) $this->clock->now()->format('Uu')) < $deadlineUs) {
            if ($isCancelled()) {
                return $this->cancelledResult($result);
            }
            $remainingUs = $deadlineUs - ((int) $this->clock->now()->format('Uu'));
            $sliceSeconds = min(0.05, max(0, $remainingUs) / 1_000_000);
            if ($sliceSeconds <= 0) {
                break;
            }
            $this->clock->sleep($sliceSeconds);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $error
     */
    private function reason(array $error): string
    {
        if (\is_string($error['user_message'] ?? null) && '' !== $error['user_message']) {
            return $error['user_message'];
        }

        return 'LLM provider request failed.';
    }

    private function withAttemptMetadata(
        PlatformInvocationResult $result,
        int $attemptIndex,
        int $maxAttempts,
        bool $exhausted,
    ): PlatformInvocationResult {
        if (null === $result->error) {
            return $result;
        }

        $error = $result->error;
        // Zero-based failed attempt index and total attempt budget (initial + retries).
        $error['retry_attempt_index'] = $attemptIndex;
        $error['retry_max_attempts'] = $maxAttempts;
        if ($exhausted) {
            $error['retry_exhausted'] = true;
            // Application budget owns retries; do not let Messenger multiply them.
            $error['retryable'] = false;
            $category = \is_string($error['error_category'] ?? null) ? $error['error_category'] : 'provider';
            $error['user_message'] = match ($category) {
                LlmProviderErrorClassifier::CATEGORY_TIMEOUT => 'LLM provider request timed out after retries were exhausted.',
                LlmProviderErrorClassifier::CATEGORY_NETWORK => 'LLM provider transport failed after retries were exhausted.',
                LlmProviderErrorClassifier::CATEGORY_RATE_LIMIT => 'LLM provider rate limit remained active after retries were exhausted.',
                LlmProviderErrorClassifier::CATEGORY_SERVER => 'LLM provider server error remained after retries were exhausted.',
                default => 'LLM provider request failed after retries were exhausted.',
            };
            if (!isset($error['message']) || !\is_string($error['message']) || '' === $error['message']) {
                $error['message'] = $error['user_message'];
            }
        }

        return new PlatformInvocationResult(
            assistantMessage: $result->assistantMessage,
            deltas: $result->deltas,
            usage: $result->usage,
            stopReason: $result->stopReason,
            error: $error,
            model: $result->model,
            reasoning: $result->reasoning,
            modelNotifications: $result->modelNotifications,
            availableTools: $result->availableTools,
            availableToolsSchemaTokensEstimate: $result->availableToolsSchemaTokensEstimate,
        );
    }

    private function cancelledResult(?PlatformInvocationResult $previous): PlatformInvocationResult
    {
        // Cancellation between attempts always follows a failed attempt (success
        // returns immediately). Discard failed partial output; preserve usage /
        // model metadata when available.
        return new PlatformInvocationResult(
            assistantMessage: null,
            deltas: [],
            usage: $previous->usage ?? [],
            stopReason: 'aborted',
            error: null,
            model: $previous->model ?? '',
            reasoning: $previous->reasoning ?? '',
            modelNotifications: $previous->modelNotifications ?? [],
            availableTools: $previous->availableTools ?? [],
            availableToolsSchemaTokensEstimate: $previous->availableToolsSchemaTokensEstimate ?? 0,
        );
    }
}
