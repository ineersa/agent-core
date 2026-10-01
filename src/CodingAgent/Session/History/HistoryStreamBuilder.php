<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\ContextBudget\ContextBudgetReminderHookSubscriber;

/**
 * Incremental retained-history accumulator for one forward scan.
 *
 * Events must be applied in ascending sequence order. Each event can be released
 * after {@see apply()} returns.
 */
final class HistoryStreamBuilder
{
    /** @var list<int> */
    private array $retainedTurnNos = [];

    /** @var array<int, string> */
    private array $promptsByTurnNo = [];

    private int $positionTurnNo = 0;

    private ?string $initialPrompt = null;

    private ?string $pendingHumanPrompt = null;

    private bool $finished = false;

    private int $eventCount = 0;

    /** @var list<array{seq: int, turn_no: int, type: string, created_at: string, summary: string}> */
    private array $sanitizedEventTail = [];

    private EventInspectionSummarizer $eventInspectionSummarizer;

    private int $eventTailLimit;

    private ?int $latestProviderInputTokens = null;

    private int $latestProviderInputTokensSeq = 0;

    private int $latestAutoCompactionAttemptSeq = 0;

    /** @var list<'early'|'urgent'> */
    private array $issuedReminderKeys = [];

    /** @var array<string, true> */
    private array $appliedShellIdempotencyKeys = [];

    private ?RunStartedLaunchProjection $runStartedLaunch = null;

    public function __construct(
        ?EventInspectionSummarizer $eventInspectionSummarizer = null,
        int $eventTailLimit = EventInspectionSummarizer::DEFAULT_TAIL_LIMIT,
    ) {
        $this->eventInspectionSummarizer = $eventInspectionSummarizer ?? new EventInspectionSummarizer();
        $this->eventTailLimit = max(1, $eventTailLimit);
    }

    public static function fromSnapshot(
        HistoryProjectionSnapshot $snapshot,
        ?EventInspectionSummarizer $eventInspectionSummarizer = null,
    ): self {
        $builder = new self($eventInspectionSummarizer);
        $builder->retainedTurnNos = $snapshot->history->retainedTurnNos;
        $builder->promptsByTurnNo = $snapshot->history->promptsByTurnNo;
        $builder->positionTurnNo = $snapshot->history->positionTurnNo;
        $builder->initialPrompt = $snapshot->initialPrompt;
        $builder->pendingHumanPrompt = $snapshot->pendingHumanPrompt;
        $builder->eventCount = $snapshot->eventCount;
        $builder->sanitizedEventTail = $snapshot->sanitizedEventTail;
        if (null !== $snapshot->eligibleAutoCompactionInputTokens) {
            // Eligible tokens remain open relative to lastSeq until a later auto attempt.
            $builder->latestProviderInputTokens = $snapshot->eligibleAutoCompactionInputTokens;
            $builder->latestProviderInputTokensSeq = $snapshot->lastSeq;
            $builder->latestAutoCompactionAttemptSeq = 0;
        } elseif ($snapshot->lastSeq > 0) {
            // No eligible measurement means either none exists or an auto attempt
            // already covered everything through lastSeq.
            $builder->latestProviderInputTokens = null;
            $builder->latestProviderInputTokensSeq = 0;
            $builder->latestAutoCompactionAttemptSeq = $snapshot->lastSeq;
        }
        $builder->issuedReminderKeys = $snapshot->issuedReminderKeys;
        $builder->appliedShellIdempotencyKeys = $snapshot->appliedShellIdempotencyKeys;
        $builder->runStartedLaunch = $snapshot->runStartedLaunch;

        return $builder;
    }

