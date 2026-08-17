<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * RetryStrategyInterface adapter that maps Hatfield's LLM retry policy onto
 * Symfony HttpClient's native RetryableHttpClient orchestration.
 *
 * Symfony owns the attempt loop, cancel-before-retry, and cancellable pauses
 * ($context->pause()); this class only decides whether to retry and how long
 * to wait:
 *
 *   - first chunk (no body buffered yet): success statuses pass through,
 *     error statuses return null so Symfony buffers the body for
 *     classification;
 *   - body received: policy->isRetryableError() (status codes plus terminal
 *     billing/quota and transient body patterns);
 *   - transport exception: policy->isRetryableTransportError(); terminal
 *     billing/quota text in the exception is never retried;
 *   - delay: policy->calculateDelayMs() with the retry-after-ms header when
 *     present, otherwise exponential backoff. Symfony itself consumes the
 *     standard Retry-After header before this strategy's getDelay() is
 *     consulted, so only the OpenAI-style retry-after-ms header is handled
 *     here.
 *
 * Structured retry logging is privacy-safe: status codes, attempt counts and
 * delay values only — never bodies, headers, prompts, or credentials.
 */
final class LlmHttpRetryStrategy implements RetryStrategyInterface
{
    public function __construct(
        private readonly LlmHttpRetryPolicy $policy,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?string $providerId = null,
    ) {
    }

    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): ?bool
    {
        if (null !== $exception) {
            // Terminal billing/quota in transport error text — never retry.
            if ($this->policy->isTerminalBillingError(429, $exception->getMessage())) {
                return false;
            }

            return $this->policy->isRetryableTransportError($exception);
        }

        // No body yet: pass through success; buffer the body for classification.
        if (null === $responseContent) {
            return $context->getStatusCode() >= 400 ? null : false;
        }

        return $this->policy->isRetryableError($context->getStatusCode(), $responseContent);
    }

    public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
    {
        // retry_count is 0-based: the initial attempt is 0, each retry +1.
        $attempt = (int) ($context->getInfo('retry_count') ?? 0);
        $retryAfterMs = $this->policy->parseRetryAfterMs($context->getHeaders());
        $delayMs = $this->policy->calculateDelayMs($attempt, $retryAfterMs);

        $this->logger->info('llm.http.retry', $this->logContext($context, $exception, $attempt, $delayMs, $retryAfterMs));

        return $delayMs;
    }

    /**
     * Privacy-safe retry log fields: no bodies, headers, prompts, or credentials.
     *
     * @return array<string, mixed>
     */
    private function logContext(AsyncContext $context, ?TransportExceptionInterface $exception, int $attempt, int $delayMs, ?int $retryAfterMs): array
    {
        $fields = [
            'event_type' => 'llm.http.retry',
            'component' => 'llm_http',
            'provider_id' => $this->providerId,
            'attempt' => $attempt + 1,
            'max_retries' => $this->policy->maxRetries,
            'delay_ms' => $delayMs,
            'retry_after_ms' => $retryAfterMs,
            'method' => $context->getInfo('http_method'),
        ];

        if (null !== $exception) {
            $fields['error_type'] = $exception::class;
        } else {
            $fields['http_status_code'] = $context->getStatusCode();
        }

        return $fields;
    }
}
