<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Mcp\Client\McpClientInterruptedException;
use Ineersa\CodingAgent\Mcp\Client\McpClientInvocationException;
use Ineersa\CodingAgent\Mcp\Client\McpConnectionManagerInterface;
use Ineersa\CodingAgent\Mcp\Tool\McpResultMapper;
use Ineersa\CodingAgent\Mcp\Tool\McpToolInvoker;
use PHPUnit\Framework\TestCase;

final class McpToolInvokerTest extends TestCase
{
    public function testInvokePropagatesTokenBudgetAndMapsInterruption(): void
    {
        $token = new class implements CancellationTokenInterface {
            public function isCancellationRequested(): bool
            {
                return false;
            }
        };
        $manager = new class($token) implements McpConnectionManagerInterface {
            public ?string $runId = null;
            public ?string $serverName = null;
            public ?string $toolName = null;
            /** @var array<string, mixed> */
            public array $arguments = [];
            public mixed $cancellationToken = null;
            public ?int $timeoutSeconds = null;

            public function __construct(private CancellationTokenInterface $expectedToken)
            {
            }

            public function discover(string $runId, ?callable $onServerDiscovered = null): array
            {
                return [];
            }

            public function disconnectAll(string $runId): void
            {
            }

            public function callTool(string $runId, string $serverName, string $toolName, array $arguments = [], ?CancellationTokenInterface $cancellationToken = null, ?int $timeoutSeconds = null): array
            {
                $this->runId = $runId;
                $this->serverName = $serverName;
                $this->toolName = $toolName;
                $this->arguments = $arguments;
                $this->cancellationToken = $cancellationToken;
                $this->timeoutSeconds = $timeoutSeconds;

                throw new McpClientInterruptedException('The client cancelled the request.');
            }
        };

        $accessor = new StackToolExecutionContextAccessor();
        $invoker = new McpToolInvoker($manager, $accessor, new McpResultMapper(), new TestLogger());

        try {
            $accessor->with(
                new ToolContext('run-cancel', 1, 'tc-1', 'fixture_slow', $token, 7),
                static fn () => $invoker->invoke('fixture', 'slow', ['value' => 1]),
            );
            $this->fail('Interrupted MCP calls must become non-retryable ToolCallException.');
        } catch (ToolCallException $e) {
            $this->assertSame('The client cancelled the request.', $e->getMessage());
            $this->assertFalse($e->retryable());
            $this->assertSame('The MCP tool call was cancelled or timed out.', $e->hint());
            $this->assertInstanceOf(McpClientInterruptedException::class, $e->getPrevious());
        }

        $this->assertSame('run-cancel', $manager->runId);
        $this->assertSame('fixture', $manager->serverName);
        $this->assertSame('slow', $manager->toolName);
        $this->assertSame(['value' => 1], $manager->arguments);
        $this->assertSame($token, $manager->cancellationToken);
        $this->assertSame(7, $manager->timeoutSeconds);
    }

    public function testInvokeKeepsInvocationFailuresRetryable(): void
    {
        $manager = new class implements McpConnectionManagerInterface {
            public function discover(string $runId, ?callable $onServerDiscovered = null): array
            {
                return [];
            }

            public function disconnectAll(string $runId): void
            {
            }

            public function callTool(string $runId, string $serverName, string $toolName, array $arguments = [], ?CancellationTokenInterface $cancellationToken = null, ?int $timeoutSeconds = null): array
            {
                throw new McpClientInvocationException('MCP server dropped the connection.');
            }
        };

        $accessor = new StackToolExecutionContextAccessor();
        $invoker = new McpToolInvoker($manager, $accessor, new McpResultMapper(), new TestLogger());
        $token = new class implements CancellationTokenInterface {
            public function isCancellationRequested(): bool
            {
                return false;
            }
        };

        try {
            $accessor->with(
                new ToolContext('run-fail', 1, 'tc-2', 'fixture_slow', $token, null),
                static fn () => $invoker->invoke('fixture', 'slow', []),
            );
            $this->fail('Generic MCP invocation failures must become retryable ToolCallException.');
        } catch (ToolCallException $e) {
            $this->assertSame('MCP server dropped the connection.', $e->getMessage());
            $this->assertTrue($e->retryable());
            $this->assertInstanceOf(McpClientInvocationException::class, $e->getPrevious());
        }
    }
}
