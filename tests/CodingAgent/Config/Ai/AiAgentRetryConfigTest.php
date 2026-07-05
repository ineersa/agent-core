<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiAgentRetryConfig;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use PHPUnit\Framework\TestCase;

final class AiAgentRetryConfigTest extends TestCase
{
    public function testFromArrayEmptyUsesResolveDefaults(): void
    {
        $config = AiAgentRetryConfig::fromArray([]);
        $this->assertSame(2, $config->resolveMaxAttempts());
        $this->assertSame(1000, $config->resolveBaseDelayMs());
        $this->assertSame(60000, $config->resolveMaxDelayMs());
    }

    public function testFromArrayExplicitInts(): void
    {
        $config = AiAgentRetryConfig::fromArray([
            'max_attempts' => 5,
            'base_delay_ms' => 250,
            'max_delay_ms' => 5000,
        ]);
        $this->assertSame(5, $config->resolveMaxAttempts());
        $this->assertSame(250, $config->resolveBaseDelayMs());
        $this->assertSame(5000, $config->resolveMaxDelayMs());
    }

    public function testFromArrayNumericString(): void
    {
        $config = AiAgentRetryConfig::fromArray(['max_attempts' => '3']);
        $this->assertSame(3, $config->resolveMaxAttempts());
    }

    public function testAiConfigReadsAgentRetry(): void
    {
        $config = AiConfig::fromArray([
            'agent_retry' => ['max_attempts' => 1, 'base_delay_ms' => 10],
        ]);
        $this->assertSame(1, $config->agentRetry->resolveMaxAttempts());
        $this->assertSame(10, $config->agentRetry->resolveBaseDelayMs());
    }

    public function testInvalidValueThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AiAgentRetryConfig::fromArray(['max_attempts' => 'nope']);
    }
}
