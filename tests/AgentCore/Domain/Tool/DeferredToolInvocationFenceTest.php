<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DeferredToolInvocationFence;
use Ineersa\AgentCore\Domain\Tool\ToolLaunchInputReferenceDTO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class DeferredToolInvocationFenceTest extends TestCase
{
    public function testSameInvocationRequiresFullEnvelopeIncludingParentModelAndLaunchContext(): void
    {
        $fence = $this->fence();
        $launch = new ToolLaunchInputReferenceDTO('subagent', 'run', 1, 'step', 'call', 'parent', str_repeat('a', 64), 8);
        $call = new ExecuteToolCall('run', 1, 'step', 1, 'key', 'call', 'subagent', ['task' => 'x'], 0, parentModel: 'parent', launchContext: $launch);
        $same = new ExecuteToolCall('run', 1, 'step', 1, 'key', 'call', 'subagent', ['task' => 'x'], 0, parentModel: 'parent', launchContext: $launch);
        $differentParent = new ExecuteToolCall('run', 1, 'step', 1, 'key', 'call', 'subagent', ['task' => 'x'], 0, parentModel: 'other', launchContext: $launch);
        $missingLaunch = new ExecuteToolCall('run', 1, 'step', 1, 'key', 'call', 'subagent', ['task' => 'x'], 0, parentModel: 'parent');

        $this->assertTrue($fence->sameInvocation($call, $same));
        $this->assertFalse($fence->sameInvocation($call, $differentParent));
        $this->assertFalse($fence->sameInvocation($call, $missingLaunch));
        $this->assertFalse($fence->sameInvocation($call, null));
    }

    public function testMatchesCorrelationIgnoresEnvelopeOnlyFields(): void
    {
        $fence = $this->fence();
        $correlation = new DeferredToolCompletionCorrelation('deferred', 'run', 1, 'step', 1, 'key', 'call', 'subagent', ['task' => 'x'], 0);
        $matching = new ExecuteToolCall('run', 1, 'step', 1, 'key', 'call', 'subagent', ['task' => 'x'], 0, parentModel: 'parent');
        $differentArgs = new ExecuteToolCall('run', 1, 'step', 1, 'key', 'call', 'subagent', ['task' => 'y'], 0, parentModel: 'parent');

        $this->assertTrue($fence->matchesCorrelation($correlation, $matching));
        $this->assertFalse($fence->matchesCorrelation($correlation, $differentArgs));
        $this->assertTrue($fence->matchesResult(new ToolCallResult('run', 1, 'step', 1, 'key', 'call', 0, ['tool_name' => 'subagent']), $matching));
    }

    private function fence(): DeferredToolInvocationFence
    {
        return new DeferredToolInvocationFence(new Serializer([new ObjectNormalizer()], [new JsonEncoder()]));
    }
}
