<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;

/**
 * Cross-process durable store for deferred tool executions.
 */
interface DeferredToolCompletionRepositoryInterface
{
    /**
     * Inserts a pending deferred record or returns the existing correlation for the same run/tool call.
     */
    public function registerPending(DeferredToolCompletionCorrelation $correlation): DeferredToolCompletionCorrelation;

    /** Resolve pending data only. Completed markers never require a retained body. */
    public function findByRunAndToolCall(string $runId, string $toolCallId): ?DeferredToolCompletionCorrelation;

    /** @return 'completed'|'pending'|null */
    public function statusForRunAndToolCall(string $runId, string $toolCallId): ?string;

    public function findByDeferredId(string $deferredId): ?DeferredToolCompletionCorrelation;

    /**
     * @return 'completed'|'pending'|null
     */
    public function status(string $deferredId): ?string;

    public function markCompleted(string $deferredId): void;
}
