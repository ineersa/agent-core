<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Handler;

use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogBuilder;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Client\McpConnectionManagerInterface;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Message\McpDisconnectSessionCommand;
use Ineersa\CodingAgent\Mcp\Message\McpInitializeSessionCommand;
use Ineersa\CodingAgent\Mcp\Message\McpRefreshCatalogCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles MCP lifecycle commands on agent.command.bus.
 *
 * MCP-02 Phase 1 behavior (no SDK connections):
 *  - Initialize: load MCP config, log lifecycle event with enabled
 *    server count.  Config failures are warning-only — normal sessions
 *    continue unaffected.
 *  - Refresh catalog / Disconnect: no-op log-only skeletons.
 *
 * MCP-03 Connection manager, discovery, and catalog (this implementation):
 *  - Initialize: load config, connect to enabled servers via McpConnectionManager,
 *    discover tools, write session catalog snapshot.
 *  - Refresh catalog: full rediscovery via McpConnectionManager, atomically
 *    replace catalog with new snapshot.  On failure, write empty/failed
 *    catalog to invalidate stale tools.
 *  - Disconnect: disconnect broker-owned clients for the run.
 *
 * This handler runs in the mcp consumer (controller mode, Doctrine
 * transport) or inline on the command bus (TUI/sync mode).  In TUI/sync
 * mode, discovery runs synchronously — acceptable for the local session.
 */
#[AsMessageHandler(bus: 'agent.command.bus')]
final class McpInitializeSessionHandler
{
    /**
     * @param McpConnectionManagerInterface $connectionManager abstraction for
     *                                                         broker-owned client lifecycle; the concrete implementation
     *                                                         is {@see \Ineersa\CodingAgent\Mcp\Client\McpConnectionManager}
     */
    public function __construct(
        private readonly McpConfigLoader $configLoader,
        private readonly McpConnectionManagerInterface $connectionManager,
        private readonly McpToolCatalogStoreInterface $catalogStore,
        private readonly LoggerInterface $logger,
        private readonly McpToolCatalogBuilder $catalogBuilder,
    ) {
    }

    /**
     * Handle MCP session initialization.
     *
     * Loads MCP config, connects to each enabled server, discovers
     * tools, and writes the session catalog snapshot.
     *
     * Config loading / server discovery failures are intentionally
     * caught and logged as warnings — MCP is optional infrastructure
     * and must not disrupt normal agent sessions.
     *
     * On config failure, an empty catalog is written to invalidate
     * any stale previous catalog for this run.
     */
    public function __invoke(McpInitializeSessionCommand $message): void
    {
        $logContext = [
            'component' => 'mcp',
            'event_type' => 'session.initialize',
            'mcp_event' => 'session.initialize',
            'run_id' => $message->runId,
            'session_id' => $message->runId,
            'reason' => $message->reason,
            'correlation_id' => '' !== $message->correlationId ? $message->correlationId : null,
        ];

        try {
            $config = $this->configLoader->load();
            $enabledCount = \count($config->servers);
            $configHash = $this->catalogBuilder->computeConfigHash($config);

            $this->logger->info('MCP session initialize', [
                ...$logContext,
                'enabled_server_count' => $enabledCount,
            ]);

            if ($enabledCount > 0) {
                // Publish a partial catalog after each server's discovery finishes
                // so successful servers are visible before slow/failing servers.
                $onServerDiscovered = function (array $cumulativeResults) use ($config, $message, $configHash): void {
                    $partialCatalog = $this->catalogBuilder->build($config, $message->runId, $configHash, $cumulativeResults);
                    $this->catalogStore->write($message->runId, $partialCatalog);

                    $this->logger->debug('MCP partial catalog written', [
                        'component' => 'mcp',
                        'event_type' => 'catalog.partial_written',
                        'mcp_event' => 'catalog.partial_written',
                        'run_id' => $message->runId,
                        'session_id' => $message->runId,
                        'server_count' => \count($partialCatalog->servers),
                        'tool_count' => $this->countTools($partialCatalog),
                    ]);
                };

                $discoveryResults = $this->connectionManager->discover($message->runId, $onServerDiscovered);
                $catalog = $this->catalogBuilder->build($config, $message->runId, $configHash, $discoveryResults);
            } else {
                // No servers configured — write empty catalog.
                $catalog = McpToolCatalogDTO::empty($message->runId, 1, $configHash);
            }

            $this->catalogStore->write($message->runId, $catalog);

            $this->logger->info('MCP catalog written', [
                'component' => 'mcp',
                'event_type' => 'catalog.written',
                'mcp_event' => 'catalog.written',
                'run_id' => $message->runId,
                'session_id' => $message->runId,
                'server_count' => \count($catalog->servers),
                'tool_count' => $this->countTools($catalog),
                'generation' => $catalog->generation,
            ]);
        } catch (\Throwable $e) {
            // Config loading / validation / interpolation / discovery
            // failure is non-fatal. Write an empty catalog to invalidate
            // any previously-discovered tools, then log a warning with
            // the exception class and message only — never dump raw
            // config, env values, headers, or tokens.
            $this->writeEmptyCatalogDiagnostic($message->runId);

            $this->logger->warning('MCP initialize failed — config or discovery error, continuing without MCP', [
                ...$logContext,
                'error_class' => $e::class,
                'error_message' => $this->sanitizeErrorMsg($e),
            ]);
        }
    }

