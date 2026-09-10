<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\Retry;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\Retry\LlmRequestRetryPolicy;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiHttpConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Retry\LlmRequestRetryPolicyFactory;
use PHPUnit\Framework\TestCase;

final class LlmRequestRetryPolicyFactoryTest extends TestCase
{
    public function testUsesAiHttpMaxRetriesForApplicationBudgetDefaultsOtherwise(): void
    {
        $defaults = LlmRequestRetryPolicyFactory::fromAppConfig(new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
        ));
        $this->assertSame(LlmRequestRetryPolicy::DEFAULT_MAX_RETRIES, $defaults->maxRetries);

        $configured = LlmRequestRetryPolicyFactory::fromAppConfig(new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            ai: new AiConfig(http: new AiHttpConfig(maxRetries: 5, baseDelayMs: 250, maxDelayMs: 2_000)),
        ));
        $this->assertSame(5, $configured->maxRetries);
        $this->assertSame(250, $configured->baseDelayMs);
        $this->assertSame(2_000, $configured->maxDelayMs);
    }
}
