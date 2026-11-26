<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;

/**
 * Locates a child run's artifact entry by its agentRunId.
 *
 * The deferred batch tables record every child run against its parent before
 * the artifact is created, so a lookup is one indexed query plus the parent's
 * registry read. Located entries are cached per process; misses are not, since
 * another process may reserve that child later.
 *
 * Pre-population: {@see SubagentExecutionService} calls {@see register()}
 * immediately after artifact creation, and owners register nested entries
 * they read, so the creating process needs no lookup at all.
 */
final class AgentChildRunDirectory
{
    /** @var array<string, AgentArtifactEntryDTO> agentRunId → entry */
    private array $cache = [];

    public function __construct(
        private readonly DeferredSubagentChildRepository $children,
        private readonly AgentArtifactRegistry $artifactRegistry,
    ) {
    }

    /** Returns null when the run is not a known child run, including every parent session. */
    public function locate(string $agentRunId): ?AgentArtifactEntryDTO
    {
        if (isset($this->cache[$agentRunId])) {
            return $this->cache[$agentRunId];
        }

        $parentRunId = $this->children->findParentRunId($agentRunId);
        $entry = null === $parentRunId ? null : $this->artifactRegistry->findByAgentRunId($parentRunId, $agentRunId);
        if (null !== $entry) {
            $this->cache[$agentRunId] = $entry;
        }

        return $entry;
    }

    /** Pre-populate the cache for a known child run. */
    public function register(AgentArtifactEntryDTO $entry): void
    {
        $this->cache[$entry->agentRunId] = $entry;
    }
}
