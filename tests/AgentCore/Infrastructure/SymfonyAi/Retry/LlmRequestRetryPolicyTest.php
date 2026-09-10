<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi\Retry;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\Retry\LlmRequestRetryPolicy;
use PHPUnit\Framework\TestCase;

final class LlmRequestRetryPolicyTest extends TestCase
{
    public function testDefaultsAreFiveRetriesBeyondInitialAttempt(): void
    {
        $policy = new LlmRequestRetryPolicy();

        $this->assertSame(5, $policy->maxRetries);
        $this->assertSame(6, $policy->maxAttempts());
        $this->assertTrue($policy->canRetry(0));
        $this->assertTrue($policy->canRetry(4));
        $this->assertFalse($policy->canRetry(5));
    }

    public function testDelayUsesExponentialBackoffAndCaps(): void
    {
        $policy = new LlmRequestRetryPolicy(baseDelayMs: 100, maxDelayMs: 250);

        $this->assertSame(100, $policy->delayMsForAttempt(0));
        $this->assertSame(200, $policy->delayMsForAttempt(1));
        $this->assertSame(250, $policy->delayMsForAttempt(2));
    }
}
