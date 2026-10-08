<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Messenger;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * MCP-aware ExecuteToolCall routing middleware for agent.execution.bus.
 *
 * Runs before Symfony's send_message middleware.  Inspects outbound
 * ExecuteToolCall envelopes: if the tool name matches an MCP dynamic
 * tool in the session catalog, adds TransportNamesStamp(['mcp']) so
 * the call is routed to the single mcp consumer instead of the default
 * tool transport.
 *
 * Skips envelopes that already carry ReceivedStamp (already consumed)
 * or an explicit TransportNamesStamp (already routed).
 *
 * Catalog read failures are logged with structured context and then
 * re-thrown — silently routing an MCP tool to the normal tool worker
 * is worse than failing the dispatch.
 */
final readonly class McpExecuteToolCallRoutingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private McpToolCatalogStoreInterface $catalogStore,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        if (!$message instanceof ExecuteToolCall) {
            return $stack->next()->handle($envelope, $stack);
        }

        // Already consumed by a worker — do not re-route.
        if (null !== $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        // Already carries an explicit transport stamp from another
        // middleware or caller — do not override.
        if (null !== $envelope->last(TransportNamesStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $runId = $message->runId();

        try {
            $catalog = $this->catalogStore->read($runId);
        } catch (\Throwable $e) {
            // Catalog read failures are dangerous — an MCP tool could
            // silently route to the wrong consumer.  Log and rethrow
            // so the dispatch fails cleanly.
            $this->logger->error('MCP catalog read failed during tool routing', [
                'component' => 'mcp',
                'event_type' => 'middleware.catalog_read_failed',
                'mcp_event' => 'middleware.catalog_read_failed',
                'run_id' => $runId,
                'session_id' => $runId,
                'tool_name' => $message->toolName,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        if (null === $catalog) {
            // No catalog written yet — normal first-turn scenario.
            // Route normally; MCP registration will catch up on later turns.
            return $stack->next()->handle($envelope, $stack);
        }

        // Check whether this tool name belongs to a connected MCP server
        // in the catalog.
        if ($this->isMcpTool($message->toolName, $catalog)) {
            $this->logger->debug('Routing MCP-backed tool call to mcp transport', [
                'component' => 'mcp',
                'event_type' => 'middleware.routing_mcp',
                'mcp_event' => 'middleware.routing_mcp',
                'run_id' => $runId,
                'session_id' => $runId,
                'tool_name' => $message->toolName,
            ]);

            return $stack->next()->handle(
                $envelope->with(new TransportNamesStamp(['mcp'])),
                $stack,
            );
        }

        // Not an MCP tool — let default YAML routing send to tool transport.
        return $stack->next()->handle($envelope, $stack);
    }

    /**
     * Check whether a tool name matches an MCP tool from a connected server
     * in the session catalog.
     */
    private function isMcpTool(string $toolName, \Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO $catalog): bool
    {
        foreach ($catalog->servers as $serverEntry) {
            if (McpServerCatalogStatusEnum::CONNECTED !== $serverEntry->status) {
                continue;
            }

            foreach ($serverEntry->tools as $toolDef) {
                if ($toolDef->hatfieldName === $toolName) {
                    return true;
                }
            }
        }

        return false;
    }
}
