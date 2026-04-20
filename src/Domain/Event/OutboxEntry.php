<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

/**
 * Represents a durable record for an event pending delivery to an external sink. It encapsulates the event payload, target sink, and retry metadata to support reliable outbox pattern implementation.
 */
final readonly class OutboxEntry
{
    public function __construct(
        public int $id,
        public OutboxSink $sink,
        public RunEvent $event,
        public int $attempts,
        public \DateTimeImmutable $availableAt,
    ) {
    }
}
