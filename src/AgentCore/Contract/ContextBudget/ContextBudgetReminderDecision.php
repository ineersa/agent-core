<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\ContextBudget;

/**
 * Pre-LLM decision to inject a one-shot wrap-up reminder into a normal
 * provider invocation without mutating RunState or canonical history.
 *
 * {@see $text} is transport-only (ExecuteLlmStep / ModelInvocationInput).
 * {@see $handledThresholdKeys} are non-content marker keys persisted on the
 * eventual LlmStepCompleted / LlmStepAborted payload for durable one-shot
 * episode tracking after delivery.
 */
final readonly class ContextBudgetReminderDecision
{
    public const string KEY_EARLY = 'early';
    public const string KEY_URGENT = 'urgent';

    /**
     * @param list<string> $handledThresholdKeys Non-content keys such as early/urgent
     */
    public function __construct(
        public string $text,
        public array $handledThresholdKeys,
    ) {
        if ('' === trim($this->text)) {
            throw new \InvalidArgumentException('ContextBudgetReminderDecision requires non-empty reminder text.');
        }

        if ([] === $this->handledThresholdKeys) {
            throw new \InvalidArgumentException('ContextBudgetReminderDecision requires at least one handled threshold key.');
        }
    }
}
