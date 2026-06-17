<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http;

/**
 * Retry policy for LLM HTTP provider requests.
 *
 * Controls timeouts, max duration, retry counts, backoff delays,
 * retryable-error classification, and Retry-After header parsing.
 *
 * All values come from Hatfield settings (`ai.http`); explicit constructor
 * arguments (used by tests and the factory) win over defaults.
 * Test-only overrides use the `env:` syntax in settings, not environment
 * variables set directly on the process.
 */
final class LlmHttpRetryPolicy
{
    public const int DEFAULT_TIMEOUT = 30;
    public const int DEFAULT_MAX_DURATION = 120;
    public const int DEFAULT_MAX_RETRIES = 2;
    public const int DEFAULT_BASE_DELAY_MS = 1_000;
    public const int DEFAULT_MAX_DELAY_MS = 60_000;

    /** Retryable HTTP status codes for LLM endpoints. */
    private const array RETRYABLE_STATUS_CODES = [408, 425, 429, 500, 502, 503, 504];

    /** Retry patterns in error text (transient/overload/network). */
    private const string RETRYABLE_ERROR_PATTERN = '/overloaded|service\s+unavailable|upstream\s+connect|connection\s+refused|please\s+try\s+again/i';

    /** Terminal billing/quota patterns — never retry these. */
    private const string TERMINAL_BILLING_PATTERN = '/insufficient_quota|quota\s+exceeded|billing|monthly\s+usage\s+limit|out\s+of\s+budget|available\s+balance|GoUsageLimitError|FreeUsageLimitError/i';

    /** Transport/network error patterns in exception messages. */
    private const string TRANSPORT_ERROR_PATTERN = '/timeout|timed\s+out|connection\s+refused|connection\s+reset|broken\s+pipe|could\s+not\s+resolve|cannot\s+connect|network\s+is\s+unreachable|stream\s+ended\s+before|read\s+error/i';

    public readonly int $timeout;
    public readonly int $maxDuration;
    public readonly int $maxRetries;
    public readonly int $baseDelayMs;
    public readonly int $maxDelayMs;

    /**
     * @param int|null $timeout     Per-request timeout in seconds (default 30)
     * @param int|null $maxDuration Total request duration budget in seconds (default 120)
     * @param int|null $maxRetries  Max retry attempts before failing (default 2, 0=no retries)
     * @param int|null $baseDelayMs Base retry backoff delay in milliseconds (default 1000)
     * @param int|null $maxDelayMs  Maximum delay for any single retry in ms (default 60000)
     */
    public function __construct(
        ?int $timeout = null,
        ?int $maxDuration = null,
        ?int $maxRetries = null,
        ?int $baseDelayMs = null,
        ?int $maxDelayMs = null,
    ) {
        $this->timeout = self::validatePositive($timeout, 'timeout') ?? self::DEFAULT_TIMEOUT;
        $this->maxDuration = self::validatePositive($maxDuration, 'maxDuration') ?? self::DEFAULT_MAX_DURATION;
        $this->maxRetries = self::validateNonNegative($maxRetries, 'maxRetries') ?? self::DEFAULT_MAX_RETRIES;
        $this->baseDelayMs = self::validateNonNegative($baseDelayMs, 'baseDelayMs') ?? self::DEFAULT_BASE_DELAY_MS;
        $this->maxDelayMs = self::validateNonNegative($maxDelayMs, 'maxDelayMs') ?? self::DEFAULT_MAX_DELAY_MS;
    }

    /**
     * Determine whether an HTTP response status code indicates a retryable error.
     *
     * Terminal billing/quota errors (429 with billing body) are NOT retryable.
     */
    public function isRetryableError(int $statusCode, ?string $responseBody): bool
    {
        if ($this->isTerminalBillingError($statusCode, $responseBody)) {
            return false;
        }

        if (\in_array($statusCode, self::RETRYABLE_STATUS_CODES, true)) {
            return true;
        }

        if (null !== $responseBody && '' !== $responseBody) {
            return (bool) preg_match(self::RETRYABLE_ERROR_PATTERN, $responseBody);
        }

        return false;
    }

    /**
     * Check whether a 429 error response is a terminal billing/quota error
     * that should NOT be retried.
     */
    public function isTerminalBillingError(int $statusCode, ?string $responseBody): bool
    {
        if (429 !== $statusCode || null === $responseBody || '' === $responseBody) {
            return false;
        }

        return (bool) preg_match(self::TERMINAL_BILLING_PATTERN, $responseBody);
    }

