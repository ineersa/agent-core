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
    public function testResolveReturnsDefaultModeWhenNoOverrides(): void
    {
        $resolver = new ToolExecutionPolicyResolver('sequential', 300, 4, []);

        $policy = $resolver->resolve('read');

        self::assertSame(ToolExecutionMode::Sequential, $policy->mode);
        self::assertSame(300, $policy->timeoutSeconds);
        self::assertSame(4, $policy->maxParallelism);
    }

    public function testResolveReturnsOverrideModeForConfiguredTool(): void
    {
        $resolver = new ToolExecutionPolicyResolver('parallel', 300, 4, [
            'write' => ['mode' => 'sequential'],
            'edit' => ['mode' => 'sequential'],
        ]);

        $writePolicy = $resolver->resolve('write');
        $editPolicy = $resolver->resolve('edit');
        $readPolicy = $resolver->resolve('read');

        // Overridden tools should be sequential despite default parallel
        self::assertSame(ToolExecutionMode::Sequential, $writePolicy->mode);
        self::assertSame(ToolExecutionMode::Sequential, $editPolicy->mode);

        // Non-overridden tool should use default (parallel)
        self::assertSame(ToolExecutionMode::Parallel, $readPolicy->mode);
    }

    public function testResolveReturnsOverrideTimeoutForConfiguredTool(): void
    {
        $resolver = new ToolExecutionPolicyResolver('sequential', 300, 4, [
            'long_running' => ['timeout_seconds' => 600],
        ]);

        $overridePolicy = $resolver->resolve('long_running');
        $defaultPolicy = $resolver->resolve('normal_tool');

        self::assertSame(600, $overridePolicy->timeoutSeconds);
        self::assertSame(300, $defaultPolicy->timeoutSeconds);
    }

    public function testResolveClampsTimeoutToAtLeastOneSecond(): void
    {
        $resolver = new ToolExecutionPolicyResolver('sequential', 0, 4, []);

        $policy = $resolver->resolve('any_tool');

        self::assertSame(1, $policy->timeoutSeconds);
    }

    public function testResolveClampsMaxParallelismToAtLeastOne(): void
    {
        $resolver = new ToolExecutionPolicyResolver('sequential', 300, 0, []);

        $policy = $resolver->resolve('any_tool');

        self::assertSame(1, $policy->maxParallelism);
    }

    public function testResolveFromSettingsEmptyOverrides(): void
    {
        $settings = $this->createStub(ToolExecutionSettingsInterface::class);
        $settings->method('defaultMode')->willReturn('sequential');
        $settings->method('defaultTimeoutSeconds')->willReturn(300);
        $settings->method('maxParallelism')->willReturn(4);

        $resolver = ToolExecutionPolicyResolver::fromSettings($settings);

        $policy = $resolver->resolve('any_tool');

        self::assertSame(ToolExecutionMode::Sequential, $policy->mode);
    }

    public function testResolveFromSettingsWithOverrides(): void
    {
        $settings = $this->createStub(ToolExecutionSettingsInterface::class);
        $settings->method('defaultMode')->willReturn('parallel');
        $settings->method('defaultTimeoutSeconds')->willReturn(300);
        $settings->method('maxParallelism')->willReturn(4);

        $resolver = ToolExecutionPolicyResolver::fromSettings($settings, [
            'write' => ['mode' => 'sequential'],
        ]);

        $writePolicy = $resolver->resolve('write');
        $defaultPolicy = $resolver->resolve('any_tool');

        self::assertSame(ToolExecutionMode::Sequential, $writePolicy->mode);
        self::assertSame(ToolExecutionMode::Parallel, $defaultPolicy->mode);
    }
}
