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

    public function findPendingByRunAndToolCall(string $runId, string $toolCallId): ?DeferredToolCompletionCorrelation;

    public function findByDeferredId(string $deferredId): ?DeferredToolCompletionCorrelation;

    /**
     * @return 'completed'|'completing'|'pending'|null
     */
    public function status(string $deferredId): ?string;

    /**
     * Atomically moves pending → completing. Returns false when already completing/completed or missing.
     */
    public function tryBeginCompletion(string $deferredId): bool;

    public function markCompleted(string $deferredId): void;
}
