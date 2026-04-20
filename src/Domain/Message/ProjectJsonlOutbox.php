<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * ProjectJsonlOutbox configures batch and retry parameters for persisting domain events to a JSON Lines file. It serves as a configuration holder for the outbox mechanism without performing I/O operations itself.
 */
final readonly class ProjectJsonlOutbox
{
    public function __construct(
        public int $batchSize = 100,
        public int $retryDelaySeconds = 30,
    ) {
    }
}
