<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;

/**
 * Durable scheduling store for tool batch membership and queues.
 *
 * Immutable request/result bodies and claims remain on the invocation ledger.
 * Registration applies the producer-captured membership decision exactly.
 */
interface ToolBatchStoreInterface
{
    public function load(string $runId, int $turnNo, string $stepId): ?ToolBatchStateDTO;

    public function delete(string $runId, int $turnNo, string $stepId): void;

    public function deleteAllForRun(string $runId): void;

    public function hasUnresolvedExecution(string $runId, ?string $toolCallId = null): bool;

    /** Apply a prepared scheduling delta under a verified transition identity. */
    public function applyPrepared(FinalizeToolBatchDTO $action, VerifiedTransitionDTO $transition): void;

    /** Persist prepared membership under a verified transition identity. */
    public function registerPrepared(RegisterToolBatchDTO $action, VerifiedTransitionDTO $transition): void;

    /**
     * Current owner-admitted invocations for a batch. Queued or barrier-blocked
     * membership remains non-executable until it appears here.
     *
     * @return list<ExecuteToolCall>
     */
    public function admittedCalls(string $runId, int $turnNo, string $stepId): array;
}
