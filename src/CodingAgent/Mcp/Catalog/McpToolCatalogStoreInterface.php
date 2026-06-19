<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Catalog;

/**
 * Read/write contract for the session-scoped MCP tool catalog.
 *
 * The catalog is the source of truth for which MCP tools are available
 * during a session. Broker processes write it after discovery; other
 * processes (LLM schema resolution, tool workers) read it.
 */
interface McpToolCatalogStoreInterface
{
    /**
     * Atomically write a catalog snapshot.
     *
     * Must use temp-file + rename so readers see either the old complete
     * snapshot or the new complete snapshot, never a partial file.
     */
    public function write(string $runId, McpToolCatalogDTO $catalog): void;

    /**
     * Read the current catalog snapshot for a run.
     *
     * Returns null when no catalog has been written yet for this run.
     */
    public function read(string $runId): ?McpToolCatalogDTO;
}
