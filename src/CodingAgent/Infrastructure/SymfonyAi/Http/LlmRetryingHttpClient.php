<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Decorating HttpClient that retries LLM provider requests on transient errors.
 *
 * Wraps a real HttpClient, retries on 429/5xx and transport-level errors using
 * an {@see LlmHttpRetryPolicy} for classification and delay calculation.
 * Respects Retry-After headers and detects terminal billing/quota errors.
 *
 * The decorator sits between {@see EventSourceHttpClient} (or any caller)
 * and the base {@see HttpClient::create()}.  It checks the response status
 * code synchronously before returning the response for streaming — errors
 * at the HTTP level (before SSE body starts) are retried; mid-stream errors
 * are NOT retried (the caller's stream loop handles those).
 *
 * Structured logging of retry attempts uses privacy-safe fields only:
 * no raw prompts, API keys, tool output, or full response bodies.
 */
final class LlmRetryingHttpClient implements HttpClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private readonly LlmHttpRetryPolicy $policy,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?string $providerId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        // Merge policy defaults for timeouts if not explicitly overridden
        // by the caller (e.g. EventSourceHttpClient's own options).
        if (!isset($options['timeout'])) {
            $options['timeout'] = $this->policy->timeout;
        }
        if (!isset($options['max_duration'])) {
            $options['max_duration'] = $this->policy->maxDuration;
        }

        $lastException = null;
        $maxAttempts = $this->policy->maxRetries + 1; // +1 for the initial attempt

        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
            try {
                $response = $this->httpClient->request($method, $url, $options);

                // Check the response status code.  For SSE streaming this
                // reads only headers — the body stream is NOT consumed here.
                $statusCode = $response->getStatusCode();

                // Success or non-retryable status: return directly.
                if ($statusCode < 400) {
                    return $response;
                }

                // Read response body (error payload) for classification.
                // This is safe because we're on the error path and will
                // either return the error response or retry with a new request.
                $body = $this->readErrorBody($response);

                // Terminal billing/quota — never retry.
                if ($this->policy->isTerminalBillingError($statusCode, $body)) {
                    return $response;
                }

                // Last attempt or not retryable: return the error response.
                if ($attempt >= $this->policy->maxRetries || !$this->policy->isRetryableError($statusCode, $body)) {
                    return $response;
                }

                // Retry with delay.
                $retryAfterMs = $this->policy->parseRetryAfterMs($this->safeGetHeaders($response));
                $delayMs = $this->policy->calculateDelayMs($attempt, $retryAfterMs);

                $this->logger->info('llm.http.retry', [
                    'event_type' => 'llm.http.retry',
                    'component' => 'llm_http',
                    'provider_id' => $this->providerId,
                    'http_status_code' => $statusCode,
                    'attempt' => $attempt + 1,
                    'max_retries' => $this->policy->maxRetries,
                    'delay_ms' => $delayMs,
                    'retry_after_ms' => $retryAfterMs,
                    'method' => $method,
                ]);

                // Cancel the failed response before the next attempt.
                $response->cancel();

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            } catch (\Throwable $exception) {
                $lastException = $exception;
                $message = $exception->getMessage();

                // Terminal billing/quota in transport error text.
                if ($this->policy->isTerminalBillingError(429, $message)) {
                    throw $exception;
                }

                // Last attempt or not a retryable transport error: rethrow.
                if ($attempt >= $this->policy->maxRetries || !$this->policy->isRetryableTransportError($exception)) {
                    throw $exception;
                }

                $this->logger->info('llm.http.retry', [
                    'event_type' => 'llm.http.retry',
                    'component' => 'llm_http',
                    'provider_id' => $this->providerId,
                    'error_type' => $exception::class,
                    'attempt' => $attempt + 1,
                    'max_retries' => $this->policy->maxRetries,
                    'method' => $method,
                ]);

                $delayMs = $this->policy->calculateDelayMs($attempt, null);
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        // Should not reach here because the last iteration either returns
        // or throws.  Defensive fallback:
        throw $lastException ?? new \RuntimeException(\sprintf('Request to %s %s failed after %d attempt(s).', $method, $url, $maxAttempts));
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->httpClient->stream($responses, $timeout);
    }

    /**
     * Return a new instance with the given options merged into the inner client.
     *
     * Retry behavior, policy, logger, and provider identity are preserved.
     *
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->httpClient = $this->httpClient->withOptions($options);

        return $clone;
    }

    /**
     * Safely read the error response body for classification.
     *
     * Returns null if the body cannot be read (e.g. cancelled, already consumed,
     * or non-repeatable stream).
     */
    private function readErrorBody(ResponseInterface $response): ?string
    {
        try {
            $body = $response->getContent(false);

            return '' !== $body ? $body : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Safely get response headers for Retry-After parsing.
     *
     * @return array<string, list<string>>
     */
    private function safeGetHeaders(ResponseInterface $response): array
    {
        try {
            return $response->getHeaders(false);
        } catch (\Throwable) {
            return [];
        }
    }
}
