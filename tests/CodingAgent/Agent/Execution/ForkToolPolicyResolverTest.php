<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\CodingAgent\Agent\Execution\ForkExecutionService;
use Ineersa\CodingAgent\Agent\Execution\ForkToolPolicyResolver;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

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
}
