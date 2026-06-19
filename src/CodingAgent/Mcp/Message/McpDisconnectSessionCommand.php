<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Message;

/**
 * Lifecycle command: disconnect all MCP servers for a session.
 *
 * Handled by {@see \Ineersa\CodingAgent\Mcp\Handler\McpInitializeSessionHandler::onDisconnectSession()}
 * which delegates to {@see \Ineersa\CodingAgent\Mcp\Client\McpConnectionManagerInterface::disconnectAll()}.
 *
 * Graceful shutdown is best-effort via {@see \Ineersa\CodingAgent\Mcp\Messenger\McpWorkerShutdownSubscriber}
 * which listens to {@see \Symfony\Component\Messenger\Event\WorkerStoppedEvent} and calls
 * disconnectAll directly in-process — no extra Messenger dispatch is needed.
 *
 * Routed to the `mcp` Messenger transport.
 */
final readonly class McpDisconnectSessionCommand
{
    public function __construct(
        public string $runId,
        public string $correlationId = '',
    ) {
    }
}