    /**
     * Handle catalog refresh — full rediscovery and snapshot replacement.
     *
     * MCP-03 behavior: on refresh failure, writes an empty/failed catalog
     * to invalidate any previously-discovered tools.  Stale tools must
     * never survive a failed refresh.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onRefreshCatalog(McpRefreshCatalogCommand $message): void
    {
        $logContext = [
            'component' => 'mcp',
            'event_type' => 'catalog.refresh',
            'mcp_event' => 'catalog.refresh',
            'run_id' => $message->runId,
            'session_id' => $message->runId,
            'correlation_id' => '' !== $message->correlationId ? $message->correlationId : null,
        ];

        try {
            $config = $this->configLoader->load();
            $enabledCount = \count($config->servers);
            $configHash = $this->catalogBuilder->computeConfigHash($config);

            if ($enabledCount > 0) {
                $onServerDiscovered = function (array $cumulativeResults) use ($config, $message, $configHash): void {
                    $partialCatalog = $this->catalogBuilder->build($config, $message->runId, $configHash, $cumulativeResults);
                    $this->catalogStore->write($message->runId, $partialCatalog);

                    $this->logger->debug('MCP partial catalog written', [
                        'component' => 'mcp',
                        'event_type' => 'catalog.partial_written',
                        'mcp_event' => 'catalog.partial_written',
                        'run_id' => $message->runId,
                        'session_id' => $message->runId,
                        'server_count' => \count($partialCatalog->servers),
                        'tool_count' => $this->countTools($partialCatalog),
                    ]);
                };

                $discoveryResults = $this->connectionManager->discover($message->runId, $onServerDiscovered);
                $catalog = $this->catalogBuilder->build($config, $message->runId, $configHash, $discoveryResults);
            } else {
                $catalog = McpToolCatalogDTO::empty($message->runId, 1, $configHash);
            }

            $this->catalogStore->write($message->runId, $catalog);

            $this->logger->info('MCP catalog refreshed', [
                ...$logContext,
                'server_count' => \count($catalog->servers),
                'tool_count' => $this->countTools($catalog),
                'generation' => $catalog->generation,
            ]);
        } catch (\Throwable $e) {
            // Refresh failure — invalidate stale tools by writing an empty
            // catalog so readers never see previously-discovered tools that
            // may no longer be valid (config changed, server unreachable).
            $this->writeEmptyCatalogDiagnostic($message->runId);

            $this->logger->warning('MCP catalog refresh failed — catalog invalidated', [
                ...$logContext,
                'error_class' => $e::class,
                'error_message' => $this->sanitizeErrorMsg($e),
            ]);
        }
    }

    /**
     * Handle session disconnect — disconnect broker-owned clients.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onDisconnectSession(McpDisconnectSessionCommand $message): void
    {
        $this->logger->debug('MCP session disconnect — closing broker clients', [
            'component' => 'mcp',
            'event_type' => 'session.disconnect',
            'mcp_event' => 'session.disconnect',
            'run_id' => $message->runId,
            'session_id' => $message->runId,
            'correlation_id' => '' !== $message->correlationId ? $message->correlationId : null,
        ]);

        $this->connectionManager->disconnectAll($message->runId);
    }

    /**
     * Count total tools across all server entries in a catalog.
     */
    private function countTools(McpToolCatalogDTO $catalog): int
    {
        $count = 0;
        foreach ($catalog->servers as $entry) {
            $count += \count($entry->tools);
        }

        return $count;
    }

    /**
     * Write an empty catalog and log any inner write failure.
     *
     * Best-effort invalidation: if we cannot even write an empty catalog,
     * log the inner failure diagnostically so operators are aware that
     * stale tools may be visible to readers.
     */
    private function writeEmptyCatalogDiagnostic(string $runId): void
    {
        try {
            $this->catalogStore->write(
                $runId,
                McpToolCatalogDTO::empty($runId, 1),
            );
        } catch (\Throwable $inner) {
            $this->logger->warning('MCP catalog invalidation write also failed — stale tools may persist', [
                'component' => 'mcp',
                'event_type' => 'catalog.invalidation_failed',
                'mcp_event' => 'catalog.invalidation_failed',
                'run_id' => $runId,
                'session_id' => $runId,
                'error_class' => $inner::class,
                'error_message' => $this->sanitizeErrorMsg($inner),
            ]);
        }
    }

    /**
     * Produce a diagnostic-safe error message for log contexts.
     *
     * Delegates to {@see \Ineersa\CodingAgent\Mcp\Client\McpConnectionManager::sanitizeLogMessage()}
     * for consistent redaction. This is a thin pass-through to keep the
     * dependency direction clear (handler → connection manager static method).
     */
    private function sanitizeErrorMsg(\Throwable $e): string
    {
        return \Ineersa\CodingAgent\Mcp\Client\McpConnectionManager::sanitizeLogMessage($e->getMessage());
    }
}
