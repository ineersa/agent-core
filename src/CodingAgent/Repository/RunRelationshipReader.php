<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Repository;

use Ineersa\CodingAgent\Entity\RunOperationalState;

/**
 * Hot-path child/parent classification from the existing operational projection.
 *
 * Never opens EventStore or session/artifact filesystem indexes.
 * Missing projection rows fail closed so unknown identity cannot bypass
 * child safety/policy as if it were a known top-level parent.
 */
final class RunRelationshipReader implements RunRelationshipReaderInterface
{
    public function __construct(
        private readonly RunOperationalProjectionRepository $projectionRepository,
    ) {
    }

    public function isAgentChild(string $runId): bool
    {
        return null !== $this->requireKnown($runId)->parentRunId;
    }

    public function readParentRunId(string $runId): ?string
    {
        return $this->requireKnown($runId)->parentRunId;
    }

    public function requireKnownTopLevel(string $runId): void
    {
        $state = $this->requireKnown($runId);
        if (null !== $state->parentRunId) {
            throw new \RuntimeException(\sprintf('Run "%s" is an agent child; nested launches are not supported.', $runId));
        }
    }

    private function requireKnown(string $runId): RunOperationalState
    {
        $state = $this->projectionRepository->find($runId);
        if (!$state instanceof RunOperationalState) {
            throw new \RuntimeException(\sprintf('Operational relationship for run "%s" is missing.', $runId));
        }

        return $state;
    }
}