    public function apply(RunEvent $event): void
    {
        if ($this->finished) {
            throw new \LogicException('HistoryStreamBuilder cannot accept events after finish().');
        }

        ++$this->eventCount;
        $this->sanitizedEventTail[] = $this->eventInspectionSummarizer->summarize($event);
        if (\count($this->sanitizedEventTail) > $this->eventTailLimit) {
            $this->sanitizedEventTail = \array_slice(
                $this->sanitizedEventTail,
                -$this->eventTailLimit,
            );
        }

        $this->observeDerivedFields($event);

        if (RunEventTypeEnum::RunStarted->value === $event->type) {
            $text = HistoryProjector::extractInitialUserText($event);
            if ('' !== $text) {
                $this->initialPrompt = $text;
            }

            return;
        }

        if (RunEventTypeEnum::AgentCommandApplied->value === $event->type
            || RunEventTypeEnum::AgentCommandQueued->value === $event->type) {
            $kind = \is_string($event->payload['kind'] ?? null) ? $event->payload['kind'] : null;
            if (RunEventTypeEnum::AgentCommandApplied->value === $event->type
                && 'shell_command' === $kind) {
                $idempotencyKey = $event->payload['idempotency_key'] ?? null;
                if (\is_string($idempotencyKey) && '' !== $idempotencyKey) {
                    $this->appliedShellIdempotencyKeys[$idempotencyKey] = true;
                }
            }

            // Only honest human input seeds a selectable prompt.
            // append_message is generated (context budget / completion) — not user history.
            if (RunEventTypeEnum::AgentCommandApplied->value === $event->type
                && \in_array($kind, ['follow_up', 'steer'], true)) {
                $text = \is_string($event->payload['text'] ?? null) ? $event->payload['text'] : '';
                if ('' === $text) {
                    $message = $event->payload['message'] ?? null;
                    if (\is_array($message)) {
                        $text = HistoryProjector::extractTextFromContent($message['content'] ?? []);
                    }
                }
                if ('' !== $text) {
                    // Latest applied human prompt wins when multiple precede one anchor.
                    $this->pendingHumanPrompt = $text;
                }
            }

            return;
        }

        if (RunEventTypeEnum::TurnAdvanced->value === $event->type) {
            $turnNo = (int) ($event->payload['turn_no'] ?? $event->turnNo);
            if ($turnNo <= 0) {
                return;
            }

            if (!\in_array($turnNo, $this->retainedTurnNos, true)) {
                $this->retainedTurnNos[] = $turnNo;
            }

            // Attach pending human prompt (or initial RunStarted prompt for first anchor).
            if (null !== $this->pendingHumanPrompt) {
                $this->promptsByTurnNo[$turnNo] = $this->pendingHumanPrompt;
                $this->pendingHumanPrompt = null;
            } elseif (null !== $this->initialPrompt) {
                // First retained anchor receives the session-start prompt once.
                $this->promptsByTurnNo[$turnNo] = $this->initialPrompt;
            }
            // Never re-attach the session-start prompt to later internal anchors.
            $this->initialPrompt = null;

            $this->positionTurnNo = $turnNo;

            return;
        }

        if (RunEventTypeEnum::HistoryPositionSet->value === $event->type) {
            $turnNo = (int) ($event->payload['position_turn_no'] ?? $event->turnNo);
            if (0 === $turnNo) {
                $this->positionTurnNo = 0;

                return;
            }
            if (\in_array($turnNo, $this->retainedTurnNos, true)) {
                $this->positionTurnNo = $turnNo;
            }

            return;
        }

        if (RunEventTypeEnum::HistoryTailDiscarded->value === $event->type) {
            $after = (int) ($event->payload['after_turn_no'] ?? 0);
            $this->retainedTurnNos = array_values(array_filter(
                $this->retainedTurnNos,
                static fn (int $t): bool => $t <= $after,
            ));
            foreach (array_keys($this->promptsByTurnNo) as $promptTurn) {
                if (!\in_array($promptTurn, $this->retainedTurnNos, true)) {
                    unset($this->promptsByTurnNo[$promptTurn]);
                }
            }
            $this->pendingHumanPrompt = null;
            if (0 === $after || [] === $this->retainedTurnNos) {
                $this->positionTurnNo = 0;
            } elseif (\in_array($after, $this->retainedTurnNos, true)) {
                $this->positionTurnNo = $after;
            } elseif ($this->positionTurnNo > $after) {
                $this->positionTurnNo = $this->retainedTurnNos[array_key_last($this->retainedTurnNos)];
            }
            // Compaction events leave pending human prompt intact (not handled here).
        }
    }

