<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionOption;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveAttention;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;

/**
 * Translates runtime HITL and tool-question events into QuestionCoordinator actions.
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

        $request = new QuestionRequest(
            requestId: $requestId,
            source: QuestionSource::AgentCore,
            kind: $kind,
            prompt: (string) ($p['prompt'] ?? 'Approval required.'),
            schema: $schema,
            choices: $choices,
            header: $header,
            default: $p['default'] ?? null,
            allowOther: true,
            runId: $runId,
            questionId: $questionId,
            toolCallId: (string) ($p['tool_call_id'] ?? ''),
            toolName: (string) ($p['tool_name'] ?? ''),
            transcript: true,
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
                        ],
                    ));
                } else {
                    $answerStr = \is_scalar($answer) ? (string) $answer : 'cancel';

                    $client->send($runId, new UserCommand(
                        type: 'answer_human',
                        payload: [
                            'question_id' => $questionId,
                            'answer' => $answerStr,
                        ],
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
                    ],
                ));

                if (null !== $sessionState && null !== $screen) {
                    SubagentLiveAttention::markCancelledForRun($sessionState, $screen, $runId);
                }
            },
        );
    }

    /**
     * Handle a tool execution terminal event by cancelling any active
     * Tui-source question whose toolCallId matches the terminal event.
     *
     * This is invoked by RuntimeEventPoller::poll() each time a
     * tool_execution.completed, tool_execution.failed, or
     * tool_execution.cancelled event is polled from the runtime stream.
     * When the tool returns (with output, error, or cancellation) while a
     * local tool question overlay is still open, this dismisses the stale
     * question so the user cannot answer a prompt for a tool that is no
     * longer running.
     *
     * Cancelling the coordinator sends a fail-safe false answer through
     * the registered cancel callback, which dispatches an
     * answer_tool_question=false command. The store's idempotency makes
     * this a noop if the adapter already cancelled or returned before the
     * answer arrives.
     */
    public function handleToolTerminal(
        RuntimeEvent $event,
        QuestionCoordinator $questionCoordinator,
        QuestionController $questionController,
    ): void {
        $p = $event->payload;
        $toolCallId = (string) ($p['tool_call_id'] ?? '');

        if ('' === $toolCallId) {
            return;
        }

        $active = $questionCoordinator->activeRequest();
        if (null === $active) {
            return;
        }

        // Only cancel if the active question is a local Tui-source question
        // (tool-local prompt, not AgentCore HITL) and the toolCallId matches.
        // Run-id match prevents cross-clearing a parent or sibling child that
        // happens to share a tool_call_id.
        if (QuestionSource::Tui !== $active->source) {
            return;
        }

        if ($active->toolCallId !== $toolCallId) {
            return;
        }

        if ($active->runId !== $event->runId) {
            return;
        }

        // cancel() invokes the registered onCancel callback for this request:
        // confirm sends answer=false; choice/approval sends answer='cancel'.
        // Both paths clear the needs-input latch for this run when session/screen
        // were wired at enqueue time.
        $questionCoordinator->cancel();

        // Close the visual overlay so the stale prompt is not visible.
        // The overlay is removed even if it was already dismissed by user
        // action — close() handles the no-op case internally.
        $questionController->close();
    }

    /**
     * Handle a tool_question.requested runtime event by enqueuing a
     * schema-driven question in the coordinator.
     *
     * This is invoked by RuntimeEventPoller::poll() each time a
     * tool_question.requested event is polled from the runtime stream.
     * The event carries a request_id, prompt, kind, schema, and metadata
     * from the tool that needs user interaction.
     *
     * The question overlay is determined by the schema:
     *   - schema has 'enum' -> Choice overlay (enum values as buttons)
     *   - schema type=boolean -> Confirm overlay
     *   - else -> Text overlay
     *
     * All tool_question.requested events are LOCAL tool questions:
     * - source is Tui (not AgentCore)
     * - transcript is false (no transcript block)
     * - answer sends answer_tool_question (not answer_human)
     * - no WaitingHuman state transition in AgentCore
     *
     * This contains ZERO extension-specific knowledge. The extension's
     * schema (from requireApproval) drives both the TUI rendering and
     * the server-side answer routing.
     *
     * A guard against duplicate request IDs prevents enqueueing the
     * same question twice if the event stream replays.
     */
    public function handleToolQuestionRequested(
        RuntimeEvent $event,
        AgentSessionClient $client,
        QuestionCoordinator $questionCoordinator,
        ?TuiSessionState $sessionState = null,
        ?ChatScreen $screen = null,
    ): void {
        $p = $event->payload;
        $requestIdFromPayload = (string) ($p['request_id'] ?? '');
        $runId = $event->runId;

        if ('' === $requestIdFromPayload) {
            return;
        }

        $requestId = $this->toolRequestId($runId, $requestIdFromPayload);

        if ($questionCoordinator->hasRequest($requestId)) {
            return;
        }

        if (null !== $sessionState && null !== $screen) {
            SubagentLiveAttention::markChildNeedsInputForRun($sessionState, $screen, $runId);
        }

        // Parse schema to determine the overlay type.
        $kind = (string) ($p['kind'] ?? '');
        $rawSchema = $p['schema'] ?? null;
        $schema = \is_string($rawSchema)
            ? (json_decode($rawSchema, true) ?? [])
            : (\is_array($rawSchema) ? $rawSchema : []);

        $hasEnum = isset($schema['enum']) && \is_array($schema['enum']) && [] !== $schema['enum'];
        $isBoolean = ($schema['type'] ?? '') === 'boolean';

        if ($hasEnum) {
            $this->handleChoiceToolQuestion($p, $schema, $requestId, $runId, $requestIdFromPayload, $client, $questionCoordinator, $sessionState, $screen);

            return;
        }

        if ($isBoolean || 'confirm' === $kind) {
            $this->handleConfirmToolQuestion($p, $requestId, $runId, $requestIdFromPayload, $client, $questionCoordinator, $sessionState, $screen);

            return;
        }

        // Degenerate fallback: unknown non-confirm schemas degrade to the generic choice
        // overlay instead of throwing (which would drop later poll-batch events). Producers
        // should supply enum or string schemas so choices are usable; this path is best-effort.
        $this->handleChoiceToolQuestion($p, $schema, $requestId, $runId, $requestIdFromPayload, $client, $questionCoordinator, $sessionState, $screen);
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
                            description: (string) ($choice['description'] ?? ''),
                        );
                    }

                    // Bare-string choice (defensive: AskHumanPayloadFactory emits
                    // structured entries, but bare strings are a plausible
                    // hand-crafted shape that must not throw TypeError).
                    return new QuestionOption(label: (string) $choice);
                },
                $p['choices'],
            ));
        }

        $enum = $schema['enum'] ?? null;

        if (\is_array($enum) && [] !== $enum) {
            return array_map(
                static fn (string $label): QuestionOption => new QuestionOption($label),
                array_values($enum),
            );
        }

        return [];
    }

    /**
     * Handle a tool_question.requested with an enum schema.
     *
     * Renders a Choice overlay with the schema's enum values as buttons.
     * The answer is sent as a raw string through answer_tool_question.
     *
     * @param array<string, mixed> $p
     * @param array<string, mixed> $schema
     */
    private function handleChoiceToolQuestion(
        array $p,
        array $schema,
        string $requestId,
        string $runId,
        string $requestIdFromPayload,
        AgentSessionClient $client,
        QuestionCoordinator $questionCoordinator,
        ?TuiSessionState $sessionState = null,
        ?ChatScreen $screen = null,
    ): void {
        $enum = $schema['enum'] ?? [];
        $choices = array_map(
            static fn (string $label): QuestionOption => new QuestionOption($label),
            array_values($enum),
        );

        $request = new QuestionRequest(
            requestId: $requestId,
            source: QuestionSource::Tui,
            kind: QuestionKind::Choice,
            prompt: (string) ($p['prompt'] ?? 'Approval required.'),
            schema: $schema,
            choices: $choices,
            header: $this->resolveQuestionHeader($sessionState, $runId, $p, 'tool question'),
            allowOther: false,
            runId: $runId,
            questionId: $requestIdFromPayload,
            toolCallId: (string) ($p['tool_call_id'] ?? ''),
            toolName: (string) ($p['tool_name'] ?? ''),
            transcript: false,
        );

        $questionCoordinator->enqueue(
            $request,
            onAnswer: static function (mixed $answer) use ($client, $runId, $requestIdFromPayload, $sessionState, $screen): void {
                $answerStr = \is_scalar($answer) ? (string) $answer : '';

                $client->send($runId, new UserCommand(
                    type: 'answer_tool_question',
                    payload: [
                        'request_id' => $requestIdFromPayload,
                        'answer' => $answerStr,
                        'kind' => 'approval',
                    ],
                ));

                if (null !== $sessionState && null !== $screen) {
                    SubagentLiveAttention::clearWaitingHumanForRun($sessionState, $screen, $runId);
                }
            },
            onCancel: static function () use ($client, $runId, $requestIdFromPayload, $sessionState, $screen): void {
                $client->send($runId, new UserCommand(
                    type: 'answer_tool_question',
                    payload: [
                        'request_id' => $requestIdFromPayload,
                        'answer' => 'cancel',
                        'kind' => 'approval',
                    ],
                ));

                // Choice/enum cancel must clear child needs-input the same way
                // confirm cancel and answer paths do (symmetric attention lifecycle).
                if (null !== $sessionState && null !== $screen) {
                    SubagentLiveAttention::clearWaitingHumanForRun($sessionState, $screen, $runId);
                }
            },
        );
    }

    /**
     * Handle a tool_question.requested event with a boolean schema.
     *
     * Renders a boolean yes/no confirmation. The answer is sent as a boolean
     * through answer_tool_question.
     *
     * @param array<string, mixed> $p
     */
    private function handleConfirmToolQuestion(
        array $p,
        string $requestId,
        string $runId,
        string $requestIdFromPayload,
        AgentSessionClient $client,
        QuestionCoordinator $questionCoordinator,
        ?TuiSessionState $sessionState = null,
        ?ChatScreen $screen = null,
    ): void {
        $request = new QuestionRequest(
            requestId: $requestId,
            source: QuestionSource::Tui,
            kind: QuestionKind::Confirm,
            prompt: (string) ($p['prompt'] ?? 'Confirmation required.'),
            schema: ['type' => 'boolean'],
            choices: [],
            header: $this->resolveQuestionHeader($sessionState, $runId, $p, 'approval'),
            allowOther: false,
            runId: $runId,
            questionId: $requestIdFromPayload,
            toolCallId: (string) ($p['tool_call_id'] ?? ''),
            toolName: (string) ($p['tool_name'] ?? ''),
            transcript: false,
        );

        $questionCoordinator->enqueue(
            $request,
            onAnswer: static function (mixed $answer) use ($client, $runId, $requestIdFromPayload, $sessionState, $screen): void {
                $boolAnswer = \is_string($answer) && 'yes' === strtolower($answer);

                $client->send($runId, new UserCommand(
                    type: 'answer_tool_question',
                    payload: [
                        'request_id' => $requestIdFromPayload,
                        'answer' => $boolAnswer,
                        'kind' => 'confirm',
                    ],
                ));

                if (null !== $sessionState && null !== $screen) {
                    SubagentLiveAttention::clearWaitingHumanForRun($sessionState, $screen, $runId);
                }
            },
            onCancel: static function () use ($client, $runId, $requestIdFromPayload, $sessionState, $screen): void {
                $client->send($runId, new UserCommand(
                    type: 'answer_tool_question',
                    payload: [
                        'request_id' => $requestIdFromPayload,
                        'answer' => false,
                        'kind' => 'confirm',
                    ],
                ));

                if (null !== $sessionState && null !== $screen) {
                    SubagentLiveAttention::clearWaitingHumanForRun($sessionState, $screen, $runId);
                }
            },
        );
    }

    private function hitlRequestId(string $runId, string $questionId): string
    {
        return 'hitl_'.substr(hash('sha256', $runId.'|'.$questionId), 0, 16);
    }

    private function toolRequestId(string $runId, string $requestIdFromPayload): string
    {
        return 'tool_'.substr(hash('sha256', $runId.'|'.$requestIdFromPayload), 0, 16);
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
