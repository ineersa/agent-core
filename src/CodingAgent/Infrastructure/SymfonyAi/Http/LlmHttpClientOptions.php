<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http;

/**
 * Timeout options for outbound LLM HTTP clients.
 *
 * Retry count and backoff live on {@see \Ineersa\AgentCore\Infrastructure\SymfonyAi\Retry\LlmRequestRetryPolicy}.
 * This type only supplies Symfony HttpClient `timeout` / `max_duration`.
 */
final class LlmHttpClientOptions
{
    public const int DEFAULT_TIMEOUT = 30;
    public const int DEFAULT_MAX_DURATION = 120;

    public readonly int $timeout;
    public readonly int $maxDuration;

    public function __construct(
        ?int $timeout = null,
        ?int $maxDuration = null,
    ) {
        $this->timeout = self::validatePositive($timeout, 'timeout') ?? self::DEFAULT_TIMEOUT;
        $this->maxDuration = self::validatePositive($maxDuration, 'maxDuration') ?? self::DEFAULT_MAX_DURATION;
    }

    /** @return array{timeout: int, max_duration: int} */
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
}
