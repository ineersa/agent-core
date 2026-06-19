<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

/**
 * Result of {@see SessionCompactor::prepare()}.
 *
 * Carries either a successful compaction preparation or a specific
 * skip reason so the future compaction pipeline (COMP-02) can
 * distinguish disabled/below-threshold/no-safe-boundary without
 * inspecting bare null.
 */
final readonly class CompactionPreparationResultDTO
{
    private function __construct(
        public ?CompactionPreparationDTO $preparation,
        public ?CompactionSkipReasonEnum $skipReason,
    ) {
    }

    /**
     * Compaction is possible and preparation is ready.
     */
    public static function ready(CompactionPreparationDTO $preparation): self
    {
        return new self($preparation, null);
    }

    /**
     * Compaction was skipped for the given reason.
     */
    public static function skipped(CompactionSkipReasonEnum $reason): self
    {
        return new self(null, $reason);
    }

    /**
     * Whether the result contains a usable preparation.
     */
    public function isReady(): bool
    {
        return null !== $this->preparation;
    }
}
