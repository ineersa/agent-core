<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

/**
 * Resolves the latest provider-reported context token usage from
 * committed run events.
 *
 * Walks backward through llm_step_completed (and llm_step_aborted)
 * events to find the most recent input_tokens/prompt_tokens measurement.
 *
 * Used by auto-compaction trigger policy: auto-compaction fires only
 * when a provider measurement exists and exceeds compact_after_tokens.
 * No provider measurement = no auto-compaction.
 *
 * Eligibility rule (event-log authoritative, not in-memory):
 * A provider usage measurement is eligible for auto-compaction at most
 * once.  An auto compaction attempt marker is any event with trigger=auto
 * and type in {context_compaction_started, context_compaction_failed}.
 * The latest attempt marker that is newer than the provider measurement
 * renders it ineligible.  A newer provider measurement (higher seq than
 * the latest attempt marker) re-opens eligibility.
 *
 * This covers both the normal path (started → compacted/failed) and the
 * prepare-failure path where context_compaction_failed is emitted without
 * a preceding context_compaction_started (e.g. too_few_messages,
 * no_safe_boundary).
 *
 * Manual /compact events are NOT considered in eligibility — only
 * auto-triggered markers (trigger=auto).
 */
final class ProviderContextUsageResolver
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
    ) {
    }

    /**
     * Returns the latest provider input/prompt token count for a run,
     * or null when no provider measurement exists yet.
     *
     * Only input_tokens / prompt_tokens are considered (not output/
     * completion tokens).  Measurement must be a positive integer.
     *
     * This method does NOT check eligibility — it returns the raw
     * latest measurement regardless of whether auto-compaction has
     * already acted on it.  Prefer {@see getLatestEligibleInputTokens}
     * for auto-compaction trigger decisions.
     */
    public function getLatestInputTokens(string $runId): ?int
    {
        $measurement = $this->findLatestProviderMeasurement($runId);

        return $measurement['tokens'] ?? null;
    }

    /**
     * Returns the latest provider token count that is ELIGIBLE for
     * auto-compaction — i.e. a provider usage measurement whose event
     * sequence number is greater than the latest auto compaction
     * attempt marker seq (or no auto attempt marker exists).
     *
     * Attempt markers include both context_compaction_started and
     * context_compaction_failed with trigger=auto.  The prepare-failure
     * path emits only context_compaction_failed (no started event), so
     * both types must be considered to prevent retry loops on stale
     * measurements.
     *
     * A provider measurement that has already triggered an attempt
     * marker is ineligible regardless of whether the attempt succeeded,
     * failed, or is still in flight.  Only a newer provider measurement
     * (higher seq than the latest attempt marker) re-opens eligibility.
     *
     * Manual /compact attempts do NOT count — only auto markers.
     *
     * @return int|null eligible tokens, or null when no eligible
     *                  measurement exists
     */
    public function getLatestEligibleInputTokens(string $runId): ?int
    {
        $measurement = $this->findLatestProviderMeasurement($runId);

        $eligibleTokens = $measurement['tokens'] ?? null;

        if (null === $eligibleTokens) {
            return null;
        }

        $providerSeq = $measurement['seq'] ?? 0;
        $latestAutoAttemptSeq = $this->findLatestAutoCompactionAttemptSeq($runId);

        // Eligible only when provider measurement is newer than the
        // last auto compaction attempt marker (or no attempt exists).
        // Attempt markers include both context_compaction_started and
        // context_compaction_failed (the prepare-failure path emits
        // only failed, no started).
        if (null !== $latestAutoAttemptSeq && $providerSeq <= $latestAutoAttemptSeq) {
            return null;
        }

        return $eligibleTokens;
    }

    /**
     * Returns the latest provider measurement metadata:
     *   seq   — event sequence number
     *   tokens — input_tokens or prompt_tokens (positive int)
     *   event — the RunEvent (for callers that need the full payload)
     *
     * @return array{seq: int, tokens: int, event: \Ineersa\AgentCore\Domain\Event\RunEvent}|array{}
     */
    private function findLatestProviderMeasurement(string $runId): array
    {
        $events = $this->eventStore->allFor($runId);

        for ($i = \count($events) - 1; $i >= 0; --$i) {
            $event = $events[$i];

            if (
                RunEventTypeEnum::LlmStepCompleted->value === $event->type
                || RunEventTypeEnum::LlmStepAborted->value === $event->type
            ) {
                $usage = $event->payload['usage'] ?? [];

                $tokens = $usage['input_tokens']
                    ?? $usage['prompt_tokens']
                    ?? null;

                if (\is_int($tokens) && $tokens > 0) {
                    return [
                        'seq' => $event->seq,
                        'tokens' => $tokens,
                        'event' => $event,
                    ];
                }
            }
        }

        return [];
    }

    /**
     * Finds the latest auto compaction attempt marker seq.
     *
     * Walks backward through events, returning the seq of the most
     * recent auto-triggered compaction attempt — either
     * context_compaction_started or context_compaction_failed with
     * trigger=auto.
     *
     * The prepare-failure path in CompactRunHandler emits
     * context_compaction_failed without a preceding started event
     * (e.g. too_few_messages, no_safe_boundary).  Including failure
     * as an attempt marker prevents retry loops on the same stale
     * provider measurement.
     *
     * Manual /compact markers (trigger=manual or missing) are ignored —
     * manual compaction should never block auto-compaction from a
     * newer provider measurement.
     */
    private function findLatestAutoCompactionAttemptSeq(string $runId): ?int
    {
        $attemptTypes = [
            RunEventTypeEnum::ContextCompactionStarted->value,
            RunEventTypeEnum::ContextCompactionFailed->value,
        ];

        $events = $this->eventStore->allFor($runId);

        for ($i = \count($events) - 1; $i >= 0; --$i) {
            $event = $events[$i];

            if (!\in_array($event->type, $attemptTypes, true)) {
                continue;
            }

            $trigger = $event->payload['trigger'] ?? null;
            if ('auto' === $trigger) {
                return $event->seq;
            }
        }

        return null;
    }
}
