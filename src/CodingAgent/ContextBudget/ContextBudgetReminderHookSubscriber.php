<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\ContextBudget;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ContextBudgetReminderConfig;
use Ineersa\CodingAgent\Session\History\HistoryProjectionStoreInterface;
use Ineersa\CodingAgent\Session\History\RunStartedLaunchProjection;

/**
 * After-turn hook: queue one-shot wrap-up append messages when committed LLM
 * usage crosses context-budget thresholds.
 *
 * Uses shared history projections for child/fork disable rules, context-window
 * hints, and already-issued reminder keys. Ordinary lookups never scan the
 * event archive.
 */
final readonly class ContextBudgetReminderHookSubscriber implements HookSubscriberInterface
{
    public const string EARLY_TEXT = 'Context usage is already very high. Stop further exploration and do not start new delegated work. Finish now with the best concise final answer or handoff, including concrete findings, incomplete work, and next steps.';
    public const string URGENT_TEXT = 'Context is nearly exhausted. Stop further exploration and do not start new delegated work. Finish now with the best concise final answer or handoff, including concrete findings, incomplete work, and next steps.';

    public function __construct(
        private HistoryProjectionStoreInterface $historyProjectionStore,
        private AgentRunnerInterface $agentRunner,
        private ContextBudgetReminderConfig $config,
        private AppConfig $appConfig,
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        $completion = $this->latestLlmStepCompletedInBatch($context->events);
        if (null === $completion) {
            return $context;
        }

        $inputTokens = $this->positivePromptInputTokens($completion->payload['usage'] ?? null);
        if (null === $inputTokens) {
            return $context;
        }

        $snapshot = $this->historyProjectionStore->get($context->runId);
        if ($this->remindersDisabledForChild($snapshot->runStartedLaunch)) {
            return $context;
        }

        $contextWindow = $this->resolveContextWindow($context, $snapshot->runStartedLaunch);
        if (null === $contextWindow) {
            return $context;
        }

        $remaining = $contextWindow - $inputTokens;
        if ($inputTokens < $this->config->earlyInputTokens
            && $remaining >= $this->config->urgentRemainingTokens) {
            return $context;
        }

        $issued = $snapshot->issuedReminderKeys;

        $earlyEligible = $inputTokens >= $this->config->earlyInputTokens
            && !\in_array('early', $issued, true)
            && !\in_array('urgent', $issued, true);

        $urgentEligible = $remaining < $this->config->urgentRemainingTokens
            && !\in_array('urgent', $issued, true);

        if (!$earlyEligible && !$urgentEligible) {
            return $context;
        }

        // Both eligible on one response: send only urgent prose.
        $text = $urgentEligible ? self::URGENT_TEXT : self::EARLY_TEXT;
        $wrapped = self::wrapSystemReminder($text);

        $this->agentRunner->appendMessage(
            $context->runId,
            new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => $wrapped]],
                metadata: ['system_reminder' => true],
            ),
        );

        return $context;
    }

    public static function wrapSystemReminder(string $text): string
    {
        return "<system-reminder>\n".trim($text)."\n</system-reminder>";
    }

    /**
     * @param list<AfterTurnCommitEventSummary> $events
     */
    private function latestLlmStepCompletedInBatch(array $events): ?AfterTurnCommitEventSummary
    {
        $found = null;
        foreach ($events as $event) {
            if (RunEventTypeEnum::LlmStepCompleted->value === $event->type) {
                $found = $event;
            }
        }

        return $found;
    }

    private function positivePromptInputTokens(mixed $usage): ?int
    {
        if (!\is_array($usage)) {
            return null;
        }

        $tokens = $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null;
        if (\is_int($tokens) && $tokens > 0) {
            return $tokens;
        }

        return null;
    }

    private function remindersDisabledForChild(?RunStartedLaunchProjection $launch): bool
    {
        if (null === $launch || !$launch->isAgentChild()) {
            return false;
        }

        return 'fork' === $launch->childKind
            ? $this->config->disableForForks
            : $this->config->disableForSubagents;
    }

    private function resolveContextWindow(
        AfterTurnCommitHookContext $context,
        ?RunStartedLaunchProjection $launch,
    ): ?int {
        if (null !== $launch?->contextWindow && $launch->contextWindow > 0) {
            return $launch->contextWindow;
        }

        $model = null !== $context->runState->model ? trim($context->runState->model) : '';

        return $this->contextWindowFromCatalog('' !== $model ? $model : null);
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
