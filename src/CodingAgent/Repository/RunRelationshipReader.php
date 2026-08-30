<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Repository;

use Ineersa\CodingAgent\Dto\RunRelationshipDTO;
use Ineersa\CodingAgent\Entity\RunOperationalState;

/**
 * Hot-path child/parent classification from the existing operational projection.
 *
 * Never opens EventStore or session/artifact filesystem indexes.
 */
final class RunRelationshipReader implements RunRelationshipReaderInterface
{
    public function __construct(
        private readonly RunOperationalProjectionRepository $projectionRepository,
    ) {
    }

    public function find(string $runId): ?RunRelationshipDTO
    {
        $state = $this->projectionRepository->find($runId);
        if (!$state instanceof RunOperationalState) {
            return null;
        }

        return new RunRelationshipDTO($state->runId, $state->parentRunId, $state->ownerSessionId);
    }

    /**
     * True when the operational row exists and carries a parent_run_id.
     *
     * Missing rows are treated as not-child for best-effort policy filters
     * (compaction/MCP/bash). Launch/depth safety gates must call
     * {@see requireKnownTopLevel()} instead of this method.
     */
    public function isAgentChild(string $runId): bool
    {
        $relationship = $this->find($runId);

        return null !== $relationship && $relationship->isAgentChild();
    }

    public function readParentRunId(string $runId): ?string
    {
        return $this->find($runId)?->parentRunId;
    }

    /**
     * Fail closed for nested launch/depth gates: unknown rows and child rows both block.
     */
    public function requireKnownTopLevel(string $runId): void
    {
        $relationship = $this->find($runId);
        if (null === $relationship) {
            throw new \RuntimeException(\sprintf('Operational relationship for run "%s" is missing; nested launch is blocked.', $runId));
        }
        if ($relationship->isAgentChild()) {
            throw new \RuntimeException(\sprintf('Run "%s" is an agent child; nested launches are not supported.', $runId));
        }
    }
}
