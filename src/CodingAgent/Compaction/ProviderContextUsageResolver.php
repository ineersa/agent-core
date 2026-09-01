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
        $hasNewerAutoAttempt = false;

        foreach ($this->eventStore->reverseFor($runId) as $event) {
            if (
                (RunEventTypeEnum::ContextCompactionStarted->value === $event->type
                    || RunEventTypeEnum::ContextCompactionFailed->value === $event->type)
                && 'auto' === ($event->payload['trigger'] ?? null)
            ) {
                $hasNewerAutoAttempt = true;

                continue;
            }

            if (
                RunEventTypeEnum::LlmStepCompleted->value !== $event->type
                && RunEventTypeEnum::LlmStepAborted->value !== $event->type
            ) {
                continue;
            }

            $usage = $event->payload['usage'] ?? [];
            $tokens = $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null;
            if (!\is_int($tokens) || $tokens <= 0) {
                continue;
            }

            return $hasNewerAutoAttempt ? null : $tokens;
        }

        return null;
    }
}
