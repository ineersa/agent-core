<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp;

use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Message\McpInitializeSessionCommand;
use Ineersa\CodingAgent\Mcp\Message\McpRefreshCatalogCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches MCP lifecycle commands from the session client layer.
 *
 * Called after start_run and attach so the MCP consumer receives
 * an initialize command.  Failure to dispatch is logged but never
 * propagated — MCP is optional infrastructure and must not block
 * normal session flow.
 *
 * Intentionally safe for both in-process (sync://) and controller
 * (Doctrine) transports.
 */
final readonly class McpSessionLifecycleDispatcher
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
        private McpConfigLoader $configLoader,
        private McpToolCatalogStoreInterface $catalogStore,
    ) {
    }

    /** Nonempty old catalogs need invalidation even when configuration is now empty. */
    public function requiresConsumer(string $runId): bool
    {
        try {
            return [] !== $this->configLoader->load()->servers || [] !== ($this->catalogStore->read($runId)->servers ?? []);
        } catch (\Throwable $exception) {
            // Preserve optional MCP degradation: the worker publishes a failed
            // catalog rather than preventing controller startup on invalid config.
            $this->logger->warning('MCP startup configuration unavailable; retaining consumer', [
                'component' => 'mcp',
                'event_type' => 'mcp.startup_config_unavailable',
                'run_id' => $runId,
                'session_id' => $runId,
                'error_class' => $exception::class,
            ]);

            return true;
        }
    }

    /**
     * Dispatch an MCP initialize command for the given session.
     *
     * @param string $runId  the run/session identifier
     * @param string $reason why initialization was triggered: 'start_run' | 'attach'
     */
    public function dispatchInitialize(string $runId, string $reason): void
    {
        // No configured capability and no old catalog to invalidate means no
        // lifecycle work. Do not enqueue initialization for an omitted consumer.
        if (!$this->requiresConsumer($runId)) {
            return;
        }

        $correlationId = bin2hex(random_bytes(12));

        try {
            $this->commandBus->dispatch(new McpInitializeSessionCommand(
                runId: $runId,
                reason: $reason,
                correlationId: $correlationId,
            ));
        } catch (\Throwable $e) {
            // Dispatch failure is non-fatal — MCP is optional infrastructure.
            // The session continues without MCP tools. error_class in context
            // distinguishes transport failures (ExceptionInterface) from bugs.
            $this->logger->warning('Failed to dispatch MCP initialize command', [
                'component' => 'mcp',
                'event_type' => 'dispatch.initialize.failed',
                'mcp_event' => 'dispatch.initialize.failed',
                'run_id' => $runId,
                'session_id' => $runId,
                'reason' => $reason,
                'correlation_id' => $correlationId,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dispatch an MCP catalog refresh (reconnect-all) for the given session.
     *
     * Unlike initialize, refresh failures are propagated so the TUI can report
     * that reconnect was not accepted by the bus.
     */
    public function dispatchRefresh(string $runId): void
    {
        $correlationId = bin2hex(random_bytes(12));

        $this->commandBus->dispatch(new McpRefreshCatalogCommand(
            runId: $runId,
            correlationId: $correlationId,
        ));
    }
}
