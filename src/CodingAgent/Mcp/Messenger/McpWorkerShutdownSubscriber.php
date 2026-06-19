<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Messenger;

use Ineersa\CodingAgent\Mcp\Client\McpConnectionManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

/**
 * Best-effort graceful shutdown for broker-owned MCP clients.
 *
 * When the single mcp Messenger consumer process is stopped (SIGTERM
 * from ConsumerSupervisor or messenger:consume exit), this subscriber
 * disconnects all MCP clients owned by the current session.
 *
 * This is best-effort: no waits, no drain loops, no blocking reconnect.
 * If the process is SIGKILL'd or OOM-killed, this subscriber never runs
 * and orphaned MCP processes (STDIO) may remain. That limitation is
 * documented in docs/mcp.md.
 *
 * In TUI/sync mode (no separate mcp consumer), this subscriber never
 * fires because there is no mcp transport worker to stop.
 */
#[AsEventListener(event: WorkerStoppedEvent::class)]
final readonly class McpWorkerShutdownSubscriber
{
    public function __construct(
        private McpConnectionManagerInterface $connectionManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerStoppedEvent $event): void
    {
        // Only react when the mcp transport worker is stopping.
        $transportNames = $event->getWorker()->getMetadata()->getTransportNames();

        if (!\in_array('mcp', $transportNames, true)) {
            return;
        }

        // Resolve the session/run identifier from the controller-set env var.
        // In TUI/sync mode this is empty — but the mcp consumer only exists
        // in controller mode, so this is set in practice.  Default 'unknown'
        // matches HeadlessController/JsonlProcessAgentSessionClient conventions.
        $runId = $_SERVER['HATFIELD_SESSION_ID'] ?? $_ENV['HATFIELD_SESSION_ID'] ?? 'unknown';

        if ('' === $runId || 'unknown' === $runId) {
            $this->logger->warning('MCP worker shutdown — no HATFIELD_SESSION_ID, skipping disconnect', [
                'component' => 'mcp',
                'event_type' => 'worker.shutdown',
                'mcp_event' => 'worker.shutdown.no_session_id',
                'session_id' => 'unknown',
            ]);

            return;
        }

        $this->logger->info('MCP worker shutdown — disconnecting broker clients', [
            'component' => 'mcp',
            'event_type' => 'worker.shutdown',
            'mcp_event' => 'worker.shutdown.disconnecting',
            'run_id' => $runId,
            'session_id' => $runId,
        ]);

        // No waits, no drain loops — best-effort disconnect only.
        // Catch any unexpected Throwable — shutdown event listeners must not
        // propagate exceptions.
        try {
            $this->connectionManager->disconnectAll($runId);
        } catch (\Throwable $e) {
            $this->logger->warning('MCP worker shutdown — disconnect failed', [
                'component' => 'mcp',
                'event_type' => 'worker.shutdown',
                'mcp_event' => 'worker.shutdown.disconnect_failed',
                'run_id' => $runId,
                'session_id' => $runId,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
