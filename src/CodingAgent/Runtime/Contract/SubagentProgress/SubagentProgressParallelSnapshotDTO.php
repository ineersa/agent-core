<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\SubagentProgress;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Parallel parent subagent_progress snapshot.
 *
 * Aggregate counters are concrete ints (including 0) so they always appear in
 * the canonical payload. Optional cost is null when absent/non-positive so
 * SKIP_NULL_VALUES preserves historical omission.
 */
final readonly class SubagentProgressParallelSnapshotDTO implements SubagentProgressSnapshotInterface
{
    /**
     * @param list<SubagentProgressChildRowDTO> $children
     */
    public function __construct(
        #[Assert\EqualTo('parallel')]
        public string $mode,
        #[Assert\NotBlank]
        public string $status,
        #[SerializedName('completed_count')]
        public int $completedCount = 0,
        #[SerializedName('total_count')]
        public int $totalCount = 0,
        #[SerializedName('elapsed_ms')]
        #[Assert\GreaterThanOrEqual(0)]
        public int $elapsedMs = 0,
        /** @var list<SubagentProgressChildRowDTO> */
        #[Assert\Valid]
        public array $children = [],
        #[SerializedName('tool_count')]
        public int $toolCount = 0,
        #[SerializedName('input_tokens')]
        public int $inputTokens = 0,
        #[SerializedName('output_tokens')]
        public int $outputTokens = 0,
        #[SerializedName('reasoning_tokens')]
        public int $reasoningTokens = 0,
        #[SerializedName('total_tokens')]
        public int $totalTokens = 0,
        public ?float $cost = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isParallel(): bool
    {
        return true;
    }
}
