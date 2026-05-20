<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;

/**
 * Ephemeral stream observer called during LLM platform invocation.
 *
 * Implementations receive streaming deltas in real-time during
 * consumeStream().  This is the AgentCore-side boundary — observers
 * MUST NOT depend on CodingAgent, TUI, or RuntimeEvent types.
 *
 * Delta events are TRANSIENT by default. They are not canonical
 * RunEvents and are not persisted to event stores unless the observer
 * implementation chooses to do so. The canonical durable event path
 * (RunCommit → EventStore → coarse RunEvents) is separate.
 */
interface LlmStreamObserverInterface
{
    /**
     * Called before the first delta is consumed.
     *
     * @param string      $runId  the active run identifier (nullable in parent context but guaranteed non-empty at stream time)
     * @param string|null $stepId the active step identifier; null when not available
     */
    public function onStreamStart(string $runId, ?string $stepId): void;

    /**
     * Called for every streaming delta returned by the platform.
     *
     * Implementations should match on the concrete DeltaInterface subtype
     * (TextDelta, ThinkingDelta, ToolCallStart, etc.) and map to their
     * own event model. Unknown delta types should be silently ignored.
     */
    public function onDelta(string $runId, ?string $stepId, DeltaInterface $delta): void;

    /**
     * Called after the final delta has been consumed (success path).
     *
     * This is the normal stream-completion signal. It does NOT mean the
     * LLM invocation completed successfully — only that the stream ended.
     * Final durable events (e.g., llm_step_completed) are emitted
     * separately through the canonical RunCommit path.
     */
    public function onStreamEnd(string $runId, ?string $stepId): void;

    /**
     * Called when the stream is terminated by an exception.
     *
     * Invoked once per stream error. After this call, the canonical
     * error result (llm_step_failed, etc.) is produced and committed
     * through the standard RunCommit path.
     */
    public function onStreamError(string $runId, ?string $stepId, \Throwable $error): void;
}
