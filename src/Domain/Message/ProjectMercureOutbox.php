<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Configuration value object for Mercure outbox delivery, specifying batch size and retry delay.
 */
final readonly class ProjectMercureOutbox
{
    public function __construct(
        public int $batchSize = 100,
        public int $retryDelaySeconds = 30,
    ) {
    }
}
