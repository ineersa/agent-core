<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;

/**
 * Per-tool MCP execution handler.
 *
 * Carries static MCP identity (originating server and tool names)
 * and delegates actual invocation to the shared {@see McpToolInvoker}
 * at call time.
 *
 * This class is a tiny value-object-like callable.  It is NOT
 * autowireable — instances must be produced by {@see McpToolHandlerFactory}.
 */
final readonly class McpToolHandler implements ToolHandlerInterface
{
    /**
     * @param McpToolInvoker $invoker Shared runtime invoker
     */
    public function __construct(
        public string $serverName,
        public string $mcpName,
        private McpToolInvoker $invoker,
    ) {
    }

    public function __invoke(array $arguments): mixed
    {
        try {
            return $this->invoker->invoke(
                serverName: $this->serverName,
                mcpName: $this->mcpName,
                arguments: $arguments,
            );
        } catch (ToolCallException $e) {
            // Add single server/tool context prefix.  Lower layers
            // throw neutral messages — only this handler adds the
            // user-visible MCP identity prefix.
            throw new ToolCallException(error: \sprintf('MCP tool "%s" (server "%s"): %s', $this->mcpName, $this->serverName, $e->getMessage()), retryable: $e->retryable(), hint: $e->hint(), previous: $e);
        }
    }
}
