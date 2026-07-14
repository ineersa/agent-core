<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;

/**
 * Immutable ordered launch plan: identities, intents, and definitions resolved once (Piece 4A).
 */
final readonly class DeferredSubagentBatchLaunchPlanDTO
{
    /**
     * @param list<DeferredSubagentBatchChildIntentDTO> $childIntents
     * @param array<int, AgentDefinitionDTO>            $definitionsByBatchIndex
     * @param list<ChildRunIdentityDTO>                 $identities
     */
    public function __construct(
        public string $lifecycleId,
        public ChildRunBatchExecutionModeEnum $executionMode,
        public int $totalChildCount,
        public array $childIntents,
        public array $definitionsByBatchIndex,
        public array $identities,
    ) {
    }

    /**
     * @return list<array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, definitionModel: ?string}>
     */
    public function reserveChildIntents(): array
    {
        return array_map(static fn (DeferredSubagentBatchChildIntentDTO $intent): array => $intent->toReserveArray(), $this->childIntents);
    }
}
