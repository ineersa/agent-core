<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\ContextBudget;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ContextBudgetReminderConfig;

/**
 * After-turn hook: queue one-shot wrap-up append messages when committed LLM
 * usage crosses context-budget thresholds.
 *
 * Uses only existing AgentCore surfaces (AfterTurnCommit, EventStore,
 * AgentRunner::appendMessage). No AgentCore reminder DTOs, markers, or
 * provider-injection fields.
 */
final readonly class ContextBudgetReminderHookSubscriber implements HookSubscriberInterface
{
    public const string EARLY_TEXT = 'Context usage is already very high. Stop further exploration and do not start new delegated work. Finish now with the best concise final answer or handoff, including concrete findings, incomplete work, and next steps.';
    public const string URGENT_TEXT = 'Context is nearly exhausted. Stop further exploration and do not start new delegated work. Finish now with the best concise final answer or handoff, including concrete findings, incomplete work, and next steps.';

    public function __construct(
        private EventStoreInterface $eventStore,
        private RunStoreInterface $runStore,
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

        $contextWindow = $this->resolveContextWindow($context->runId);
        if (null === $contextWindow) {
            return $context;
        }

        $issued = $this->issuedReminderKeysAfterLatestCompaction($context->runId);

        $earlyEligible = $inputTokens >= $this->config->earlyInputTokens
            && !\in_array('early', $issued, true)
            && !\in_array('urgent', $issued, true);

        $remaining = $contextWindow - $inputTokens;
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

    /**
     * Detect already queued/applied reminder messages after the latest successful
     * compaction barrier by exact wrapped text in generic command payloads.
     *
     * @return list<string>
     */
    private function issuedReminderKeysAfterLatestCompaction(string $runId): array
    {
        $earlyWrapped = self::wrapSystemReminder(self::EARLY_TEXT);
        $urgentWrapped = self::wrapSystemReminder(self::URGENT_TEXT);
        $keys = [];

        foreach ($this->eventStore->reverseFor($runId) as $event) {
            if (RunEventTypeEnum::ContextCompacted->value === $event->type) {
                break;
            }

            if (
                RunEventTypeEnum::AgentCommandQueued->value !== $event->type
                && RunEventTypeEnum::AgentCommandApplied->value !== $event->type
            ) {
                continue;
            }

            $text = $this->commandEventMessageText($event->payload);
            if ($text === $urgentWrapped && !\in_array('urgent', $keys, true)) {
                $keys[] = 'urgent';
            }
            if ($text === $earlyWrapped && !\in_array('early', $keys, true)) {
                $keys[] = 'early';
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function commandEventMessageText(array $payload): string
    {
        if (isset($payload['text']) && \is_string($payload['text']) && '' !== $payload['text']) {
            return $payload['text'];
        }

        $message = $payload['message'] ?? null;
        if (!\is_array($message)) {
            return '';
        }

        $content = $message['content'] ?? null;
        if (!\is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (\is_array($block) && isset($block['text']) && ('text' === ($block['type'] ?? null))) {
                $parts[] = (string) $block['text'];
            }
        }

        return implode('', $parts);
    }

    private function resolveContextWindow(string $runId): ?int
    {
        $fromRun = $this->contextWindowFromRunStarted($this->eventStore->firstFor($runId));
        if (null !== $fromRun) {
            return $fromRun;
        }

        $runState = $this->runStore->get($runId);
        $model = null !== $runState && null !== $runState->model ? trim($runState->model) : '';

        return $this->contextWindowFromCatalog('' !== $model ? $model : null);
    }

    private function contextWindowFromRunStarted(?RunEvent $event): ?int
    {
        if (null === $event || RunEventTypeEnum::RunStarted->value !== $event->type) {
            return null;
        }

        $inner = $event->payload['payload'] ?? null;
        if (!\is_array($inner)) {
            return null;
        }

        $metadata = $inner['metadata'] ?? null;
        if (!\is_array($metadata)) {
            return null;
        }

        $window = $metadata['context_window'] ?? null;

        return \is_int($window) && $window > 0 ? $window : null;
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
