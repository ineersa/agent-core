<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionOption;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveAttention;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;

/**
 * Translates canonical human-input events into QuestionCoordinator actions.
 *
 * Extracted from TickPollListener so polling/rendering stays separate from
 * question overlay wiring and orphan self-heal policy.
 */
final class RuntimeQuestionEventHandler
{
    /**
     * Handle a human_input.requested runtime event by enqueuing a
     * kind-driven question in the coordinator.
     *
     * This is invoked by RuntimeEventPoller::poll() each time a
     * human_input.requested event is polled from the runtime stream.
     * The event carries a question_id, schema, prompt, and metadata
     * from the tool that requested input.
     *
     * The question kind is resolved from ui_kind (preferred) or schema:
     *   - ui_kind=text/confirm/choice/approval -> direct kind mapping
     *   - schema.type=boolean -> Confirm
     *   - schema.enum non-empty -> Choice
     *   - else -> Text
     *
     * Choices are built from the payload 'choices' field (structured
     * QuestionOption-compatible array) or fall back to schema.enum.
     *
     * Confirm kind answers are normalized to boolean true/false to
     * match the downstream boolean schema expectation.
     *
     * A guard against duplicate request IDs prevents enqueueing the
     * same question twice if the event stream replays (e.g. after a
     * cursor reset). RuntimeEventPoller seq-based deduplication should
     * prevent replays under normal operation, but this is a safety net.
     */
    public function handleHumanInputRequested(
        RuntimeEvent $event,
        AgentSessionClient $client,
        QuestionCoordinator $questionCoordinator,
        ?TuiSessionState $sessionState = null,
        ?ChatScreen $screen = null,
    ): void {
        $p = $event->payload;
        $questionId = (string) ($p['question_id'] ?? '');
        $runId = $event->runId;

        if ('' === $questionId) {
            return;
        }

        $requestId = $this->hitlRequestId($runId, $questionId);

        if ($questionCoordinator->hasRequest($requestId)) {
            return;
        }

        $schema = \is_array($p['schema'] ?? null) ? $p['schema'] : ['type' => 'string'];
        $kind = $this->resolveQuestionKind($p);
        $choices = $this->buildChoices($p, $schema);
        $header = $this->resolveQuestionHeader($sessionState, $runId, $p, 'asks');
        // Exhaustive tool-call approval enums (SafeGuard, etc.) must not expose
        // free-form "Type your answer". Model-turn ask_human keeps free-form.
        $allowOther = ($p['continuation_kind'] ?? null) !== 'tool_call';

        $request = new QuestionRequest(
            requestId: $requestId,
            kind: $kind,
            prompt: (string) ($p['prompt'] ?? 'Approval required.'),
            choices: $choices,
            header: $header,
            allowOther: $allowOther,
            runId: $runId,
            toolCallId: (string) ($p['tool_call_id'] ?? ''),
            toolName: (string) ($p['tool_name'] ?? ''),
        );

        // Enqueue the question with answer and cancel callbacks.
        // Answer sends the user's selection, normalized to the
        // expected type (boolean for confirm, string otherwise).
        // Cancel sends a generic 'Cancelled by user' sentinel — no extension-
        // specific vocabulary leaks into this generic human_input
        // path. The receiving extension owns fail-closed semantics
        // via its resolveApprovalAnswer() contract, which must treat
        // unrecognized answers as denied.
        $questionCoordinator->enqueue(
            $request,
            onAnswer: static function (mixed $answer) use ($client, $runId, $questionId, $kind, $sessionState, $screen): void {
                if (QuestionKind::Confirm === $kind) {
                    $boolAnswer = \is_string($answer) && 'yes' === strtolower($answer);

                    $client->send($runId, new UserCommand(
                        type: 'answer_human',
                        payload: [
                            'question_id' => $questionId,
                            'answer' => $boolAnswer,
                        ]
                    ));
                } else {
                    $answerStr = \is_scalar($answer) ? (string) $answer : 'cancel';

                    $client->send($runId, new UserCommand(
                        type: 'answer_human',
                        payload: [
                            'question_id' => $questionId,
                            'answer' => $answerStr,
                        ]
                    ));
                }

                if (null !== $sessionState && null !== $screen) {
                    SubagentLiveAttention::clearWaitingHumanForRun($sessionState, $screen, $runId);
                }
            },
            onCancel: static function () use ($client, $runId, $questionId, $sessionState, $screen): void {
                $client->send($runId, new UserCommand(
                    type: 'answer_human',
                    payload: [
                        'question_id' => $questionId,
                        'answer' => 'Cancelled by user',
                    ]
                ));

                // Question dismiss is not child-run cancellation; clear needs-input only.
                if (null !== $sessionState && null !== $screen) {
                    SubagentLiveAttention::clearWaitingHumanForRun($sessionState, $screen, $runId);
                }
            }
        );
    }

