<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

final readonly class ChildRunBatchLaunchAbortContextDTO
{
    public function __construct(
        public ChildRunBatchLaunchAbortPhaseEnum $phase,
        public ?int $preparationFailureBatchIndex = null,
    ) {
    }

    public static function preparationFailure(int $preparationFailureBatchIndex): self
    {
        return new self(ChildRunBatchLaunchAbortPhaseEnum::Preparation, $preparationFailureBatchIndex);
    }

    public static function runtimeStart(): self
    {
        return new self(ChildRunBatchLaunchAbortPhaseEnum::RuntimeStart);
    }
}
