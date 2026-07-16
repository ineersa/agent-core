<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;

/**
 * Generic immutable launch plan: identities and reservation intents (no kind-specific preparation state).
 */
final readonly class DeferredAgentChildBatchLaunchPlanDTO implements DeferredAgentChildBatchLaunchPlanInterface
{
    /**
     * @param list<DeferredAgentChildBatchChildIntentDTO> $childIntents
     * @param list<ChildRunIdentityDTO>                   $identities
     */
    public function __construct(
        public string $lifecycleId,
        public ChildRunBatchExecutionModeEnum $executionMode,
        public int $totalChildCount,
        public array $childIntents,
        public array $identities,
    ) {
    }

    public function lifecycleId(): string
    {
        return $this->lifecycleId;
    }

    public function executionMode(): ChildRunBatchExecutionModeEnum
    {
        return $this->executionMode;
    }

    public function totalChildCount(): int
    {
        return $this->totalChildCount;
    }

    public function identities(): array
    {
        return $this->identities;
    }

    public function reserveChildIntents(): array
    {
        return array_map(
            static fn (DeferredAgentChildBatchChildIntentDTO $intent): array => $intent->toReserveArray(),
            $this->childIntents,
        );
    }
}
