<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Configuration value object for JSON Lines outbox delivery, specifying batch size and retry delay.
 */
final readonly class ProjectJsonlOutbox
{
    public function __construct(
        public int $batchSize = 100,
        public int $retryDelaySeconds = 30,
    ) {
    }
}
