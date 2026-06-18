<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Handler;

use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Message\McpDisconnectSessionCommand;
use Ineersa\CodingAgent\Mcp\Message\McpInitializeSessionCommand;
use Ineersa\CodingAgent\Mcp\Message\McpRefreshCatalogCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles MCP lifecycle commands on agent.command.bus.
 *
 * Phase 1 behavior (MCP-02):
 *  - Initialize: load MCP config, log lifecycle event with enabled
 *    server count.  Config failures are warning-only — normal sessions
 *    continue unaffected.  No real SDK connection or discovery yet.
 *  - Refresh catalog / Disconnect: no-op log-only skeletons.
 *
 * Phase 3+ (MCP-03/04/05): will connect SDK clients, discover tools,
 *   persist catalogs, and handle disconnect cleanup.
 *
 * This handler runs in the mcp consumer (controller mode, Doctrine
 * transport) or inline on the command bus (TUI/sync mode).
 */
#[AsMessageHandler(bus: 'agent.command.bus')]
final class McpInitializeSessionHandler
{
    public function __construct(
        private readonly McpConfigLoader $configLoader,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handle MCP session initialization.
     *
     * Loads MCP config and logs a structured lifecycle event.
     * Config loading / validation failures are intentionally caught and
     * logged as warnings — MCP is optional infrastructure and must not
     * disrupt normal agent sessions.
     */
    public function __invoke(McpInitializeSessionCommand $message): void
    {
        $logContext = [
            'component' => 'mcp',
            'mcp_event' => 'session.initialize',
            'run_id' => $message->runId,
            'session_id' => $message->runId,
            'reason' => $message->reason,
            'correlation_id' => '' !== $message->correlationId ? $message->correlationId : null,
        ];

        try {
            $config = $this->configLoader->load();
            $enabledCount = \count($config->servers);

            $this->logger->info('MCP session initialize', [
                ...$logContext,
                'enabled_server_count' => $enabledCount,
            ]);

            if ($enabledCount > 0) {
                // Log per-server info (name and transport type only — never
                // command, args, env values, headers, tokens, or URLs).
                foreach ($config->servers as $name => $server) {
                    $this->logger->debug('MCP server configured', [
                        'component' => 'mcp',
                        'mcp_event' => 'server.configured',
                        'run_id' => $message->runId,
                        'server_name' => $name,
                        'transport' => null !== $server->transportType ? $server->transportType->value : 'unknown',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Config loading / validation / interpolation failure is
            // non-fatal.  Log a warning with the exception class and
            // message only — never dump the raw config, env values,
            // headers, or tokens.
            $this->logger->warning('MCP initialize failed — config error, continuing without MCP', [
                ...$logContext,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle catalog refresh (Phase 1 skeleton — log only).
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onRefreshCatalog(McpRefreshCatalogCommand $message): void
    {
        $this->logger->debug('MCP refresh catalog requested (Phase 3 deferred)', [
            'component' => 'mcp',
            'mcp_event' => 'catalog.refresh.requested',
            'run_id' => $message->runId,
            'correlation_id' => '' !== $message->correlationId ? $message->correlationId : null,
        ]);
    }

    /**
     * Handle session disconnect (Phase 1 skeleton — log only).
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onDisconnectSession(McpDisconnectSessionCommand $message): void
    {
        $this->logger->debug('MCP disconnect requested (Phase 4 deferred)', [
            'component' => 'mcp',
            'mcp_event' => 'session.disconnect.requested',
            'run_id' => $message->runId,
            'correlation_id' => '' !== $message->correlationId ? $message->correlationId : null,
        ]);
    }
}
