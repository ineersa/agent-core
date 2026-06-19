<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Mcp\Client\McpConnectionManagerInterface;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Psr\Log\LoggerInterface;

/**
 * Shared runtime invoker for MCP-backed dynamic tools.
 *
 * Owns the real work: reads ambient execution context, resolves
 * per-server MCP timeouts, caps the effective timeout by the ToolContext
 * limit, calls the single broker-owned MCP client, and maps results
 * through McpResultMapper.
 *
 * This service is autowired.  Per-tool handlers ({@see McpToolHandler})
 * are tiny callables that carry only static MCP identity and delegate
 * to this invoker at call time.
 */
final readonly class McpToolInvoker
{
    public function __construct(
        private McpConnectionManagerInterface $connectionManager,
        private McpConfigLoader $configLoader,
        private StackToolExecutionContextAccessor $contextAccessor,
        private McpResultMapper $resultMapper,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Invoke an MCP tool in the ambient execution context.
     *
     * Must be called from within a {@see StackToolExecutionContextAccessor::with()}
     * scope so the tool context (runId, timeout, cancellation token) is available.
     *
     * @param string               $serverName Originating MCP server name
     * @param string               $mcpName    Original MCP tool name
     * @param array<string, mixed> $arguments  Tool arguments from the LLM
     *
     * @throws ToolCallException on MCP errors, timeouts, or missing live client
     */
    public function invoke(string $serverName, string $mcpName, array $arguments): string
    {
        $context = $this->contextAccessor->requireCurrent();

        $toolContextTimeoutMs = $context->timeoutSeconds() * 1000;
        $serverTimeoutMs = $this->resolveServerTimeoutMs($serverName);
        $effectiveTimeoutMs = (0 !== $serverTimeoutMs)
            ? min($serverTimeoutMs, $toolContextTimeoutMs)
            : $toolContextTimeoutMs;

        try {
            $rawResult = $this->connectionManager->callTool(
                runId: $context->runId(),
                serverName: $serverName,
                toolName: $mcpName,
                arguments: $arguments,
                requestedTimeoutMs: $effectiveTimeoutMs,
            );
        } catch (ToolCallException $e) {
            // Connection manager already wrapped the error — propagate.
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected MCP tool invocation failure', [
                'component' => 'mcp',
                'event_type' => 'tool.invoke_failed',
                'mcp_event' => 'tool.invoke_failed',
                'run_id' => $context->runId(),
                'session_id' => $context->runId(),
                'server_name' => $serverName,
                'mcp_tool_name' => $mcpName,
                'tool_call_id' => $context->toolCallId(),
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);

            throw new ToolCallException(error: \sprintf('MCP tool "%s" (server "%s") failed unexpectedly: %s', $mcpName, $serverName, $e->getMessage()), retryable: false, hint: 'An unexpected error occurred in the MCP tool invocation layer.', previous: $e);
        }

        try {
            return $this->resultMapper->map($rawResult);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException(error: \sprintf('Failed to map MCP tool result for "%s" (server "%s"): %s', $mcpName, $serverName, $e->getMessage()), retryable: false, hint: 'The MCP server response could not be mapped to tool output.', previous: $e);
        }
    }

    /**
     * Resolve the per-server MCP tool-call timeout in milliseconds.
     *
     * Returns 0 when the config is unavailable (caller should fall
     * back to ToolContext timeout).
     */
    private function resolveServerTimeoutMs(string $serverName): int
    {
        try {
            $config = $this->configLoader->load();
        } catch (\Throwable $e) {
            $this->logger->warning('MCP config load failed during tool invocation timeout resolution', [
                'component' => 'mcp',
                'event_type' => 'invoker.config_load_failed',
                'mcp_event' => 'invoker.config_load_failed',
                'server_name' => $serverName,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);

            return 0;
        }

        $server = $config->servers[$serverName] ?? null;

        if (null === $server) {
            $this->logger->warning('MCP server config not found during tool invocation', [
                'component' => 'mcp',
                'event_type' => 'invoker.server_not_configured',
                'mcp_event' => 'invoker.server_not_configured',
                'server_name' => $serverName,
            ]);

            return 0;
        }

        return $server->timeoutMs;
    }
}
