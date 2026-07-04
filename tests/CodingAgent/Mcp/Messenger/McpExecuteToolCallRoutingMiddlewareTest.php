<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Messenger;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogEntryDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Messenger\McpExecuteToolCallRoutingMiddleware;
use Ineersa\CodingAgent\Tests\Support\Messenger\TestStack;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Test thesis 1: The middleware adds TransportNamesStamp(['mcp']) only for
 * ExecuteToolCall messages whose toolName matches an MCP dynamic tool in
 * the session catalog.
 *
 * Test thesis 2: Non-MCP tool names, non-ExecuteToolCall messages, messages
 * with ReceivedStamp, and messages already carrying TransportNamesStamp
 * pass through unmodified.
 *
 * Test thesis 3: A missing catalog is a no-op (passes through).
 *
 * Test thesis 4: Catalog read failures are re-thrown.
 */
final class McpExecuteToolCallRoutingMiddlewareTest extends TestCase
{
    private TestLogger $logger;
    /** @var array<string, McpToolCatalogDTO> */
    private array $catalogStoreData = [];

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
        $this->catalogStoreData = [];
    }

    // ── Test thesis 1: MCP tool gets stamped ──

    public function testStampsMcpTransportForCatalogBackedTool(): void
    {
        $catalog = $this->makeCatalog('run-1', 'echo', 'echo_reverse');
        $middleware = $this->makeMiddleware(['run-1' => $catalog]);
        $message = new ExecuteToolCall(
            runId: 'run-1',
            turnNo: 1,
            stepId: 's1',
            attempt: 1,
            idempotencyKey: 'ik1',
            toolCallId: 'tc1',
            toolName: 'echo_reverse',
            args: ['text' => 'hello'],
            orderIndex: 0,
        );

        $envelope = new Envelope($message);

        $result = $middleware->handle($envelope, new TestStack());

        $stamp = $result->last(TransportNamesStamp::class);
        $this->assertNotNull($stamp, 'Expected TransportNamesStamp for MCP tool');
        $this->assertSame(['mcp'], $stamp->getTransportNames());
    }

    // ── Test thesis 2: Non-MCP tool passes through ──

    public function testDoesNotStampNonMcpTool(): void
    {
        $catalog = $this->makeCatalog('run-1', 'echo', 'echo_reverse');
        $middleware = $this->makeMiddleware(['run-1' => $catalog]);
        $message = new ExecuteToolCall(
            runId: 'run-1',
            turnNo: 1,
            stepId: 's1',
            attempt: 1,
            idempotencyKey: 'ik1',
            toolCallId: 'tc1',
            toolName: 'read',
            args: ['path' => './f.txt'],
            orderIndex: 0,
        );

        $envelope = new Envelope($message);

        $result = $middleware->handle($envelope, new TestStack());

        $this->assertNull($result->last(TransportNamesStamp::class), 'Non-MCP tool should not be stamped');
    }

    // ── Non-ExecuteToolCall messages pass through ──

    public function testIgnoresNonExecuteToolCallMessages(): void
    {
        $middleware = $this->makeMiddleware([]);
        $stdMessage = new \stdClass();
        $envelope = new Envelope($stdMessage);

        $result = $middleware->handle($envelope, new TestStack());

        $this->assertNull($result->last(TransportNamesStamp::class));
    }

    // ── ReceivedStamp skip ──

    public function testSkipsMessagesWithReceivedStamp(): void
    {
        $catalog = $this->makeCatalog('run-1', 'echo', 'echo_reverse');
        $middleware = $this->makeMiddleware(['run-1' => $catalog]);
        $message = new ExecuteToolCall(
            runId: 'run-1',
            turnNo: 1,
            stepId: 's1',
            attempt: 1,
            idempotencyKey: 'ik1',
            toolCallId: 'tc1',
            toolName: 'echo_reverse',
            args: [],
            orderIndex: 0,
        );

        $envelope = (new Envelope($message))->with(new ReceivedStamp('mcp'));

        $result = $middleware->handle($envelope, new TestStack());

        $this->assertNotNull($result->last(ReceivedStamp::class));
        $this->assertNull($result->last(TransportNamesStamp::class), 'Already-consumed message should not be stamped');
    }

    // ── Existing TransportNamesStamp skip ──

    public function testSkipsMessagesWithExistingTransportNamesStamp(): void
    {
        $catalog = $this->makeCatalog('run-1', 'echo', 'echo_reverse');
        $middleware = $this->makeMiddleware(['run-1' => $catalog]);
        $message = new ExecuteToolCall(
            runId: 'run-1',
            turnNo: 1,
            stepId: 's1',
            attempt: 1,
            idempotencyKey: 'ik1',
            toolCallId: 'tc1',
            toolName: 'echo_reverse',
            args: [],
            orderIndex: 0,
        );

        $envelope = (new Envelope($message))->with(new TransportNamesStamp(['tool']));

        $result = $middleware->handle($envelope, new TestStack());

        $stamp = $result->last(TransportNamesStamp::class);
        $this->assertNotNull($stamp);
        $this->assertSame(['tool'], $stamp->getTransportNames(), 'Existing stamp should not be overwritten');
    }

    // ── Test thesis 3: Missing catalog is a no-op ──

    public function testMissingCatalogIsNoOp(): void
    {
        // Empty catalog store — all reads return null
        $middleware = $this->makeMiddleware([]);
        $message = new ExecuteToolCall(
            runId: 'run-1',
            turnNo: 1,
            stepId: 's1',
            attempt: 1,
            idempotencyKey: 'ik1',
            toolCallId: 'tc1',
            toolName: 'echo_reverse',
            args: [],
            orderIndex: 0,
        );

        $envelope = new Envelope($message);

        $result = $middleware->handle($envelope, new TestStack());

        $this->assertNull($result->last(TransportNamesStamp::class));
    }

    // ── Test thesis 4: Catalog read failure is re-thrown ──

    public function testRethrowsCatalogReadFailure(): void
    {
        $failingStore = new class implements McpToolCatalogStoreInterface {
            public function write(string $runId, McpToolCatalogDTO $catalog): void
            {
            }

            public function read(string $runId): ?McpToolCatalogDTO
            {
                throw new \RuntimeException('Catalog I/O failure');
            }
        };

        $middleware = new McpExecuteToolCallRoutingMiddleware($failingStore, $this->logger);
        $message = new ExecuteToolCall(
            runId: 'run-1',
            turnNo: 1,
            stepId: 's1',
            attempt: 1,
            idempotencyKey: 'ik1',
            toolCallId: 'tc1',
            toolName: 'echo_reverse',
            args: [],
            orderIndex: 0,
        );
        $envelope = new Envelope($message);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Catalog I/O failure');
        $middleware->handle($envelope, new TestStack());

        $this->assertGreaterThanOrEqual(1, \count($this->logger->records), 'Catalog read failure should be logged');
    }

    // ── Only tools from CONNECTED servers are stamped ──

    public function testDoesNotStampToolsFromFailedServers(): void
    {
        $catalog = new McpToolCatalogDTO(
            runId: 'run-1',
            servers: [
                'down-server' => new McpServerCatalogEntryDTO(
                    serverName: 'down-server',
                    transport: 'stdio',
                    status: McpServerCatalogStatusEnum::FAILED,
                    tools: [
                        new McpToolDefinitionDTO(
                            hatfieldName: 'down_fetch',
                            serverName: 'down-server',
                            mcpName: 'fetch',
                            description: 'Fetch from failed server',
                            inputSchema: [],
                        ),
                    ],
                ),
            ],
        );

        $middleware = $this->makeMiddleware(['run-1' => $catalog]);
        $message = new ExecuteToolCall(
            runId: 'run-1',
            turnNo: 1,
            stepId: 's1',
            attempt: 1,
            idempotencyKey: 'ik1',
            toolCallId: 'tc1',
            toolName: 'down_fetch',
            args: [],
            orderIndex: 0,
        );

        $envelope = new Envelope($message);

        $result = $middleware->handle($envelope, new TestStack());

        $this->assertNull($result->last(TransportNamesStamp::class), 'Tools from failed servers should not be stamped');
    }

    // ── Helper methods ──

    private function makeCatalog(string $runId, string $serverName, string $hatfieldName): McpToolCatalogDTO
    {
        return new McpToolCatalogDTO(
            runId: $runId,
            servers: [
                $serverName => new McpServerCatalogEntryDTO(
                    serverName: $serverName,
                    transport: 'stdio',
                    status: McpServerCatalogStatusEnum::CONNECTED,
                    tools: [
                        new McpToolDefinitionDTO(
                            hatfieldName: $hatfieldName,
                            serverName: $serverName,
                            mcpName: 'reverse',
                            description: 'Reverse text',
                            inputSchema: ['type' => 'object'],
                        ),
                    ],
                ),
            ],
        );
    }

    /**
     * @param array<string, McpToolCatalogDTO> $storeData
     */
    private function makeMiddleware(array $storeData): McpExecuteToolCallRoutingMiddleware
    {
        $store = new class($storeData) implements McpToolCatalogStoreInterface {
            /** @param array<string, McpToolCatalogDTO> $data */
            public function __construct(private array $data)
            {
            }

            public function write(string $runId, McpToolCatalogDTO $catalog): void
            {
            }

            public function read(string $runId): ?McpToolCatalogDTO
            {
                return $this->data[$runId] ?? null;
            }
        };

        return new McpExecuteToolCallRoutingMiddleware($store, $this->logger);
    }
}
