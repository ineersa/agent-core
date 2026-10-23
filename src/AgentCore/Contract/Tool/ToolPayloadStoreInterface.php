<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;

/**
 * Callers hold the existing run lock through admission and payload publication,
 * reads or deletion. Immutable files need no additional per-call lock. A scoped
 * store reuses a root resolution; it does not acquire or retain ownership.
 */
interface ToolPayloadStoreInterface
{
    /** Reuse a positive path resolution for one run-scoped operation, not across operations. */
    public function forRun(string $runId): self;

    public function publish(ExecuteToolCall|ExecuteShellToolCall|ToolCallResult $message): ToolPayloadReferenceDTO;

    public function read(ToolPayloadReferenceDTO $reference): ExecuteToolCall|ExecuteShellToolCall|ToolCallResult|DeferredToolCompletionCorrelation;

    public function publishCorrelation(DeferredToolCompletionCorrelation $correlation): ToolPayloadReferenceDTO;

    public function readCorrelation(ToolPayloadReferenceDTO $reference): DeferredToolCompletionCorrelation;

    /** Retain the first processed terminal before owner acceptance, without an owner lock. */
    public function retainProcessedResult(ToolPayloadReferenceDTO $invocation, ToolCallResult $result): ToolPayloadReferenceDTO;

    /** Read only processed-reference metadata bound to the exact immutable call. */
    public function findProcessedResultReference(ToolPayloadReferenceDTO $invocation): ?ToolPayloadReferenceDTO;

    public function deleteCall(string $runId, string $toolCallId): void;

    public function deleteAllForRun(string $runId): void;
}
