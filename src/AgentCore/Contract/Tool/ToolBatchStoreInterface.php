<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolBatchScheduleDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;

/** Active batch calls, collected results, and captured scheduling decisions. */
interface ToolBatchStoreInterface
{
    public function load(string $runId, int $turnNo, string $stepId): ?ToolBatchStateDTO;

    /** Return a detached scheduling snapshot without invocation or result bodies. */
    public function loadSchedule(string $runId, int $turnNo, string $stepId): ?ToolBatchScheduleDTO;

    public function loadCall(string $runId, int $turnNo, string $stepId, string $toolCallId): ?ExecuteToolCall;

    /** Current invocation identity without opening its data file. */
    public function loadCallReference(string $runId, int $turnNo, string $stepId, string $toolCallId): ?ToolPayloadReferenceDTO;

    public function loadResult(string $runId, int $turnNo, string $stepId, string $toolCallId): ?ToolCallResult;

    /** @return array<string, ToolCallResult> Ordered by invocation order. */
    public function loadResults(string $runId, int $turnNo, string $stepId): array;

    /** Delete only settled batch metadata and unshared tool payloads. */
    public function delete(string $runId, int $turnNo, string $stepId): void;

    /** Apply the same settlement and shared-payload guards across the run. */
    public function deleteAllForRun(string $runId): void;

    public function hasUnresolvedExecution(string $runId): bool;

    /**
     * Resolve pending deferred work and membership in unsettled batches without bodies.
     *
     * @param list<string> $toolCallIds
     *
     * @return list<string> unresolved identities from the requested set
     */
    public function findUnresolvedToolCallIds(string $runId, array $toolCallIds): array;

    /**
     * Prepare payloads before the metadata transaction. The returned local write
     * joins that transaction; it is never persisted as transition work.
     *
     * @param list<RegisterToolBatchDTO|FinalizeToolBatchDTO> $actions
     *
     * @return \Closure(): void
     */
    public function prepareChanges(array $actions, VerifiedTransitionDTO $transition): \Closure;
}
