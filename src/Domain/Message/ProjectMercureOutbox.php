<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * ProjectMercureOutbox configures the publishing parameters for the Mercure transport boundary, specifically defining batch sizes and retry delays for event dispatch. It serves as a configuration holder for the outbox mechanism without performing state mutations or I/O operations itself.
 */
final readonly class ProjectMercureOutbox
{
    public function __construct(
        public int $batchSize = 100,
        public int $retryDelaySeconds = 30,
    ) {
    }
}
