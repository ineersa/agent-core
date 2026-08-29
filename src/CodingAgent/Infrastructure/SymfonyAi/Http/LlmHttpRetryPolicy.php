<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http;

use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;

final class LlmHttpRetryPolicy
{
    public const int DEFAULT_TIMEOUT = 30;
    public const int DEFAULT_MAX_DURATION = 120;
    public const int DEFAULT_MAX_RETRIES = 2;
    public const int DEFAULT_BASE_DELAY_MS = 1_000;
    public const int DEFAULT_MAX_DELAY_MS = 60_000;

    private const array RETRYABLE_STATUS_CODES = [0, 408, 425, 429, 500, 502, 503, 504];

    public readonly int $timeout;
    public readonly int $maxDuration;
    public readonly int $maxRetries;
    public readonly int $baseDelayMs;
    public readonly int $maxDelayMs;

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

    public function retryStrategy(): GenericRetryStrategy
    {
        return new GenericRetryStrategy(
            statusCodes: self::RETRYABLE_STATUS_CODES,
            delayMs: $this->baseDelayMs,
            multiplier: 2.0,
            maxDelayMs: $this->maxDelayMs,
        );
    }

    /** @return array<string, mixed> */
    public function httpClientOptions(): array
    {
        return [
            'timeout' => $this->timeout,
            'max_duration' => $this->maxDuration,
        ];
    }

    private static function validatePositive(?int $value, string $name): ?int
    {
        if (null !== $value && $value <= 0) {
            throw new \InvalidArgumentException(\sprintf('%s must be a positive integer, got %d', $name, $value));
        }

        return $value;
    }

    private static function validateNonNegative(?int $value, string $name): ?int
    {
        if (null !== $value && $value < 0) {
            throw new \InvalidArgumentException(\sprintf('%s must be a non-negative integer, got %d', $name, $value));
        }

        return $value;
    }
}