    /**
     * Check whether a transport-level exception (timeout, connection reset, DNS failure)
     * is a retryable network error.
     */
    public function isRetryableTransportError(\Throwable $exception): bool
    {
        return (bool) preg_match(self::TRANSPORT_ERROR_PATTERN, $exception->getMessage());
    }

    /**
     * Parse the Retry-After delay from HTTP response headers.
     *
     * Supports, in priority order:
     *   - retry-after-ms (OpenAI custom header, integer milliseconds)
     *   - retry-after (standard header — integer seconds or HTTP-date)
     *
     * Returns the delay in milliseconds, or null if no retry-after header is present.
     *
     * @param array<string, list<string>> $headers Headers from Symfony HttpClient
     */
    public function parseRetryAfterMs(array $headers): ?int
    {
        // 1. retry-after-ms (OpenAI custom header)
        $ms = self::findHeader($headers, 'retry-after-ms');
        if (null !== $ms && '' !== $ms) {
            $value = (int) $ms;
            if ($value > 0) {
                return $value;
            }
        }

        // 2. retry-after (standard: seconds or HTTP date)
        $retryAfter = self::findHeader($headers, 'retry-after');
        if (null === $retryAfter || '' === $retryAfter) {
            return null;
        }

        $trimmed = trim($retryAfter);

        // Try integer seconds
        if (ctype_digit($trimmed)) {
            return (int) $trimmed * 1000;
        }

        // Try HTTP-date (RFC 1123)
        try {
            $date = new \DateTimeImmutable($trimmed);
            $now = new \DateTimeImmutable('now');
            $diff = (int) ($date->format('U') - $now->format('U'));

            return $diff > 0 ? $diff * 1000 : 0;
        } catch (\Exception) {
            // Not a valid date
        }

        return null;
    }

    /**
     * Calculate the delay before the next retry attempt.
     *
     * Uses the server-provided retry-after delay (capped to maxDelayMs) when available,
     * otherwise exponential backoff: baseDelayMs * 2^attempt, capped to maxDelayMs.
     *
     * @param int      $attempt      0-based attempt index
     * @param int|null $retryAfterMs Server-requested delay in ms, or null
     *
     * @return int Delay in milliseconds
     */
    public function calculateDelayMs(int $attempt, ?int $retryAfterMs): int
    {
        if (null !== $retryAfterMs && $retryAfterMs > 0) {
            return min($retryAfterMs, $this->maxDelayMs);
        }

        // Exponential backoff
        $delay = (int) ($this->baseDelayMs * (2 ** $attempt));

        return min($delay, $this->maxDelayMs);
    }

    /**
     * Build default HttpClient create options from this policy.
     *
     * @return array<string, mixed>
     */
    public function httpClientOptions(): array
    {
        return [
            'timeout' => $this->timeout,
            'max_duration' => $this->maxDuration,
        ];
    }

    // ── Internal helpers ──────────────────────────────────────────────────

    /**
     * Validate a positive integer (> 0) and return it, or return null
     * when the value is null (caller applies the default).
     */
    private static function validatePositive(?int $value, string $name): ?int
    {
        if (null !== $value) {
            if ($value <= 0) {
                throw new \InvalidArgumentException(\sprintf('%s must be a positive integer, got %d', $name, $value));
            }

            return $value;
        }

        return null;
    }

    /**
     * Validate a non-negative integer (>= 0) and return it, or return null
     * when the value is null (caller applies the default).
     */
    private static function validateNonNegative(?int $value, string $name): ?int
    {
        if (null !== $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException(\sprintf('%s must be a non-negative integer, got %d', $name, $value));
            }

            return $value;
        }

        return null;
    }

    /**
     * Find a header value by case-insensitive key.
     *
     * Symfony's $response->getHeaders(false) returns an array keyed by lowercase.
     *
     * @param array<string, list<string>> $headers Headers from Symfony HttpClient
     */
    private static function findHeader(array $headers, string $key): ?string
    {
        $lower = strtolower($key);

        return isset($headers[$lower][0]) && '' !== $headers[$lower][0]
            ? $headers[$lower][0]
            : null;
    }
}
