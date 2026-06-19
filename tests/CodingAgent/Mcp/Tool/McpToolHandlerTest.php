<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Mcp\Client\McpClientInvocationException;
use Ineersa\CodingAgent\Mcp\Client\McpConnectionManagerInterface;
use Ineersa\CodingAgent\Mcp\Tool\McpResultMapper;
use Ineersa\CodingAgent\Mcp\Tool\McpToolHandler;
use Ineersa\CodingAgent\Mcp\Tool\McpToolHandlerFactory;
use Ineersa\CodingAgent\Mcp\Tool\McpToolInvoker;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis 1: McpToolHandler delegates its static MCP identity
 * (serverName, mcpName) to McpToolInvoker and returns the mapped result.
 *
 * Test thesis 2: When the invoker throws ToolCallException, the handler
 * re-wraps it with server/tool context.
 *
 * Test thesis 3: McpToolHandlerFactory creates correct per-tool handlers.
 */
final class McpToolHandlerTest extends TestCase
{
    private TestLogger $logger;
    private StackToolExecutionContextAccessor $contextAccessor;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
        $this->contextAccessor = new StackToolExecutionContextAccessor();
    }

    // ── Test thesis 1: Handler delegates and returns mapped result ──

    public function testHandlerDelegatesToInvokerAndReturnsResult(): void
    {
        $invoker = $this->makeInvokerWithResult('hello from mcp');
        $handler = new McpToolHandler('echo', 'reverse', $invoker);

        $result = $this->contextAccessor->with(
            new ToolContext('run-1', 1, 'tc1', 'echo_reverse', new NullCancellationToken(), 30),
            fn () => $handler(['text' => 'hello']),
        );

        $this->assertSame('hello from mcp', $result);
    }

    // ── Test thesis 2: Handler re-wraps invoker exceptions ──

    public function testHandlerReWrapsInvokerToolCallException(): void
    {
        $invoker = $this->makeInvokerThatThrows(
            new ToolCallException('MCP server disconnected', retryable: false, hint: 'Check connectivity'),
        );
        $handler = new McpToolHandler('broken', 'fetch', $invoker);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP tool "fetch" (server "broken"): MCP server disconnected');

        $this->contextAccessor->with(
            new ToolContext('run-1', 1, 'tc1', 'broken_fetch', new NullCancellationToken(), 30),
            fn () => $handler([]),
        );
    }

    // ── Test thesis 3: Factory creates correct handlers ──

    public function testFactoryCreatesHandlerWithCorrectIdentity(): void
    {
        $invoker = $this->makeInvokerWithResult('ok');
        $factory = new McpToolHandlerFactory($invoker);
        $handler = $factory->create('echo', 'reverse');

        $this->assertSame('echo', $handler->serverName);
        $this->assertSame('reverse', $handler->mcpName);
    }

    // ── Handler preserves invoker error message through re-wrap ──

    public function testHandlerPreservesHintFromInvokerException(): void
    {
        $invoker = $this->makeInvokerThatThrows(
            new ToolCallException('Server timeout', retryable: false, hint: 'Retry with smaller payload'),
        );
        $handler = new McpToolHandler('api', 'query', $invoker);

        try {
            $this->contextAccessor->with(
                new ToolContext('run-1', 1, 'tc1', 'api_query', new NullCancellationToken(), 30),
                fn () => $handler(['q' => 'search']),
            );
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('MCP tool "query" (server "api")', $e->getMessage());
            $this->assertStringContainsString('Server timeout', $e->getMessage());
            // The invoker translates McpClientInvocationException with its own hint;
            // the handler preserves whatever hint the invoker sets.
            $this->assertStringContainsString('could not complete', $e->hint());
            // Client-layer exceptions are translated to retryable=true
            // because connection/transport errors are often transient.
            $this->assertTrue($e->retryable());
        }
    }

    // ── Helpers ──

    private function makeInvokerWithResult(string $fakeResult): McpToolInvoker
    {
        $connectionManager = new class($fakeResult) implements McpConnectionManagerInterface {
            public function __construct(private string $fakeResult) {}
            public function discover(string $runId): array { return []; }
            public function getClient(string $runId, string $serverName): ?\Ineersa\CodingAgent\Mcp\Client\McpClientInterface { return null; }
            public function disconnectServer(string $runId, string $serverName): void {}
            public function disconnectAll(string $runId): void {}

            public function callTool(string $runId, string $serverName, string $toolName, array $arguments = []): array
            {
                return [
                    'content' => [['type' => 'text', 'text' => $this->fakeResult]],
                    'isError' => false,
                ];
            }
        };

        return new McpToolInvoker(
            $connectionManager,
            $this->contextAccessor,
            new McpResultMapper(),
            $this->logger,
        );
    }

    private function makeInvokerThatThrows(\Throwable $exception): McpToolInvoker
    {
        $connectionManager = new class($exception) implements McpConnectionManagerInterface {
            public function __construct(private \Throwable $exception) {}
            public function discover(string $runId): array { return []; }
            public function getClient(string $runId, string $serverName): ?\Ineersa\CodingAgent\Mcp\Client\McpClientInterface { return null; }
            public function disconnectServer(string $runId, string $serverName): void {}
            public function disconnectAll(string $runId): void {}

            public function callTool(string $runId, string $serverName, string $toolName, array $arguments = []): array
            {
                throw new McpClientInvocationException($this->exception->getMessage(), 0, $this->exception);
            }
        };

        return new McpToolInvoker(
            $connectionManager,
            $this->contextAccessor,
            new McpResultMapper(),
            $this->logger,
        );
    }
}
