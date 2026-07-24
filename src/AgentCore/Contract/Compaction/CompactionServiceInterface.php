<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Compaction;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Compaction service contract for the core pipeline.
 *
 * Implemented by the CodingAgent layer (wrapping SessionCompactor) so
 * AgentCore pipeline handlers can invoke compaction preparation and
 * result assembly without importing CodingAgent classes.
 */
interface CompactionServiceInterface
{
    /**
     * Prepare compaction partitions for a message list.
     *
     * Returns a CompactionPrepareResult with either a prepared split
     * (partitioned messages) or a structural skip reason.
     *
     * The returned DTO is AgentCore-native — the CodingAgent implementation
     * maps from its internal DTOs to the contract shape.
     *
     * @param list<AgentMessage> $messages
     */
    public function prepare(array $messages): CompactionPrepareResult;

    /**
     * Build the summarization message list for the LLM call.
     *
     * Returns the messages to pass directly to the summarization model,
     * with tool results in the summarize partition replaced by deterministic
     * digest/placeholders.
     *
     * @param CompactionPrepareResult $result             Result from prepare()
     * @param string|null             $customInstructions Optional user instructions
     *
     * @return list<AgentMessage>
     */
    public function buildSummarizationMessages(
        CompactionPrepareResult $result,
        ?string $customInstructions,
    ): array;

    /**
     * Build the compacted message list from a summarization result.
     *
     * Returns a CompactResult with the full [summaryMessage, ...retainedTail]
     * ready to replace RunState.messages.
     */
    public function buildCompactedMessages(
        string $summaryText,
        CompactionPrepareResult $result,
    ): CompactResult;

    /**
     * Synchronously compact an in-memory message snapshot without mutating
     * RunStore/EventStore.
     *
     * Uses the same prepare → hooks → summarization prompt → no-tools model
     * invocation → empty/ineffective validation → buildCompactedMessages path
     * as the async CompactRun pipeline, but returns the result directly.
     *
     * Structural prepare skips are returned as structural no-ops with the
     * original messages. Model summaries that do not reduce the token estimate
     * are also returned as structural no-ops with reason `ineffective_compaction`
     * and the original messages (snapshot callers such as fork must continue).
     * Hard failures (model error, empty summary, hook cancel) are returned as
     * failures. Hook-provided replacement summaries match CompactRunHandler and
     * skip the ordinary ineffective-compaction rejection.
     *
     * @param list<AgentMessage> $messages
     */
    public function compactMessages(
        string $runId,
        int $turnNo,
        array $messages,
        string $trigger = 'manual',
        ?string $customInstructions = null,
        ?string $activeModel = null,
    ): MessageSnapshotCompactionResult;
}
