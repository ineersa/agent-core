<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Mcp\Client\McpClientInvocationException;
use Ineersa\CodingAgent\Mcp\Client\McpConnectionManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared runtime invoker for MCP-backed dynamic tools.
 *
 * Owns the real work: reads ambient execution context, calls the
 * single broker-owned MCP client, and maps results through
 * {@see McpResultMapper}.
 *
 * This service is autowired.  Per-tool handlers ({@see McpToolHandler})
 * are tiny callables that carry only static MCP identity and delegate
 * to this invoker at call time.
 *
 * TODO: Per-call timeout is not enforced because the MCP SDK
 * ({@see McpSdkClientAdapter}) has no per-call timeout or cancellation
 * hook.  Request timeout is fixed at client construction time
 * ({@see McpSdkClientFactory::createSdkClient()}) via the server's
 * configured `timeoutMs`.  {@see ToolContext::timeoutSeconds()} cannot
 * currently cap an in-flight SDK call.  Similarly,
 * {@see ToolContext::cancellationToken()} is not propagated to SDK
 * calls.  When the SDK adds call-level timeout/cancellation support,
 * wire it through {@see McpClientInterface::callTool()} and enforce
 * min(mcpConfigTimeout, toolContextTimeout) here.
 */
final readonly class McpToolInvoker
{
    public function __construct(
        private McpConnectionManagerInterface $connectionManager,
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

        try {
            $rawResult = $this->connectionManager->callTool(
                runId: $context->runId(),
                serverName: $serverName,
                toolName: $mcpName,
                arguments: $arguments,
            );
        } catch (McpClientInvocationException $e) {
            // Client-layer failure (missing client after reconnect,
            // SDK error, connection drop).  Translate to ToolCallException
            // so the existing ToolExecutor pipeline handles it.
            //
            // Mark retryable: connection/client errors are often transient
            // and the next attempt may succeed after a fresh reconnect.
            throw new ToolCallException(error: $e->getMessage(), retryable: true, hint: 'The MCP server could not complete the tool call. The request may succeed if retried.', previous: $e);
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

            throw new ToolCallException(error: 'MCP invocation failed unexpectedly.', retryable: false, hint: 'An unexpected error occurred in the MCP tool invocation layer.', previous: $e);
        }

        try {
            return $this->resultMapper->map($rawResult);
        } catch (ToolCallException $e) {
            // MCP isError or mapping failure — already a ToolCallException.
            // Do not re-wrap; let the handler add server/tool context.
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException(error: 'MCP result mapping failed.', retryable: false, hint: 'The MCP server response could not be mapped to tool output.', previous: $e);
        }
    }
}
