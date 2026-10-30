<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DTO\DeferredToolCompletionMetadataDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;

/**
 * Cross-process durable store for deferred tool executions.
 */
interface DeferredToolCompletionRepositoryInterface
{
    /**
     * Inserts a pending deferred record or returns the existing correlation for the same run/tool call.
     */
    public function registerPending(DeferredToolCompletionCorrelation $correlation): DeferredToolCompletionCorrelation;

    /**
     * Owner preparation inserts a pending record without readiness.
     * Existing rows keep their current readiness; late registerPending stamps readiness.
     */
    public function preparePending(DeferredToolCompletionCorrelation $correlation): DeferredToolCompletionCorrelation;

    /** Resolve pending data only. Completed markers never require a retained body. */
    public function findByRunAndToolCall(string $runId, string $toolCallId): ?DeferredToolCompletionCorrelation;

    /** @return 'completed'|'pending'|null */
    public function statusForRunAndToolCall(string $runId, string $toolCallId): ?string;

    public function findByDeferredId(string $deferredId): ?DeferredToolCompletionCorrelation;

    /** Read and validate stored correlation identity without opening its body. */
    public function findMetadataByDeferredId(string $deferredId): ?DeferredToolCompletionMetadataDTO;

    public function findMetadataByRunAndToolCall(string $runId, string $toolCallId): ?DeferredToolCompletionMetadataDTO;

    /**
     * @return 'completed'|'pending'|null
     */
    public function status(string $deferredId): ?string;

    public function markCompleted(string $deferredId): void;

    /** Old successor tokens require an explicit owner continuation after loss. */
    public function runRequiresContinuation(string $runId): bool;

    /** First immutable processed result wins; later publications are no-ops when equal. */
    public function acceptResultReference(string $deferredId, ToolPayloadReferenceDTO $reference): ToolPayloadReferenceDTO;
}
