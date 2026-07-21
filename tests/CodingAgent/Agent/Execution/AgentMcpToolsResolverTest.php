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
use Ineersa\CodingAgent\Mcp\Config\McpServerAvailabilityEnum;
use Ineersa\CodingAgent\Mcp\Config\McpServerDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Config\McpTransportTypeEnum;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
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

        $this->assertSame([], $result['non_mcp_tools']);
        $this->assertSame(['context7_resolve'], $result['mcp_runtime_tools']);
        $this->assertSame(['context7_resolve', 'websearch_search'], $result['catalog_mcp_runtime_tools']);
        $this->assertSame('inherited_global', $result['mcp_policy']['mode']);
    }

    public function testExplicitToolsWithoutMcpSelectorsDeniesMcp(): void
    {
        $resolver = $this->createResolver();
        $result = $resolver->resolve(['read', 'bash'], 'parent-run');

        $this->assertSame(['read', 'bash'], $result['non_mcp_tools']);
        $this->assertSame([], $result['mcp_runtime_tools']);
        $this->assertSame('none', $result['mcp_policy']['mode']);
    }

    public function testMcpDenySelector(): void
    {
        $resolver = $this->createResolver();
        $result = $resolver->resolve(['read', 'mcp:-'], 'parent-run');

        $this->assertSame([], $result['mcp_runtime_tools']);
    }

    public function testMcpStarAllowsSpecificServerTools(): void
    {
        $resolver = $this->createResolver();
        $result = $resolver->resolve(['mcp:*'], 'parent-run');

        $this->assertContains('context7_resolve', $result['mcp_runtime_tools']);
        $this->assertContains('websearch_search', $result['mcp_runtime_tools']);
        $this->assertSame('all', $result['mcp_policy']['mode']);
    }

    public function testConcreteAndTerminalStarPrefixSelectors(): void
    {
        $resolver = $this->createResolver();
        // Terminal-star is the only prefix wildcard; trailing `_` alone is no longer special.
        $result = $resolver->resolve(['mcp:websearch_search', 'mcp:context7_*'], 'parent-run');

        $this->assertSame(['websearch_search', 'context7_resolve'], $result['mcp_runtime_tools']);
        $this->assertSame('specific', $result['mcp_policy']['mode']);
    }

    public function testTrailingUnderscoreSelectorIsExactNotPrefix(): void
    {
        // Hand-built catalog name ending `_` proves the grammar: no terminal star => exact only.
        // Old production code would expand `mcp:websearch_` as a prefix over every websearch_* tool.
        $resolver = $this->createResolver([
            'websearch' => ['websearch_', 'websearch_search'],
        ]);

        $result = $resolver->resolve(['mcp:websearch_'], 'parent-run');

        $this->assertSame(['websearch_'], $result['mcp_runtime_tools']);
        $this->assertNotContains('websearch_search', $result['mcp_runtime_tools']);
        $this->assertSame('specific', $result['mcp_policy']['mode']);
    }

    public function testEmbeddedOrMultipleStarsAreNotGlobs(): void
    {
        $resolver = $this->createResolver();

        $embedded = $resolver->resolve(['mcp:web*search_search'], 'parent-run');
        $this->assertSame([], $embedded['mcp_runtime_tools']);

        $multi = $resolver->resolve(['mcp:websearch**'], 'parent-run');
        $this->assertSame([], $multi['mcp_runtime_tools']);
    }

    /**
     * @param array<string, list<string>>|null $extraServerTools When non-null, provided server entries replace that server's default catalog runtime-name list (array_replace top-level semantics)
     */
    private function createResolver(?array $extraServerTools = null): AgentMcpToolsResolver
    {
        $defaultServerTools = [
            'context7' => ['context7_resolve'],
            'websearch' => ['websearch_search'],
        ];
        $serverTools = null === $extraServerTools
            ? $defaultServerTools
            : array_replace($defaultServerTools, $extraServerTools);

        $servers = [];
        foreach ($serverTools as $serverName => $hatfieldNames) {
            $toolDefs = [];
            foreach ($hatfieldNames as $hatfieldName) {
                $toolDefs[] = new McpToolDefinitionDTO(
                    hatfieldName: $hatfieldName,
                    serverName: $serverName,
                    mcpName: $hatfieldName,
                    description: $hatfieldName,
                    inputSchema: ['type' => 'object'],
                );
            }
            $servers[$serverName] = new McpServerCatalogEntryDTO(
                serverName: $serverName,
                transport: 'http',
                status: McpServerCatalogStatusEnum::CONNECTED,
                tools: $toolDefs,
            );
        }

        $catalog = new McpToolCatalogDTO(
            runId: 'parent-run',
            generatedAt: '2026-01-01T00:00:00Z',
            configHash: 'hash',
            servers: $servers,
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

        $loader = TestMcpConfigLoaderFactory::loaderForServers($config->servers);

        return new AgentMcpToolsResolver($catalogStore, $loader);
    }
}
