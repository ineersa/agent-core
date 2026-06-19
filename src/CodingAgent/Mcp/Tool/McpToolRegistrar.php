<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Psr\Log\LoggerInterface;

/**
 * Bridges the session MCP tool catalog into the ToolRegistry dynamic-tool
 * path so discovered MCP tools are visible to the LLM before schema
 * resolution.
 *
 * Call registerForRun() from the tool-set resolver hook before
 * CodingAgentToolSetResolver builds the active snapshot.  Missing or
 * not-yet-written catalogs are a no-op — MCP tools register only when
 * the catalog exists.
 *
 * Ownership tracking prevents stale MCP tools from surviving across
 * handled runs in long-lived consumers.  Only tool names tracked by
 * this registrar instance are removed; unrelated dynamic tools
 * (extension-registered, other consumers) are never touched.
 */
final class McpToolRegistrar
{
    /**
     * @var array<string, true> MCP-owned dynamic tool names tracked by this instance
     */
    private array $ownedNames = [];

    public function __construct(
        private readonly McpToolCatalogStoreInterface $catalogStore,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly McpToolHandlerFactory $handlerFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Synchronize MCP dynamic tools for a run from the session catalog.
     *
     * Removes previously MCP-owned dynamic tools tracked by this
     * registrar instance before reading the catalog so stale tools
     * from a prior run are cleared even when the current-run catalog
     * is missing or fails.  Then reads the catalog; if present,
     * registers tools from connected servers.
     *
     * Collision diagnostics are logged at warning level with
     * structured context (component=mcp, mcp_event=tool.collision);
     * the collided tool is skipped so non-colliding tools still
     * register.
     *
     * Every caught exception is either propagated forward or logged
     * with structured diagnostics — empty catch blocks are forbidden
     * per AGENTS.md.
     */
    public function registerForRun(string $runId): void
    {
        // Remove previously MCP-owned dynamic tools from this registrar
        // instance so stale tools from prior runs/catalogs are cleared
        // before new registration.  Unrelated dynamic tools are never
        // touched.
        $this->removeOwnedTools();

        // Read the current-run catalog.  Null means no catalog exists
        // yet — this is acceptable (no-op after stale removal).
        $catalog = $this->catalogStore->read($runId);
        if (null === $catalog) {
            return;
        }

        $this->registerFromCatalog($catalog, $runId);
    }

    /**
     * Remove all MCP-owned dynamic tools tracked by this instance.
     *
     * Only removes tools whose names are in $this->ownedNames —
     * unrelated dynamic tools (extension-registered, etc.) are
     * preserved.
     */
    private function removeOwnedTools(): void
    {
        foreach ($this->ownedNames as $name => $_) {
            $this->toolRegistry->removeDynamicTool($name);
        }

        $this->ownedNames = [];
    }

    /**
     * Iterate connected catalog servers and register their tools as
     * dynamic registry entries.
     */
    private function registerFromCatalog(\Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO $catalog, string $runId): void
    {
        foreach ($catalog->servers as $serverEntry) {
            if (McpServerCatalogStatusEnum::CONNECTED !== $serverEntry->status) {
                // Failed/unavailable servers have no usable tools —
                // skip silently.
                continue;
            }

            $this->registerServerTools($serverEntry, $runId);
        }
    }

    /**
     * Register every tool from a connected server entry.
     *
     * Each tool is registered individually so a collision or exception
     * for one tool does not block others from the same server.
     */
    private function registerServerTools(
        \Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogEntryDTO $serverEntry,
        string $runId,
    ): void {
        foreach ($serverEntry->tools as $toolDef) {
            $this->registerOneTool($toolDef, $serverEntry->serverName, $runId);
        }
    }

    /**
     * Register a single MCP tool definition as a dynamic tool.
     *
     * Collision detection: if a tool with the same Hatfield name
     * already exists in the registry after MCP-owned stale removal
     * (either a permanent tool or an unrelated dynamic tool), the
     * MCP tool is skipped with a structured warning.  MCP tools
     * never overwrite non-owned tools.
     */
    private function registerOneTool(
        \Ineersa\CodingAgent\Mcp\Catalog\McpToolDefinitionDTO $toolDef,
        string $serverName,
        string $runId,
    ): void {
        $hatfieldName = $toolDef->hatfieldName;

        // Collision check: if the name exists after we removed our own
        // stale entries, it belongs to someone else (permanent tool or
        // unrelated dynamic tool).  Skip with structured warning.
        //
        // Note: toolDefinition() applies visibility filtering
        // (allowlist/denylist).  A hidden permanent tool may pass
        // this check but still cause addDynamicTool() to throw.
        // The catch block below handles that path.
        if (null !== $this->toolRegistry->toolDefinition($hatfieldName)) {
            $this->logger->warning(
                \sprintf(
                    'MCP tool "%s" (server "%s") name collision with existing tool "%s" — skipped.',
                    $toolDef->mcpName,
                    $serverName,
                    $hatfieldName,
                ),
                [
                    'component' => 'mcp',
                    'mcp_event' => 'tool.collision',
                    'event_type' => 'tool.collision',
                    'server_name' => $serverName,
                    'mcp_tool_name' => $toolDef->mcpName,
                    'hatfield_tool_name' => $hatfieldName,
                    'reason' => 'tool_name_collision',
                    'run_id' => $runId,
                    'session_id' => $runId,
                ],
            );

            return;
        }

        // Guard against empty description — the registry requires
        // non-empty strings.  Fall back to a safe placeholder.
        $description = '' !== $toolDef->description
            ? $toolDef->description
            : \sprintf('MCP tool "%s" from server "%s"', $toolDef->mcpName, $serverName);

        $handler = $this->handlerFactory->create(
            serverName: $serverName,
            mcpName: $toolDef->mcpName,
        );

        try {
            $this->toolRegistry->addDynamicTool(
                name: $hatfieldName,
                description: $description,
                parametersJsonSchema: $toolDef->inputSchema,
                handler: $handler,
                executionMode: ToolExecutionMode::Sequential,
            );

            // Track as MCP-owned so future stale-removal cleans it up.
            $this->ownedNames[$hatfieldName] = true;
        } catch (\Throwable $e) {
            // addDynamicTool rejects permanent/dynamic name collisions
            // with \InvalidArgumentException, including hidden
            // permanent tools that bypassed the visibility-filtered
            // toolDefinition() check above.  Log and continue so
            // other tools still register.
            $this->logger->warning(
                \sprintf(
                    'Failed to register MCP dynamic tool "%s" (server "%s"): %s',
                    $hatfieldName,
                    $serverName,
                    $e->getMessage(),
                ),
                [
                    'component' => 'mcp',
                    'mcp_event' => 'tool.register_failed',
                    'event_type' => 'tool.register_failed',
                    'server_name' => $serverName,
                    'mcp_tool_name' => $toolDef->mcpName,
                    'hatfield_tool_name' => $hatfieldName,
                    'error_class' => $e::class,
                    'error_message' => $e->getMessage(),
                    'run_id' => $runId,
                    'session_id' => $runId,
                ],
            );
        }
    }
}
