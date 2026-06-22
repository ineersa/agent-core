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
     */
    public function getLatestInputTokens(string $runId): ?int
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
                    return $tokens;
                }
            }
        }

        return null;
    }
}
