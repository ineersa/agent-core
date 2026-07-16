<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;

/**
 * Immutable ordered launch plan for durable deferred child batch launch.
 */
interface DeferredAgentChildBatchLaunchPlanInterface
{
    public function lifecycleId(): string;

    public function executionMode(): ChildRunBatchExecutionModeEnum;

    public function totalChildCount(): int;

    /**
     * @return list<ChildRunIdentityDTO>
     */
    public function identities(): array;

    /**
     * @return list<array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, definitionModel: ?string}>
     */
    public function reserveChildIntents(): array;
}
