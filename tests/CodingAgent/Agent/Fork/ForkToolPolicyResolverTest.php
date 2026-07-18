<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\CodingAgent\Agent\Execution\AgentMcpToolsResolver;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Agent\Fork\ForkToolPolicyResolver;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;

final class ForkToolPolicyResolverTest extends IsolatedKernelTestCase
{
    public function testForkChildPolicyExcludesForkAndSubagent(): void
    {
        $resolver = self::getContainer()->get(ForkToolPolicyResolver::class);
        $policy = $resolver->resolve('parent-policy-1');

        $this->assertNotContains('fork', $policy['tools']);
        $this->assertNotContains('subagent', $policy['tools']);
        $this->assertNotSame([], $policy['tools']);
    }

    public function testForkChildPolicyReusesConfiguredSubagentExcludedTools(): void
    {
        // Thesis: SETTINGS-03 agents.subagent_excluded_tools applies to fork children via the
        // canonical AgentToolPolicyResolver; recursion tools stay excluded; unrelated tools remain.
        $registry = $this->createStub(ToolRegistryInterface::class);
        $registry->method('activeToolNames')->willReturn([
            'read',
            'bash',
            'settings',
            'documentation',
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

        $agentsConfig = new AgentsConfig(subagentExcludedTools: ['settings', 'custom_excluded']);
        $resolver = new ForkToolPolicyResolver(
            new AgentToolPolicyResolver($registry, $mcpResolver, $agentsConfig),
        );

        $policy = $resolver->resolve('parent-policy-exclusions');

        $this->assertContains('read', $policy['tools']);
        $this->assertContains('bash', $policy['tools']);
        $this->assertNotContains('settings', $policy['tools']);
        $this->assertNotContains('custom_excluded', $policy['tools']);
        // documentation is not in this configured denylist → remains.
        $this->assertContains('documentation', $policy['tools']);
        $this->assertNotContains('fork', $policy['tools']);
        $this->assertNotContains('subagent', $policy['tools']);
    }
}