    public function finish(): HistoryDTO
    {
        $this->finished = true;

        // Drop invalid position if it failed to materialize as a retained anchor.
        if (0 !== $this->positionTurnNo && !\in_array($this->positionTurnNo, $this->retainedTurnNos, true)) {
            $this->positionTurnNo = [] !== $this->retainedTurnNos
                ? $this->retainedTurnNos[array_key_last($this->retainedTurnNos)]
                : 0;
        }

        return new HistoryDTO(
            retainedTurnNos: $this->retainedTurnNos,
            promptsByTurnNo: $this->promptsByTurnNo,
            positionTurnNo: $this->positionTurnNo,
        );
    }

    /**
     * @return list<int>
     */
    public function provisionalRetainedTurnNos(): array
    {
        return $this->retainedTurnNos;
    }

    /**
     * @return array<int, string>
     */
    public function provisionalPromptsByTurnNo(): array
    {
        return $this->promptsByTurnNo;
    }

    public function provisionalPositionTurnNo(): int
    {
        return $this->positionTurnNo;
    }

    public function finishSnapshot(int $lastSeq): HistoryProjectionSnapshot
    {
        return new HistoryProjectionSnapshot(
            history: $this->finish(),
            lastSeq: $lastSeq,
            initialPrompt: $this->initialPrompt,
            pendingHumanPrompt: $this->pendingHumanPrompt,
            eventCount: $this->eventCount,
            sanitizedEventTail: $this->sanitizedEventTail,
            eligibleAutoCompactionInputTokens: $this->eligibleAutoCompactionInputTokens(),
            issuedReminderKeys: $this->issuedReminderKeys,
            appliedShellIdempotencyKeys: $this->appliedShellIdempotencyKeys,
            runStartedLaunch: $this->runStartedLaunch,
        );
    }

    private function observeDerivedFields(RunEvent $event): void
    {
        if (RunEventTypeEnum::RunStarted->value === $event->type) {
            $this->runStartedLaunch = $this->extractRunStartedLaunch($event);

            return;
        }

        if (
            (RunEventTypeEnum::ContextCompactionStarted->value === $event->type
                || RunEventTypeEnum::ContextCompactionFailed->value === $event->type)
            && 'auto' === ($event->payload['trigger'] ?? null)
        ) {
            $this->latestAutoCompactionAttemptSeq = max($this->latestAutoCompactionAttemptSeq, $event->seq);

            return;
        }

        if (RunEventTypeEnum::ContextCompacted->value === $event->type) {
            $this->issuedReminderKeys = [];

            return;
        }

        if (
            RunEventTypeEnum::LlmStepCompleted->value === $event->type
            || RunEventTypeEnum::LlmStepAborted->value === $event->type
        ) {
            $usage = $event->payload['usage'] ?? [];
            $tokens = $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null;
            if (\is_int($tokens) && $tokens > 0) {
                $this->latestProviderInputTokens = $tokens;
                $this->latestProviderInputTokensSeq = $event->seq;
            }

            return;
        }

        if (
            RunEventTypeEnum::AgentCommandQueued->value === $event->type
            || RunEventTypeEnum::AgentCommandApplied->value === $event->type
        ) {
            $text = $this->commandEventMessageText($event->payload);
            if ($this->isUrgentReminderText($text) && !\in_array('urgent', $this->issuedReminderKeys, true)) {
                $this->issuedReminderKeys[] = 'urgent';
            }
            if ($this->isEarlyReminderText($text) && !\in_array('early', $this->issuedReminderKeys, true)) {
                $this->issuedReminderKeys[] = 'early';
            }
        }
    }

