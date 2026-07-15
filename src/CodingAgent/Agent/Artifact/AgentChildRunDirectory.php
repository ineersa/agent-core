<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Psr\Log\LoggerInterface;

/**
 * In-memory lookup index for child agent runs backed by AgentArtifactRegistry by their agentRunId.
 *
 * Scans known parent scopes starting from DB-backed top-level sessions
 * (via HatfieldSessionStore::listSessions()) and recursively walks each
 * discovered child {@see AgentArtifactEntryDTO::$agentRunId} as a
 * further parent scope (nested fork → scout registries live under
 * `.hatfield/sessions/<childRunId>/artifacts/agents/`).  Results are
 * cached per process for subsequent lookups.
 *
 * This is intentionally a lazy scan — there is no global child-run
 * index.  A cache miss walks all reachable parent scopes once; subsequent
 * lookups of the same runId are O(1) via the in-memory cache.
 *
 * Cache-hit fast path: already-located (or pre-registered) entries
 * return from the in-memory map without a session-list query or
 * registry read.
 *
 * Cache-miss rescan: when a runId is not in the cache, the locator
 * rescans all known parent sessions and their registries.  This avoids
 * a stale process-wide flag that would permanently miss child runs
 * created later in long-lived messenger consumer or tool processes.
 * The rescan is intentionally simple in v1 — there is no listener or
 * cache-invalidation event for new artifacts.
 *
 * Pre-population: {@see SubagentExecutionService} calls {@see register()}
 * immediately after artifact creation so the creating process avoids the
 * full scan on the first lookup.  Other processes (e.g. messenger
 * consumers) discover the new child on their next cache-miss lookup.
 */
final class AgentChildRunDirectory
{
    /** @var array<string, AgentArtifactEntryDTO> agentRunId → entry */
    private array $cache = [];

    public function __construct(
        private readonly HatfieldSessionStore $hatfieldSessionStore,
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Locate a child artifact entry by agentRunId.
     *
     * Cache-hit fast path (O(1)): returns the entry directly when the
     * runId was already located or pre-registered.
     *
     * Cache-miss path (O(P)): rescans all known parent sessions and
     * their artifact registries.  If the child run was created since
     * the last scan (e.g. by another long-lived messenger consumer),
     * the rescan discovers it.
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
     * so that the routers find the child store on the very first lookup
     * in the same process.
     */
    public function register(AgentArtifactEntryDTO $entry): void
    {
        $this->cache[$entry->agentRunId] = $entry;
    }

    /**
     * Drop a pre-registered cache entry when a pending reservation is discarded.
     */
    public function unregister(string $agentRunId): void
    {
        unset($this->cache[$agentRunId]);
    }

    /**
     * Scan all known parent sessions for child artifacts.
     *
     * No process-wide scanned guard — long-lived processes (messenger
     * consumers, tool workers) may see newly created child artifacts
     * on a subsequent cache-miss lookup after the first scan.
     *
     * Failures on individual parent registries are logged and skipped
     * so that one corrupt registry does not prevent locating child
     * runs in other parents.
     */
    private function scanAllSessions(): void
    {
        /** @var array<string, true> parent scopes whose registry.json was already scanned */
        $visitedParentScopes = [];

        /** @var list<string> BFS queue of parentRunId scopes (top-level sessions + nested child runs) */
        $parentScopeQueue = [];

        foreach ($this->hatfieldSessionStore->listSessions() as $session) {
            $parentRunId = $session['sessionId'] ?? null;
            if (!\is_string($parentRunId) || '' === $parentRunId) {
                continue;
            }

            $parentScopeQueue[] = $parentRunId;
        }

        $this->logger->debug('AgentChildRunDirectory scanning for child artifacts', [
            'component' => 'agent.locator',
            'top_level_session_count' => \count($parentScopeQueue),
        ]);

        while ([] !== $parentScopeQueue) {
            $parentRunId = array_shift($parentScopeQueue);
            if (isset($visitedParentScopes[$parentRunId])) {
                continue;
            }

            $visitedParentScopes[$parentRunId] = true;

            try {
                $entries = $this->artifactRegistry->list($parentRunId);
            } catch (\Throwable $e) {
                // Registry listing for one parent failing should not
                // prevent location of child runs in other parents.
                $this->logger->warning('Failed to list artifacts for parent session', [
                    'component' => 'agent.locator',
                    'parent_run_id' => $parentRunId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($entries as $entry) {
                if (!isset($this->cache[$entry->agentRunId])) {
                    $this->cache[$entry->agentRunId] = $entry;
                }

                // Nested children (e.g. fork → scout) register under the
                // child run's own session directory; enqueue for BFS.
                if (!isset($visitedParentScopes[$entry->agentRunId])) {
                    $parentScopeQueue[] = $entry->agentRunId;
                }
            }
        }
    }
}
