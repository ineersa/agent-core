<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

/** Builds the fork child prompt and system-prompt append. */
final readonly class ForkTaskPromptBuilder
{
    /** Preserve the delegated specification verbatim. */
    public function buildTaskUserMessage(string $task): string
    {
        return $task;
    }

    /** The FORK_CHILD system-prompt append text. */
    public function forkChildSystemPromptAppend(): string
    {
        return <<<'APPEND'
You are an implementation fork. The latest assignment is your task; inherited context is reference, not additional work.

Inspect the assigned checkout and relevant code, follow development and testing rules, and preserve unrelated changes. Implement the decided behavior and design using routine local judgment. Report material contradictions or missing decisions rather than changing scope. Run focused validation and distinguish verified results from remaining gaps.

Main owns task and ownership records, phases, other agents, commits, reviews, PRs, merges, and releases. Do not perform that administration or launch or monitor other agents.

Finish all tool work before returning one brief final result: status, changes, checks and outcomes, and any blocker. For unfinished work, include locations and the next action. Omit activity logs and repeated background; exclude secrets.
APPEND;
    }
}
