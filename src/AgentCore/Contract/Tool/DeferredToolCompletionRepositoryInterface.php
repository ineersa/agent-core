<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
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

    /** Returns existing registration, including completed invocations. */
    public function findByRunAndToolCall(string $runId, string $toolCallId): ?DeferredToolCompletionCorrelation;

    public function findByDeferredId(string $deferredId): ?DeferredToolCompletionCorrelation;

    /**
     * @return 'completed'|'pending'|null
     */
    public function status(string $deferredId): ?string;

    public function markCompleted(string $deferredId): void;

    /**
     * Applies a captured lifecycle projection under verified local finalization.
     * Terminal acceptance with a completion receipt also marks status completed.
     * Transport send and caller detachment alone must not mark completed.
     */
    public function applyLifecycleProjection(RecordToolInvocationLifecycleDTO $action): void;
}
