<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Agent\Fork\ForkToolPolicyResolver;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\TestCase;

final class ForkToolPolicyResolverTest extends TestCase
{
    public function testForkChildPolicyReusesConfiguredSubagentExcludedTools(): void
    {
        // Thesis: SETTINGS-03 agents.subagent_excluded_tools applies to fork children via the
        // canonical AgentToolPolicyResolver; recursion tools stay excluded; unrelated tools remain.
        $registry = $this->createStub(ToolRegistryInterface::class);
        $registry->method('activeToolNames')->willReturn([
            'read',
            'bash',
            'hatfield_docs',
            'custom_excluded',
            'fork',
            'subagent',
        ]);

        $catalogStore = $this->createStub(McpToolCatalogStoreInterface::class);
        $catalogStore->method('read')->willReturn(null);
        $mcpResolver = new AgentMcpToolsResolver(
            $catalogStore,
            TestMcpConfigLoaderFactory::loaderForServers([]),
        );

        $agentsConfig = new AgentsConfig(subagentExcludedTools: ['custom_excluded']);
        $resolver = new ForkToolPolicyResolver(
            new AgentToolPolicyResolver($registry, $mcpResolver, $agentsConfig),
        );

        $policy = $resolver->resolve('parent-policy-exclusions');

        $this->assertContains('read', $policy['tools']);
        $this->assertContains('bash', $policy['tools']);
        $this->assertNotContains('custom_excluded', $policy['tools']);
        // hatfield_docs is not in this configured denylist → remains.
        $this->assertContains('hatfield_docs', $policy['tools']);
        $this->assertNotContains('fork', $policy['tools']);
        $this->assertNotContains('subagent', $policy['tools']);
    }
}
