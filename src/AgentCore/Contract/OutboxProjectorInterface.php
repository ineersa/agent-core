<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Event\OutboxSink;

interface OutboxProjectorInterface
{
    /**
     * Returns the outbox sink this projector handles.
     */
    public function sink(): OutboxSink;

    /**
     * Processes a batch of pending outbox entries for this projector's sink.
     *
     * Claims entries from the outbox store, processes them, and marks
     * them as processed or failed (with retry delay).
     */
    public function processBatch(int $batchSize = 100, int $retryDelaySeconds = 30): void;
}
