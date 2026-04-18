<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Event\OutboxEntry;
use Ineersa\AgentCore\Domain\Event\OutboxSink;
use Ineersa\AgentCore\Domain\Event\RunEvent;

interface OutboxStoreInterface
{
    public function enqueue(RunEvent $event, OutboxSink $sink): void;

    /**
     * @return list<OutboxEntry>
     */
    public function claim(OutboxSink $sink, int $limit = 100, ?\DateTimeImmutable $now = null): array;

    public function markProcessed(int $entryId, ?\DateTimeImmutable $processedAt = null): void;

    public function markFailed(int $entryId, int $retryAfterSeconds = 30, ?\DateTimeImmutable $now = null): void;
}
