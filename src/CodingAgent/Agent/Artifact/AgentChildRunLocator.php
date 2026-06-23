<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Psr\Log\LoggerInterface;

/**
 * Lazily locates child agent run artifact entries by their agentRunId.
 *
 * Scans known parent sessions (via HatfieldSessionStore::listSessions())
 * and checks each parent's AgentArtifactRegistry for an entry matching
 * the given agentRunId.  Results are cached per process for subsequent
 * lookups.
 *
 * This is intentionally a lazy scan — there is no global child-run
 * index.  The first lookup for an unknown agentRunId is O(P) where P
 * is the number of live parent sessions, but subsequent lookups of the
 * same runId are O(1).
 *
 * Once the cache is populated (by a successful locate or by the
 * SubagentExecutionService pre-populating it after artifact creation),
 * the router path is fast.
 */
final class AgentChildRunLocator
{
    /** @var array<string, AgentArtifactEntryDTO> agentRunId → entry */
    private array $cache = [];

    /** @var bool whether a full scan has been performed */
    private bool $scanned = false;

    public function __construct(
        private readonly HatfieldSessionStore $hatfieldSessionStore,
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Locate a child artifact entry by agentRunId.
     *
     * Returns null when the run is not a known child run.
     */
    public function locate(string $agentRunId): ?AgentArtifactEntryDTO
    {
        if (isset($this->cache[$agentRunId])) {
            return $this->cache[$agentRunId];
        }

        $this->scanAllSessions();

        return $this->cache[$agentRunId] ?? null;
    }

    /**
     * Pre-populate the cache for a known child run (faster than scanning).
     *
     * Called by SubagentExecutionService immediately after artifact creation
     * so that the routers find the child store on the very first lookup.
     */
    public function register(AgentArtifactEntryDTO $entry): void
    {
        $this->cache[$entry->agentRunId] = $entry;
    }

    /**
     * Scan all known parent sessions for child artifacts.
     */
    private function scanAllSessions(): void
    {
        if ($this->scanned) {
            return;
        }

        $this->scanned = true;

        $sessions = $this->hatfieldSessionStore->listSessions();

        foreach ($sessions as $session) {
            $parentRunId = $session['session_id'] ?? null;
            if (!\is_string($parentRunId) || '' === $parentRunId) {
                continue;
            }

            try {
                $entries = $this->artifactRegistry->list($parentRunId);
                foreach ($entries as $entry) {
                    // Only cache the entry if not already known.
                    if (!isset($this->cache[$entry->agentRunId])) {
                        $this->cache[$entry->agentRunId] = $entry;
                    }
                }
            } catch (\Throwable $e) {
                // Registry listing for one parent failing should not
                // prevent location of child runs in other parents.
                $this->logger->warning('Failed to list artifacts for parent session', [
                    'component' => 'agent.locator',
                    'parent_run_id' => $parentRunId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
