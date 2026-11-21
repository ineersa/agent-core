<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Clock;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;

use function Amp\delay;

/** Backoff yields to Hatfield's Revolt event loop; foreign fiber hosts use a native clock. */
final readonly class AmpClock implements ClockInterface
{
    public function __construct(private ClockInterface $clock = new MonotonicClock())
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    public function sleep(float|int $seconds): void
    {
        delay((float) $seconds);
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        return new self($this->clock->withTimeZone($timezone));
    }
}
