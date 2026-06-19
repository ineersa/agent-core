<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Tool;

use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogEntryDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Tool\McpCatalogRegisteringToolSetResolver;
use Ineersa\CodingAgent\Mcp\Tool\McpToolRegistrar;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis 4: The ToolSetResolver wrapper calls registrar before
 * delegate resolves active names, so LLM-visible schema/allowlist
 * snapshots include catalog-backed MCP tools.
 */
final class McpCatalogRegisteringToolSetResolverTest extends TestCase
{
    public function testRegistersMcpToolsBeforeDelegateResolve(): void
    {
        $registry = new ToolRegistry();

        // Inner resolver delegates to the registry directly
        $inner = new class($registry) implements ToolSetResolverInterface {
            public function __construct(private ToolRegistry $registry)
            {
            }

            public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
            {
                return new ActiveToolSet(
                    toolNames: $this->registry->activeToolNames(),
                    allowListNames: $this->registry->activeToolNames(),
                    executionModes: [],
                );
            }
        };

        $catalog = new McpToolCatalogDTO(
            runId: 'run-xyz',
            servers: [
                'srv' => new McpServerCatalogEntryDTO(
                    serverName: 'srv',
                    transport: 'stdio',
                    status: McpServerCatalogStatusEnum::CONNECTED,
                    tools: [
                        new McpToolDefinitionDTO(
                            hatfieldName: 'srv_calc',
                            serverName: 'srv',
                            mcpName: 'calc',
                            description: 'Calculator',
                            inputSchema: [],
                        ),
                    ],
                ),
            ],
        );

        $store = $this->makeStore(['run-xyz' => $catalog]);
        $registrar = new McpToolRegistrar($store, $registry, new TestLogger());
        $wrapper = new McpCatalogRegisteringToolSetResolver($inner, $registrar);

        $result = $wrapper->resolve('toolset:run:run-xyz:turn:1', turnNo: 1, runId: 'run-xyz');

        $this->assertContains('srv_calc', $result->toolNames, 'MCP tool should be in resolved toolNames');
        $this->assertContains('srv_calc', $result->allowListNames, 'MCP tool should be in execution allowlist');
    }

    public function testDelegatesWithoutRegistrationWhenNoRunId(): void
    {
        $registry = new ToolRegistry();
        // Add a permanent tool so delegate always returns something
        $registry->registerTool(
            name: 'perm',
            description: 'Permanent',
            parametersJsonSchema: [],
            handler: new class implements \Ineersa\CodingAgent\Tool\ToolHandlerInterface {
                public function __invoke(array $arguments = []): string
                {
                    return 'perm';
                }
            },
            promptLine: 'perm: Perm',
        );

        $inner = new class($registry) implements ToolSetResolverInterface {
            public function __construct(private ToolRegistry $registry)
            {
            }

            public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
            {
                return new ActiveToolSet(
                    toolNames: $this->registry->activeToolNames(),
                    allowListNames: $this->registry->activeToolNames(),
                    executionModes: [],
                );
            }
        };

        $store = $this->makeStore([]);
        $registrar = new McpToolRegistrar($store, $registry, new TestLogger());
        $wrapper = new McpCatalogRegisteringToolSetResolver($inner, $registrar);

        // null runId — registration should be skipped
        $result = $wrapper->resolve('toolset:run:unknown:turn:1');

        $this->assertSame(['perm'], $result->toolNames);
    }

    public function testNoOpWhenCatalogMissing(): void
    {
        $registry = new ToolRegistry();

        $inner = new class($registry) implements ToolSetResolverInterface {
            public function __construct(private ToolRegistry $registry)
            {
            }

            public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
            {
                return new ActiveToolSet(
                    toolNames: $this->registry->activeToolNames(),
                    allowListNames: $this->registry->activeToolNames(),
                    executionModes: [],
                );
            }
        };

        // Store has no catalog — read returns null
        $store = $this->makeStore([]);
        $registrar = new McpToolRegistrar($store, $registry, new TestLogger());
        $wrapper = new McpCatalogRegisteringToolSetResolver($inner, $registrar);

        $result = $wrapper->resolve('toolset:run:no-catalog:turn:1', turnNo: 1, runId: 'no-catalog');

        $this->assertSame([], $result->toolNames);
    }

    /** @param array<string, McpToolCatalogDTO> $data */
    private function makeStore(array $data): McpToolCatalogStoreInterface
    {
        return new class($data) implements McpToolCatalogStoreInterface {
            /** @param array<string, McpToolCatalogDTO> $data */
            public function __construct(private array $data)
            {
            }

            public function write(string $runId, McpToolCatalogDTO $catalog): void
            {
                $this->data[$runId] = $catalog;
            }

            public function read(string $runId): ?McpToolCatalogDTO
            {
                return $this->data[$runId] ?? null;
            }
        };
    }
}
