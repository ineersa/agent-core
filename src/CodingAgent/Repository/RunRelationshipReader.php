<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Repository;

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
        return null !== $this->requireKnown($runId)['parent_run_id'];
    }

    public function readParentRunId(string $runId): ?string
    {
        return $this->requireKnown($runId)['parent_run_id'];
    }

    public function requireKnownTopLevel(string $runId): void
    {
        $state = $this->requireKnown($runId);
        if (null !== $state['parent_run_id']) {
            throw new \RuntimeException(\sprintf('Run "%s" is an agent child; nested launches are not supported.', $runId));
        }
    }

    /** @return array{parent_run_id: ?string} */
    private function requireKnown(string $runId): array
    {
        $state = $this->projectionRepository->findRelationship($runId);
        if (null === $state) {
            throw new \RuntimeException(\sprintf('Operational relationship for run "%s" is missing.', $runId));
        }

        return $state;
    }
}