    public function shouldRejectOrphanedQuestion(TuiSessionState $state, QuestionRequest $activeRequest): bool
    {
        $parentRunId = null !== $state->handle ? $state->handle->runId : $state->sessionId;
        if ($activeRequest->runId === $parentRunId) {
            // Parent HITL can arrive after a turn completed (e.g. post-subagent ask_human).
            // Activity must stay WaitingHuman; never treat that as an orphaned question.
            if (RunActivityStateEnum::WaitingHuman === $state->activity) {
                return false;
            }

            return !$state->activity->isActive();
        }

        $live = $state->subagentLiveView;
        if ($live->active && null !== $live->selected && $activeRequest->runId === $live->selected->agentRunId) {
            return $live->childActivity->isTerminal();
        }

        return false;
    }

    /**
     * Resolve the QuestionKind from the human_input payload.
     *
     * Priority order:
     *   1. ui_kind from payload (text/confirm/choice/approval)
     *   2. Fallback kind from payload (legacy pre-factory events)
     *   3. Fallback to schema-driven derivation (pre-QH-04 payloads)
     *
     * @param array<string, mixed> $p
     */
    private function resolveQuestionKind(array $p): QuestionKind
    {
        $kind = (string) ($p['ui_kind'] ?? $p['kind'] ?? '');

        // 'interrupt' is a transport marker from AskHumanPayloadFactory, not a UI
        // kind. If ui_kind was absent and only the transport kind leaked through,
        // fall through to schema-driven derivation instead of rendering an empty
        // Choice overlay that the user cannot answer.
        if ('' !== $kind && 'interrupt' !== $kind) {
            return match ($kind) {
                'text' => QuestionKind::Text,
                'confirm', 'approval' => QuestionKind::Confirm,
                'choice' => QuestionKind::Choice,
                default => QuestionKind::Choice,
            };
        }

        // Fallback: derive from schema
        $schema = \is_array($p['schema'] ?? null) ? $p['schema'] : ['type' => 'string'];

        if (($schema['type'] ?? '') === 'boolean') {
            return QuestionKind::Confirm;
        }

        if (isset($schema['enum']) && \is_array($schema['enum']) && [] !== $schema['enum']) {
            return QuestionKind::Choice;
        }

        return QuestionKind::Text;
    }

    /**
     * Build QuestionOption list from the payload choices field or schema enum.
     *
     * Payload choices (structured [{label, description?}]) take priority.
     * Falls back to schema.enum bare string labels.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed> $schema
     *
     * @return list<QuestionOption>
     */
    private function buildChoices(array $p, array $schema): array
    {
        if (isset($p['choices']) && \is_array($p['choices']) && [] !== $p['choices']) {
            return array_values(array_map(
                static function (mixed $choice): QuestionOption {
                    if (\is_array($choice)) {
                        return new QuestionOption(
                            label: (string) ($choice['label'] ?? ''),
                            description: (string) ($choice['description'] ?? '')
                        );
                    }

                    // Bare-string choice (defensive: AskHumanPayloadFactory emits
                    // structured entries, but bare strings are a plausible
                    // hand-crafted shape that must not throw TypeError).
                    return new QuestionOption(label: (string) $choice);
                },
                $p['choices']
            ));
        }

        $enum = $schema['enum'] ?? null;

        if (\is_array($enum) && [] !== $enum) {
            return array_map(
                static fn (string $label): QuestionOption => new QuestionOption($label),
                array_values($enum)
            );
        }

        return [];
    }

    private function hitlRequestId(string $runId, string $questionId): string
    {
        return 'hitl_'.substr(hash('sha256', $runId.'|'.$questionId), 0, 16);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveQuestionHeader(?TuiSessionState $sessionState, string $runId, array $payload, string $suffix): ?string
    {
        if (null !== ($payload['header'] ?? null) && '' !== (string) $payload['header']) {
            return (string) $payload['header'];
        }

        $agentName = $this->resolveSubagentLabel($sessionState, $runId);
        if (null === $agentName) {
            return null;
        }

        return \sprintf('Subagent %s %s', $agentName, $suffix);
    }

    private function resolveSubagentLabel(?TuiSessionState $sessionState, string $runId): ?string
    {
        if (null === $sessionState) {
            return null;
        }

        $live = $sessionState->subagentLiveView;
        if ($live->active && null !== $live->selected && $live->selected->agentRunId === $runId) {
            return $live->selected->agentName;
        }

        foreach ($sessionState->subagentLiveCatalog->all() as $catalogChild) {
            if ($catalogChild->agentRunId === $runId) {
                return $catalogChild->agentName;
            }
        }

        return null;
    }
}
