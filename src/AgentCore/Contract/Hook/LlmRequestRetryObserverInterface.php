<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

/**
 * Ephemeral observer for application-level LLM request retries.
 *
 * Implementations may surface retry progress to the TUI. They must not
 * depend on CodingAgent runtime event types from AgentCore.
 */
interface LlmRequestRetryObserverInterface
{
    /**
     * `attempt` / `max_attempts` are the retry index and retry budget
     * (for example 1/5), not total platform invocations.
     *
     * @param array{
     *     attempt: int,
     *     max_attempts: int,
     *     delay_ms: int,
     *     reason: string,
     *     error_category: string,
     *     error_type: string
     * } $retry
     */
    public function onRequestRetry(string $runId, ?string $stepId, array $retry): void;
}
