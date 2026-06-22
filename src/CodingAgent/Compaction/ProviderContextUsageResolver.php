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
 * once.  The latest auto context_compaction_started event marks the
 * measurement handled.  A newer provider usage (higher seq) re-opens
 * eligibility.  This prevents repeated auto-compaction loops on the
 * same provider measurement after compaction commits or process restart.
 *
 * Manual /compact events are NOT considered in eligibility — only
 * auto-triggered starts (trigger=auto).
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
     * sequence number is greater than the latest auto
     * context_compaction_started event seq (or no auto started event
     * exists).
     *
     * A provider measurement that has already triggered an auto-
     * compaction attempt (context_compaction_started with trigger=auto)
     * is ineligible regardless of whether the attempt succeeded, failed,
     * or is still in flight.  Only a newer provider measurement (higher
     * seq) re-opens eligibility.
     *
     * Manual /compact attempts do NOT count — only auto starts.
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
        $latestAutoStartSeq = $this->findLatestAutoCompactionStartSeq($runId);

        // Eligible only when provider measurement is newer than the
        // last auto compaction attempt (or no auto attempt exists).
        if (null !== $latestAutoStartSeq && $providerSeq <= $latestAutoStartSeq) {
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
     * Finds the latest auto context_compaction_started event seq.
     *
     * Walks backward through events, returning the seq of the most
     * recent context_compaction_started with trigger=auto.
     * Manual /compact starts (trigger=manual or missing) are ignored —
     * manual compaction should never block auto-compaction from a
     * newer provider measurement.
     */
    private function findLatestAutoCompactionStartSeq(string $runId): ?int
    {
        $events = $this->eventStore->allFor($runId);

        for ($i = \count($events) - 1; $i >= 0; --$i) {
            $event = $events[$i];

            if (RunEventTypeEnum::ContextCompactionStarted->value !== $event->type) {
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
