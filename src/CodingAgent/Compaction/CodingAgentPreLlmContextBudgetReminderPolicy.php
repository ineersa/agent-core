<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Contract\ContextBudget\ContextBudgetReminderDecision;
use Ineersa\AgentCore\Contract\ContextBudget\PreLlmContextBudgetReminderPolicyInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ContextBudgetReminderConfig;

/**
 * CodingAgent pre-LLM wrap-up reminder policy.
 *
 * Scans committed run events for:
 *  - latest positive provider input_tokens / prompt_tokens after the latest
 *    successful context_compacted barrier (or run start when none)
 *  - non-content handled marker keys on LlmStepCompleted / LlmStepAborted
 *    after that barrier
 *  - context window from run_started metadata, falling back to the model catalog
 *
 * Missing usage or window => no reminder. Successful compaction starts a new
 * episode and pre-compaction usage/markers are ignored.
 */
final readonly class CodingAgentPreLlmContextBudgetReminderPolicy implements PreLlmContextBudgetReminderPolicyInterface
{
    public const string EARLY_TEXT = 'Context usage is already very high. Stop further exploration and do not start new delegated work. Finish now with the best concise final answer or handoff, including concrete findings, incomplete work, and next steps.';
    public const string URGENT_TEXT = 'Context is nearly exhausted. Stop further exploration and do not start new delegated work. Finish now with the best concise final answer or handoff, including concrete findings, incomplete work, and next steps.';

    public function __construct(
        private EventStoreInterface $eventStore,
        private ContextBudgetReminderConfig $config,
        private AppConfig $appConfig,
    ) {
    }

    public function decide(string $runId, ?string $activeModel = null): ?ContextBudgetReminderDecision
    {
        $events = $this->eventStore->allFor($runId);
        $barrierSeq = $this->latestSuccessfulCompactionSeq($events);

        $inputTokens = $this->latestPromptInputTokensAfterBarrier($events, $barrierSeq);
        if (null === $inputTokens) {
            return null;
        }

        $contextWindow = $this->resolveContextWindow($events, $activeModel);
        if (null === $contextWindow) {
            return null;
        }

        $handled = $this->handledKeysAfterBarrier($events, $barrierSeq);

        $earlyEligible = $inputTokens >= $this->config->earlyInputTokens
            && !\in_array(ContextBudgetReminderDecision::KEY_EARLY, $handled, true);

        $remaining = $contextWindow - $inputTokens - $this->config->outputHeadroomTokens;
        $urgentEligible = $remaining < $this->config->urgentRemainingTokens
            && !\in_array(ContextBudgetReminderDecision::KEY_URGENT, $handled, true);

        if (!$earlyEligible && !$urgentEligible) {
            return null;
        }

        // Both eligible on one turn: send only urgent prose, mark both handled.
        if ($urgentEligible) {
            $keys = [ContextBudgetReminderDecision::KEY_URGENT];
            if ($earlyEligible) {
                $keys[] = ContextBudgetReminderDecision::KEY_EARLY;
            }

            return new ContextBudgetReminderDecision(
                text: self::URGENT_TEXT,
                handledThresholdKeys: $keys,
            );
        }

        return new ContextBudgetReminderDecision(
            text: self::EARLY_TEXT,
            handledThresholdKeys: [ContextBudgetReminderDecision::KEY_EARLY],
        );
    }

    /**
     * @param list<RunEvent> $events
     */
    private function latestSuccessfulCompactionSeq(array $events): int
    {
        for ($i = \count($events) - 1; $i >= 0; --$i) {
            if (RunEventTypeEnum::ContextCompacted->value === $events[$i]->type) {
                return $events[$i]->seq;
            }
        }

        return 0;
    }

    /**
     * @param list<RunEvent> $events
     */
    private function latestPromptInputTokensAfterBarrier(array $events, int $barrierSeq): ?int
    {
        for ($i = \count($events) - 1; $i >= 0; --$i) {
            $event = $events[$i];
            if ($event->seq <= $barrierSeq) {
                break;
            }

            if (
                RunEventTypeEnum::LlmStepCompleted->value !== $event->type
                && RunEventTypeEnum::LlmStepAborted->value !== $event->type
            ) {
                continue;
            }

            $usage = $event->payload['usage'] ?? [];
            if (!\is_array($usage)) {
                continue;
            }

            $tokens = $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null;
            if (\is_int($tokens) && $tokens > 0) {
                return $tokens;
            }
        }

        return null;
    }

    /**
     * @param list<RunEvent> $events
     *
     * @return list<string>
     */
    private function handledKeysAfterBarrier(array $events, int $barrierSeq): array
    {
        $keys = [];

        foreach ($events as $event) {
            if ($event->seq <= $barrierSeq) {
                continue;
            }

            if (
                RunEventTypeEnum::LlmStepCompleted->value !== $event->type
                && RunEventTypeEnum::LlmStepAborted->value !== $event->type
            ) {
                continue;
            }

            $raw = $event->payload['context_budget_reminders_handled'] ?? null;
            if (!\is_array($raw)) {
                continue;
            }

            foreach ($raw as $key) {
                if (\is_string($key) && '' !== $key && !\in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * @param list<RunEvent> $events
     */
    private function resolveContextWindow(array $events, ?string $activeModel): ?int
    {
        $fromRun = $this->contextWindowFromRunStarted($events);
        if (null !== $fromRun) {
            return $fromRun;
        }

        return $this->contextWindowFromCatalog($activeModel);
    }

    /**
     * @param list<RunEvent> $events
     */
    private function contextWindowFromRunStarted(array $events): ?int
    {
        foreach ($events as $event) {
            if (RunEventTypeEnum::RunStarted->value !== $event->type) {
                continue;
            }

            $inner = $event->payload['payload'] ?? null;
            if (!\is_array($inner)) {
                continue;
            }

            $metadata = $inner['metadata'] ?? null;
            if (!\is_array($metadata)) {
                continue;
            }

            $window = $metadata['context_window'] ?? null;
            if (\is_int($window) && $window > 0) {
                return $window;
            }
        }

        return null;
    }

    private function contextWindowFromCatalog(?string $activeModel): ?int
    {
        if (null === $activeModel || '' === trim($activeModel)) {
            return null;
        }

        $catalog = $this->appConfig->catalog;
        if (!$catalog instanceof HatfieldModelCatalog) {
            return null;
        }

        $model = $catalog->getModel(trim($activeModel));
        if (null === $model || null === $model->contextWindow || $model->contextWindow <= 0) {
            return null;
        }

        return $model->contextWindow;
    }
}
