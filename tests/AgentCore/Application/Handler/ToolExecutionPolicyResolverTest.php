<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ToolExecutionPolicyResolver;
use Ineersa\AgentCore\Contract\Tool\ToolExecutionSettingsInterface;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\AgentCore\Application\Handler\ToolExecutionPolicyResolver
 */
final class ToolExecutionPolicyResolverTest extends TestCase
{
    public function testResolveReturnsDefaultMode(): void
    {
        $resolver = new ToolExecutionPolicyResolver('sequential', null, 4);

        $policy = $resolver->resolve('read');

        $this->assertSame(ToolExecutionMode::Sequential, $policy->mode);
        $this->assertNull($policy->timeoutSeconds);
        $this->assertSame(4, $policy->maxParallelism);
    }

    public function testResolveReturnsConfiguredDefaultMode(): void
    {
        $resolver = new ToolExecutionPolicyResolver('parallel', null, 4);

        $policy = $resolver->resolve('any_tool');

        $this->assertSame(ToolExecutionMode::Parallel, $policy->mode);
    }

    public function testResolveWithZeroDefaultTimeoutMeansNoPostHocTimeout(): void
    {
        $resolver = new ToolExecutionPolicyResolver('sequential', 0, 4);

        $policy = $resolver->resolve('any_tool');

        $this->assertNull($policy->timeoutSeconds);
    }

    public function testResolveWithExplicitDefaultTimeout(): void
    {
        $resolver = new ToolExecutionPolicyResolver('sequential', 45, 4);

        $policy = $resolver->resolve('any_tool');

        $this->assertSame(45, $policy->timeoutSeconds);
    }

    public function testResolveClampsMaxParallelismToAtLeastOne(): void
    {
        $resolver = new ToolExecutionPolicyResolver('sequential', null, 0);

        $policy = $resolver->resolve('any_tool');

        $this->assertSame(1, $policy->maxParallelism);
    }

    public function testResolveFromSettings(): void
    {
        $settings = $this->createStub(ToolExecutionSettingsInterface::class);
        $settings->method('defaultMode')->willReturn('sequential');
        $settings->method('defaultTimeoutSeconds')->willReturn(null);
        $settings->method('maxParallelism')->willReturn(4);

        $resolver = ToolExecutionPolicyResolver::fromSettings($settings);

        $policy = $resolver->resolve('any_tool');

        $this->assertSame(ToolExecutionMode::Sequential, $policy->mode);
    }
}
