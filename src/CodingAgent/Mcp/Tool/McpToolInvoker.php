<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\DiagnosticMessageSanitizer;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Mcp\Client\McpClientConnectionException;
use Ineersa\CodingAgent\Mcp\Client\McpClientInterruptedException;
use Ineersa\CodingAgent\Mcp\Client\McpClientInvocationException;
use Ineersa\CodingAgent\Mcp\Client\McpConnectionManagerInterface;
use Mcp\Client\Exception\ConnectionException;
use Mcp\Client\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

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
 * Propagates the ambient cancellation token and deadline to the MCP client.
 * The adapter caps the deadline by the server's configured timeout.
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
     * @param string                    $serverName   Originating MCP server name
     * @param string                    $mcpName      Original MCP tool name
     * @param array<string, mixed>      $arguments    Tool arguments from the LLM
     * @param array<string, mixed>|null $outputSchema
     *
     * @throws ToolCallException on MCP errors, timeouts, or missing live client
     */
    public function invoke(string $serverName, string $mcpName, array $arguments, ?array $outputSchema = null): string|ToolResult
    {
        $context = $this->contextAccessor->requireCurrent();

        try {
            $rawResult = $this->connectionManager->callTool(
                runId: $context->runId(),
                serverName: $serverName,
                toolName: $mcpName,
                arguments: $arguments,
                cancellationToken: $context->cancellationToken(),
                timeoutSeconds: $context->timeoutSeconds(),
            );
        } catch (McpClientInterruptedException $e) {
            throw new ToolCallException(error: $e->getMessage(), retryable: false, hint: 'The MCP tool call was cancelled or timed out.', previous: $e);
        } catch (\Throwable $e) {
            throw $this->failure($e, 'tool.invoke_failed', $serverName, $mcpName);
        }

        try {
            $text = $this->resultMapper->map($rawResult);
            if (!\array_key_exists('structuredContent', $rawResult)) {
                return $text;
            }

            return new ToolResult(
                toolCallId: $context->toolCallId(),
                toolName: $context->toolName(),
                content: [['type' => 'text', 'text' => $text]],
                programmaticResult: [$this->resultMapper->mapStructuredContent($rawResult['structuredContent'], $outputSchema)],
            );
        } catch (ToolCallException $e) {
            // MCP isError or mapping failure — already a ToolCallException.
            // Do not re-wrap; let the handler add server/tool context.
            throw $e;
        } catch (\Throwable $e) {
            throw $this->failure($e, 'tool.map_failed', $serverName, $mcpName);
        }
    }

    private function failure(\Throwable $exception, string $event, string $serverName, string $mcpName): ToolCallException
    {
        $context = $this->contextAccessor->requireCurrent();
        $this->logger->error('mcp.'.$event, [
            'component' => 'mcp', 'event_type' => $event, 'mcp_event' => $event,
            'run_id' => $context->runId(), 'session_id' => $context->runId(),
            'server_name' => $serverName, 'mcp_tool_name' => $mcpName,
            'tool_call_id' => $context->toolCallId(),
            'error_class' => $exception::class,
            'error_message' => DiagnosticMessageSanitizer::sanitize($exception->getMessage()),
            'exception_chain' => DiagnosticMessageSanitizer::exceptionChain($exception),
        ]);

        // The SDK can wrap local errors in connection exceptions. Classify the
        // originating error rather than recommending a retry for the wrapper.
        $origin = $exception;
        while (null !== $origin->getPrevious()) {
            $origin = $origin->getPrevious();
        }
        $retryable = 'tool.invoke_failed' === $event && ($origin instanceof TransportExceptionInterface
            || $origin instanceof ConnectionException
            || $origin instanceof RequestException
            || $origin instanceof McpClientConnectionException
            || $origin instanceof McpClientInvocationException);

        return new ToolCallException(
            error: DiagnosticMessageSanitizer::sanitize($origin::class.': '.$origin->getMessage()),
            retryable: $retryable,
            previous: $exception,
        );
    }
}
