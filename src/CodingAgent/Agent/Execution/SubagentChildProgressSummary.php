<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

/**
 * Bounded, privacy-safe summary derived from a child agent's events and RunState.
 *
 * Never includes raw tool results, system/user-context/tool-role message bodies,
 * or full prompts. Intended as enrichment for typed parent subagent_progress
 * snapshots only ({@see SubagentProgressSnapshotBuilder}).
 *
 * Model and reasoning are concrete launch identity supplied by preparation /
 * deferred projection paths (not external input).
 */
final readonly class SubagentChildProgressSummary
{
    /**
     * @param list<string> $recentTools Safe display lines (e.g. read: path="…")
     */
    public function __construct(
        public string $model,
        public string $reasoning,
        public int $toolCount = 0,
        public int $llmStepCount = 0,
        public int $inputTokens = 0,
        public int $latestInputTokens = 0,
        public int $contextWindow = 0,
        public int $outputTokens = 0,
        public int $reasoningTokens = 0,
        public int $totalTokens = 0,
        public ?float $cost = null,
        public ?string $provider = null,
        public ?string $artifactPath = null,
        public ?string $assistantExcerpt = null,
        public array $recentTools = [],
        public ?string $activeToolLine = null,
    ) {
    }
}
