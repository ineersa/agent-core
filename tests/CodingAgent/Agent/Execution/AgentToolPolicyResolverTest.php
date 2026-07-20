<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Config\AgentsConfig;
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
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentToolPolicyResolver::class)]
final class AgentToolPolicyResolverTest extends TestCase
{
    public function testResolveExcludesSubagentByDefault(): void
    {
        $resolver = new AgentToolPolicyResolver($this->registry(['read']), $this->mcpResolver([]), new AgentsConfig());
        $policy = $resolver->resolve($this->definition(['read', 'subagent', 'fork']), 'run-1');
        $this->assertNotContains('subagent', $policy['tools']);
        $this->assertNotContains('fork', $policy['tools']);
    }

    public function testOmittedToolsInheritsRegistryAndGlobalMcpOnly(): void
    {
        // Thesis: active registry already contains every catalog-registered MCP tool
        // (global + availability-specific). Omitted tools must strip the full catalog MCP
        // set and re-add only globally available MCP tools — not leak specific servers.
        $resolver = new AgentToolPolicyResolver(
            $this->registry(['read', 'context7_resolve', 'websearch_search', 'subagent', 'fork']),
            $this->mcpResolver(['context7_resolve', 'websearch_search'], allServers: true),
            new AgentsConfig(),
        );
        $policy = $resolver->resolve($this->definition(null), 'run-1');

        $this->assertSame(['read', 'context7_resolve'], $policy['tools']);
        $this->assertSame('inherited_global', $policy['mcp']['mode']);
        $this->assertSame(['context7_resolve'], $policy['mcp']['tools']);
    }

    public function testStructuralRecursionToolsAlwaysRemovedEvenWhenConfiguredExclusionsEmpty(): void
    {
        // Thesis A: child policy always removes fork and subagent even when
        // agents.subagent_excluded_tools is [] and the definition names both tools.
        $resolver = new AgentToolPolicyResolver(
            $this->registry(['read', 'subagent', 'fork']),
            $this->mcpResolver([]),
            new AgentsConfig(subagentExcludedTools: []),
        );
        $policy = $resolver->resolve($this->definition(['read', 'subagent', 'fork']), 'run-1');

        $this->assertContains('read', $policy['tools']);
        $this->assertNotContains('subagent', $policy['tools']);
        $this->assertNotContains('fork', $policy['tools']);
    }

    public function testExplicitToolsMergeMcpSelectors(): void
    {
        // Explicit mcp: selectors may still include availability-specific tools even when
        // the active registry snapshot already lists both global and specific MCP names.
        $resolver = new AgentToolPolicyResolver(
            $this->registry(['read', 'context7_resolve', 'websearch_search']),
            $this->mcpResolver(['context7_resolve', 'websearch_search'], allServers: true),
            new AgentsConfig(),
        );
        $policy = $resolver->resolve($this->definition(['read', 'mcp:websearch_search']), 'run-1');
        $this->assertSame(['read', 'websearch_search'], $policy['tools']);
        $this->assertSame('specific', $policy['mcp']['mode']);
        $this->assertSame(['websearch_search'], $policy['mcp']['tools']);
    }

    /**
     * @return iterable<string, array{0: list<string>|null}>
     */
    public static function childToolListCases(): iterable
    {
        yield 'omitted inherit-all' => [null];
        yield 'explicit list' => [['read', 'settings', 'hatfield_docs', 'bash']];
    }

    /**
     * @param list<string>|null $tools
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('childToolListCases')]
    public function testDefaultExcludedToolsRemovedForChildren(?array $tools): void
    {
        $resolver = new AgentToolPolicyResolver(
            $this->registry(['read', 'settings', 'hatfield_docs', 'bash', 'subagent']),
            $this->mcpResolver([]),
            new AgentsConfig(),
        );
        $policy = $resolver->resolve($this->definition($tools), 'run-1');

        $this->assertContains('read', $policy['tools']);
        $this->assertContains('bash', $policy['tools']);
        $this->assertNotContains('settings', $policy['tools']);
        $this->assertNotContains('hatfield_docs', $policy['tools']);
        $this->assertNotContains('subagent', $policy['tools']);
    }

    /** @param list<string> $globalTools */
    private function mcpResolver(array $globalTools, bool $allServers = false): AgentMcpToolsResolver
    {
        $catalogTools = $allServers ? ['context7_resolve', 'websearch_search'] : $globalTools;
        $servers = [];
        if ($allServers || \in_array('context7_resolve', $catalogTools, true)) {
            $servers['context7'] = new McpServerCatalogEntryDTO('context7', 'http', McpServerCatalogStatusEnum::CONNECTED, tools: [
                new McpToolDefinitionDTO('context7_resolve', 'context7', 'resolve', 'd', ['type' => 'object']),
            ]);
        }
        if ($allServers || \in_array('websearch_search', $catalogTools, true)) {
            $servers['websearch'] = new McpServerCatalogEntryDTO('websearch', 'http', McpServerCatalogStatusEnum::CONNECTED, tools: [
                new McpToolDefinitionDTO('websearch_search', 'websearch', 'search', 'd', ['type' => 'object']),
            ]);
        }
        $catalog = new McpToolCatalogDTO(runId: 'run-1', generatedAt: 't', configHash: 'h', servers: $servers);
        $catalogStore = $this->createStub(McpToolCatalogStoreInterface::class);
        $catalogStore->method('read')->willReturn($catalog);
        $config = McpConfigDTO::fromServers([
            'context7' => new McpServerDefinitionDTO('context7', url: 'u', transportType: McpTransportTypeEnum::HTTP, availability: McpServerAvailabilityEnum::All),
            'websearch' => new McpServerDefinitionDTO('websearch', url: 'u', transportType: McpTransportTypeEnum::HTTP, availability: McpServerAvailabilityEnum::Specific),
        ]);
        $loader = TestMcpConfigLoaderFactory::loaderForServers($config->servers);

        return new AgentMcpToolsResolver($catalogStore, $loader);
    }

    /** @param list<string> $tools */
    private function registry(array $tools): ToolRegistryInterface
    {
        $registry = $this->createStub(ToolRegistryInterface::class);
        $registry->method('activeToolNames')->willReturn($tools);

        return $registry;
    }

    /** @param list<string>|null $tools */
    private function definition(?array $tools): AgentDefinitionDTO
    {
        return new AgentDefinitionDTO(
            name: 'test-agent',
            description: 'Test',
            tools: $tools,
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'x',
        );
    }
}
