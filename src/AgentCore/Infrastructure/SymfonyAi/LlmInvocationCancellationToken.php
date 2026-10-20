<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use DateTimeImmutable;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;

/**
 * Shares bounded cancellation observations across one invocation's stream layers.
 * Execution/result boundaries use refresh() to bypass a cached negative result.
 */
final class LlmInvocationCancellationToken implements CancellationTokenInterface
{
    private const string POLL_INTERVAL = '+100 milliseconds';

    private bool $cancellationRequested = false;
    private ?DateTimeImmutable $nextPollAt = null;

    public function __construct(
        private readonly CancellationTokenInterface $inner,
        private readonly ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function isCancellationRequested(): bool
    {
        if ($this->cancellationRequested) {
            return true;
        }
        if (null !== $this->nextPollAt && $this->clock->now() < $this->nextPollAt) {
            return false;
        }

        return $this->refresh();
    }

    public function refresh(): bool
    {
        // Once observed, cancellation cannot be undone by a later status read.
        if ($this->cancellationRequested) {
            return true;
        }

        $this->cancellationRequested = $this->inner->isCancellationRequested();
        $this->nextPollAt = $this->clock->now()->modify(self::POLL_INTERVAL);

        return $this->cancellationRequested;
    }
}
