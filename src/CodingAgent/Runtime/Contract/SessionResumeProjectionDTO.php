<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;

/**
 * Compact non-transcript TUI resume fields derived during cold reconstruction.
 *
 * Replaces retained-history RuntimeEvent replay lists. Transcript blocks remain
 * separate on {@see SessionTranscriptSnapshotDTO}. Terminal/passive activity is
 * still inferred by SessionInitializer from cold reconstruction metadata.
 */
final readonly class SessionResumeProjectionDTO
{
    /**
     * @param array<string, string>                   $queuedUserMessages
     * @param list<SubagentProgressSnapshotInterface> $subagentProgressSnapshots
     */
    public function __construct(
        public int $usageInputTokens = 0,
        public int $usageOutputTokens = 0,
        public float $usageTotalCost = 0.0,
        public int $usageLatestInputTokens = 0,
        public int $usageCacheReadTokens = 0,
        public int $usageCacheCreationTokens = 0,
        public bool $usageHasCacheTelemetry = false,
        public array $queuedUserMessages = [],
        public array $subagentProgressSnapshots = [],
        public ?string $llmRetryWorkingMessage = null,
        public string $activity = 'idle',
        public bool $isCompacting = false,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }
}
