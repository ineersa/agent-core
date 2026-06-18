<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Message;

/**
 * Lifecycle command: disconnect all MCP servers for a session.
 *
 * Skeleton for future Phase 4+ use. In Phase 1 the handler is a no-op
 * that only logs the event. Real disconnect/cleanup is deferred until
 * connection management is implemented (MCP-03).
 *
 * Dispatched explicitly or on controller graceful shutdown.
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
