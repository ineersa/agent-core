<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

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