    private function isEarlyReminderText(string $text): bool
    {
        return ContextBudgetReminderHookSubscriber::wrapSystemReminder(
            ContextBudgetReminderHookSubscriber::EARLY_TEXT,
        ) === $text;
    }

    private function isUrgentReminderText(string $text): bool
    {
        return ContextBudgetReminderHookSubscriber::wrapSystemReminder(
            ContextBudgetReminderHookSubscriber::URGENT_TEXT,
        ) === $text;
    }

    private function eligibleAutoCompactionInputTokens(): ?int
    {
        if (null === $this->latestProviderInputTokens || $this->latestProviderInputTokensSeq <= 0) {
            return null;
        }

        if ($this->latestAutoCompactionAttemptSeq >= $this->latestProviderInputTokensSeq) {
            return null;
        }

        return $this->latestProviderInputTokens;
    }

    private function extractRunStartedLaunch(RunEvent $event): ?RunStartedLaunchProjection
    {
        $inner = $event->payload['payload'] ?? null;
        if (!\is_array($inner)) {
            return null;
        }

        $metadata = $inner['metadata'] ?? null;
        if (!\is_array($metadata)) {
            return null;
        }

        $model = $metadata['model'] ?? null;
        if (!\is_string($model) || '' === trim($model)) {
            return null;
        }

        $session = $metadata['session'] ?? [];
        if (!\is_array($session)) {
            $session = [];
        }

        $sessionKind = \is_string($session['kind'] ?? null) ? $session['kind'] : 'main';
        $childKind = \is_string($session['child_kind'] ?? null) ? $session['child_kind'] : null;
        $parentRunId = \is_string($session['parent_run_id'] ?? null) ? $session['parent_run_id'] : null;
        $agentName = \is_string($session['agent_name'] ?? null) ? $session['agent_name'] : null;
        $artifactId = \is_string($session['artifact_id'] ?? null) ? $session['artifact_id'] : null;
        $interactive = !\array_key_exists('interactive', $session) || false !== $session['interactive'];

        $reasoning = $metadata['reasoning'] ?? null;
        if (\is_string($reasoning)) {
            $reasoning = trim($reasoning);
            $reasoning = '' === $reasoning ? null : $reasoning;
        } else {
            $reasoning = null;
        }

        $contextWindow = $metadata['context_window'] ?? null;
        if (!\is_int($contextWindow) || $contextWindow <= 0) {
            $contextWindow = null;
        }

        $allowedTools = null;
        $allowedExtensions = null;
        if ('agent_child' === $sessionKind) {
            $toolsScope = $metadata['tools_scope'] ?? null;
            $allowedTools = [];
            if (\is_array($toolsScope) && isset($toolsScope['allowed_tools']) && \is_array($toolsScope['allowed_tools'])) {
                $allowedTools = array_values(array_filter(
                    $toolsScope['allowed_tools'],
                    static fn (mixed $name): bool => \is_string($name) && '' !== $name,
                ));
            }

            $extensions = $metadata['extensions'] ?? null;
            $allowedExtensions = [];
            if (\is_array($extensions)) {
                $allowedExtensions = array_values(array_filter(
                    $extensions,
                    static fn (mixed $name): bool => \is_string($name) && '' !== $name,
                ));
            }
        }

        return new RunStartedLaunchProjection(
            model: trim($model),
            reasoning: $reasoning,
            contextWindow: $contextWindow,
            sessionKind: $sessionKind,
            childKind: $childKind,
            parentRunId: $parentRunId,
            agentName: $agentName,
            artifactId: $artifactId,
            interactive: $interactive,
            allowedTools: $allowedTools,
            allowedExtensions: $allowedExtensions,
        );
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
}
