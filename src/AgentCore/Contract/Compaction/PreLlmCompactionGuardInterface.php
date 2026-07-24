<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Compaction;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Optional guard checked before an LLM step is dispatched.
 *
 * Allows coding-agent-side policy (auto-compaction threshold,
 * provider/model overrides) to schedule a {@see CompactRun} instead of
 * an {@see ExecuteLlmStep} without AgentCore importing coding-agent
 * config or services.
 *
 * Implementations are expected to be stateless except for in-memory
 * dedup guards (same-process singleton reuse).
 */
interface PreLlmCompactionGuardInterface
{
    /**
     * Determine whether compaction should run before the next LLM call.
     *
     * When true, the caller should dispatch a CompactRun effect
     * instead of an ExecuteLlmStep effect.  The next AdvanceRun after
     * compaction completes will proceed normally.
     *
     * @param int                $nextTurnNo   Turn number the LLM step would use
     * @param list<AgentMessage> $messages     Current prompt-history messages
     * @param string|null        $activeStepId Current active step, or null
     */
    public function shouldCompactBeforeLlmStep(
        string $runId,
        int $nextTurnNo,
        array $messages,
        ?string $activeStepId,
        ?string $activeModel = null,
    ): bool;
}
