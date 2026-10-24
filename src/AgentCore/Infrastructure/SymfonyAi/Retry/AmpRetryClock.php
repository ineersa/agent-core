<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi\Retry;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function Amp\delay;

/** LLM retry waits yield to the same event loop as provider HTTP and keepalive. */
final readonly class AmpRetryClock implements ClockInterface
{
    public function __construct(private ClockInterface $clock = new NativeClock())
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
