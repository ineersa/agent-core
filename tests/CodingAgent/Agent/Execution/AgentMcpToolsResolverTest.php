<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogEntryDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Config\McpConfigDTO;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Config\McpServerAvailabilityEnum;
use Ineersa\CodingAgent\Mcp\Config\McpServerDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Config\McpTransportTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: without catalog + availability + mcp: selector expansion, child/parent MCP exposure cannot be enforced.
 */
#[CoversClass(AgentMcpToolsResolver::class)]
final class AgentMcpToolsResolverTest extends TestCase
{
    public function testOmittedToolsInheritsOnlyGlobalAvailabilityServers(): void
    {
        $resolver = $this->createResolver();
        $result = $resolver->resolve(null, 'parent-run');

        self::assertSame([], $result['non_mcp_tools']);
        self::assertSame(['context7_resolve'], $result['mcp_runtime_tools']);
        self::assertSame('inherited_global', $result['mcp_policy']['mode']);
    }

    public function testExplicitToolsWithoutMcpSelectorsDeniesMcp(): void
    {
        $resolver = $this->createResolver();
        $result = $resolver->resolve(['read', 'bash'], 'parent-run');

        self::assertSame(['read', 'bash'], $result['non_mcp_tools']);
        self::assertSame([], $result['mcp_runtime_tools']);
        self::assertSame('none', $result['mcp_policy']['mode']);
    }

    public function testMcpDenySelector(): void
    {
        $resolver = $this->createResolver();
        $result = $resolver->resolve(['read', 'mcp:-'], 'parent-run');

        self::assertSame([], $result['mcp_runtime_tools']);
    }

    public function testMcpStarAllowsSpecificServerTools(): void
    {
        $resolver = $this->createResolver();
        $result = $resolver->resolve(['mcp:*'], 'parent-run');

        self::assertContains('context7_resolve', $result['mcp_runtime_tools']);
        self::assertContains('websearch_search', $result['mcp_runtime_tools']);
        self::assertSame('all', $result['mcp_policy']['mode']);
    }

    public function testConcreteAndPrefixSelectors(): void
    {
        $resolver = $this->createResolver();
        $result = $resolver->resolve(['mcp:websearch_search', 'mcp:context7_'], 'parent-run');

        self::assertSame(['websearch_search', 'context7_resolve'], $result['mcp_runtime_tools']);
        self::assertSame('specific', $result['mcp_policy']['mode']);
    }

    private function createResolver(): AgentMcpToolsResolver
    {
        $catalog = new McpToolCatalogDTO(
            runId: 'parent-run',
            generatedAt: '2026-01-01T00:00:00Z',
            configHash: 'hash',
            servers: [
                'context7' => new McpServerCatalogEntryDTO(
                    serverName: 'context7',
                    transport: 'http',
                    status: McpServerCatalogStatusEnum::CONNECTED,
                    tools: [
                        new McpToolDefinitionDTO(
                            hatfieldName: 'context7_resolve',
                            serverName: 'context7',
                            mcpName: 'resolve',
                            description: 'ctx',
                            inputSchema: ['type' => 'object'],
                        ),
                    ],
                ),
                'websearch' => new McpServerCatalogEntryDTO(
                    serverName: 'websearch',
                    transport: 'http',
                    status: McpServerCatalogStatusEnum::CONNECTED,
                    tools: [
                        new McpToolDefinitionDTO(
                            hatfieldName: 'websearch_search',
                            serverName: 'websearch',
                            mcpName: 'search',
                            description: 'search',
                            inputSchema: ['type' => 'object'],
                        ),
                    ],
                ),
            ],
        );

        $catalogStore = $this->createStub(McpToolCatalogStoreInterface::class);
        $catalogStore->method('read')->willReturn($catalog);

        $config = McpConfigDTO::fromServers([
            'context7' => new McpServerDefinitionDTO(
                name: 'context7',
                url: 'https://example.test/mcp',
                transportType: McpTransportTypeEnum::HTTP,
                availability: McpServerAvailabilityEnum::All,
            ),
            'websearch' => new McpServerDefinitionDTO(
                name: 'websearch',
                url: 'https://example.test/sse',
                transportType: McpTransportTypeEnum::HTTP,
                availability: McpServerAvailabilityEnum::Specific,
            ),
        ]);

        $loader = $this->createStub(McpConfigLoader::class);
        $loader->method('load')->willReturn($config);

        return new AgentMcpToolsResolver($catalogStore, $loader);
    }
}
