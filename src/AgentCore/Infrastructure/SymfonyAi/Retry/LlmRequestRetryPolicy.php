<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi\Retry;

/**
 * Application-level retry budget for one LLM platform invocation.
 *
 * Defaults match the product requirement: five retries beyond the initial
 * attempt (six attempts total). Delay uses exponential backoff.
 */
final class LlmRequestRetryPolicy
{
    public const int DEFAULT_MAX_RETRIES = 5;
    public const int DEFAULT_BASE_DELAY_MS = 1_000;
    public const int DEFAULT_MAX_DELAY_MS = 60_000;

    public readonly int $maxRetries;
    public readonly int $baseDelayMs;
    public readonly int $maxDelayMs;

    public function __construct(
        ?int $maxRetries = null,
        ?int $baseDelayMs = null,
        ?int $maxDelayMs = null,
    ) {
        $this->maxRetries = self::validateNonNegative($maxRetries, 'maxRetries') ?? self::DEFAULT_MAX_RETRIES;
        $this->baseDelayMs = self::validateNonNegative($baseDelayMs, 'baseDelayMs') ?? self::DEFAULT_BASE_DELAY_MS;
        $this->maxDelayMs = self::validateNonNegative($maxDelayMs, 'maxDelayMs') ?? self::DEFAULT_MAX_DELAY_MS;
    }

    public function maxAttempts(): int
    {
        return $this->maxRetries + 1;
    }

    public function canRetry(int $attemptIndex): bool
    {
        return $attemptIndex < $this->maxRetries;
    }

    public function delayMsForAttempt(int $attemptIndex): int
    {
        $delay = (int) round($this->baseDelayMs * (2 ** max(0, $attemptIndex)));

        return min(max(0, $delay), $this->maxDelayMs);
    }

    private static function validateNonNegative(?int $value, string $name): ?int
    {
        if (null !== $value && $value < 0) {
            throw new \InvalidArgumentException(\sprintf('%s must be a non-negative integer, got %d', $name, $value));
        }

        return $value;
    }
}
