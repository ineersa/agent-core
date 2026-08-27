<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

/** Resolves a run to its stable top-level public session id. */
final readonly class RunOwnerSessionResolver
{
    public function __construct(private AgentChildRunDirectory $childRunDirectory)
    {
    }

    public function ownerSessionIdFor(string $runId): string
    {
        $currentRunId = $runId;
        $visited = [];
        while (null !== ($entry = $this->childRunDirectory->locate($currentRunId))) {
            if (isset($visited[$currentRunId])) {
                throw new \LogicException(\sprintf('Child run ownership cycle detected for "%s".', $runId));
            }
            $visited[$currentRunId] = true;
            $currentRunId = $entry->parentRunId;
        }

        return $currentRunId;
    }
}
