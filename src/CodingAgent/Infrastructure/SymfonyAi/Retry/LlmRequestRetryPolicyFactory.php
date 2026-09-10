<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Retry;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\Retry\LlmRequestRetryPolicy;
use Ineersa\CodingAgent\Config\AppConfig;

/**
 * Builds {@see LlmRequestRetryPolicy} from existing ai.http settings.
 *
 * Reuses max_retries / base_delay_ms / max_delay_ms so one settings block
 * controls the application retry budget. Defaults remain in the policy when
 * settings omit values.
 */
final class LlmRequestRetryPolicyFactory
{
    public static function fromAppConfig(AppConfig $appConfig): LlmRequestRetryPolicy
    {
        $http = $appConfig->ai?->http;

        return new LlmRequestRetryPolicy(
            maxRetries: $http?->maxRetries,
            baseDelayMs: $http?->baseDelayMs,
            maxDelayMs: $http?->maxDelayMs,
        );
    }
}
