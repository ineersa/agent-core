<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Dto;

/**
 * Narrow durable child/parent relationship from run_operational_state.
 *
 * parentRunId is non-null only for agent_child rows. Missing operational rows
 * are represented by null from the reader, never by inventing a top-level row.
 */
final readonly class RunRelationshipDTO
{
    public function __construct(
        public string $runId,
        public ?string $parentRunId,
        public string $ownerSessionId,
    ) {
    }

    public function isAgentChild(): bool
    {
        return null !== $this->parentRunId;
    }
}
