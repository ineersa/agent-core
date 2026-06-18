<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Message;

/**
 * Lifecycle command: initialize MCP connections for a session.
 *
 * Dispatched during start_run and resume so the MCP consumer (Phase 1)
 * can load config, log enabled server counts, and later (Phase 3)
 * establish SDK connections and discover tool catalogs.
 *
 * This message is routed to the dedicated `mcp` Messenger transport.
 * In controller mode (Doctrine queues) it lands in the mcp consumer;
 * in TUI/in-process mode (sync://) it runs synchronously on the
 * command bus as a no-op log.
 *
 * App-layer (not AgentCore domain) — extends no base message.
 */
final readonly class McpInitializeSessionCommand
{
    /**
     * @param string $runId         the session/run identifier (session_id === run_id)
     * @param string $reason        why initialization was triggered: 'start_run' | 'resume'
     * @param string $correlationId unique idempotency key auto-generated if empty
     */
    public function __construct(
        public string $runId,
        public string $reason = 'start_run',
        public string $correlationId = '',
    ) {
    }
}
