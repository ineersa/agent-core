<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiAgentRetryConfig;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use PHPUnit\Framework\TestCase;

final class AiAgentRetryConfigTest extends TestCase
{
    public function testFromArrayResolvesDefaultAndExplicitValues(): void
    {
        $this->assertSame(3, AiAgentRetryConfig::fromArray([])->resolveMaxAttempts());
        $this->assertSame(5, AiAgentRetryConfig::fromArray(['max_attempts' => 5])->resolveMaxAttempts());
        $this->assertSame(3, AiAgentRetryConfig::fromArray(['max_attempts' => '3'])->resolveMaxAttempts());
    }

    public function testAiConfigReadsAgentRetry(): void
    {
        $config = AiConfig::fromArray(['agent_retry' => ['max_attempts' => 1]]);

        $this->assertSame(1, $config->agentRetry->resolveMaxAttempts());
    }

    public function testInvalidValueThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AiAgentRetryConfig::fromArray(['max_attempts' => -1]);
    }
}
