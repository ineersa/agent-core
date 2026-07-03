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
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\SubagentLiveAttention;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;

/**
 * Tick listener that polls for new runtime events.
 *
 * Delegates polling logic to RuntimeEventPoller and updates the
 * transcript display and working status when new events arrive.
 *
 * Also wires runtime human_input.requested events into the TUI
 * QuestionCoordinator/QuestionController so that HITL/interrupt
 * questions show interactive overlays and answers are dispatched
 * back to the runtime via answer_human commands.
 *
 * Implements TuiListenerRegistrar for DI-driven registration.
 * The service itself is stateless; per-run state comes from the context.
 */
final class TickPollListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly RuntimeEventPoller $poller,
        private readonly SubagentLiveChildViewPoller $subagentLiveChildPoller,
        private readonly QuestionCoordinator $questionCoordinator,
        private readonly QuestionController $questionController,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $poller = $this->poller;
        $state = $context->state;
        $client = $context->client;
        $screen = $context->screen;
        $questionCoordinator = $this->questionCoordinator;
        $questionController = $this->questionController;
        $subagentLiveChildPoller = $this->subagentLiveChildPoller;

        // Wire the question controller with TUI runtime references
        $questionController->setRuntimeRefs($context, $screen);

        $context->ticks->add(static function () use ($poller, $state, $client, $screen, $questionCoordinator, $questionController, $subagentLiveChildPoller): ?bool {
            $onHitl = static function (RuntimeEvent $event) use ($client, $questionCoordinator): void {
                self::handleHumanInputRequested($event, $client, $questionCoordinator);
            };

            $onToolQuestion = static function (RuntimeEvent $event) use ($client, $questionCoordinator): void {
                self::handleToolQuestionRequested($event, $client, $questionCoordinator);
            };

            $onToolTerminal = static function (RuntimeEvent $event) use ($questionCoordinator, $questionController): void {
                self::handleToolTerminal($event, $questionCoordinator, $questionController);
            };

            $liveActive = $state->subagentLiveView->active;

            // Child-first on the shared JSONL pipe: events() re-buffers non-matching
            // run ids; polling the child run before the parent reduces child latency.
            if ($liveActive) {
                $childBlocks = $subagentLiveChildPoller->poll(
                    $state->subagentLiveView,
                    $client,
                    onHumanInputRequested: static function (RuntimeEvent $event) use ($client, $questionCoordinator, $state): void {
                        self::handleHumanInputRequested($event, $client, $questionCoordinator, $state, $screen);
                    },
                    onToolQuestionRequested: static function (RuntimeEvent $event) use ($client, $questionCoordinator, $state): void {
                        self::handleToolQuestionRequested($event, $client, $questionCoordinator, $state, $screen);
                    },
                    onToolTerminal: static function (RuntimeEvent $event) use ($questionCoordinator, $questionController): void {
                        self::handleToolTerminal($event, $questionCoordinator, $questionController);
                    },
                );
                // Only repaint transcript when new child blocks arrive; cached blocks stay on screen.
                if (null !== $childBlocks) {
                    $screen->setTranscriptBlocks($childBlocks);
                }
            }

            $changedBlocks = $poller->poll(
                $state,
                $client,
                onHumanInputRequested: $onHitl,
                onToolQuestionRequested: $onToolQuestion,
                onToolTerminal: $onToolTerminal,
            );

            if ($liveActive) {
                $selected = $state->subagentLiveView->selected;
                if (null !== $selected) {
                    $refreshed = $state->subagentLiveCatalog->findByArtifactId($selected->artifactId);
                    if (null !== $refreshed) {
                        $state->subagentLiveView->selected = $refreshed;
                        if (SubagentLiveStatusEnum::WaitingHuman === $refreshed->status) {
                            $state->subagentLiveView->childActivity = RunActivityStateEnum::WaitingHuman;
                        } elseif ($refreshed->isRunning()) {
                            $state->subagentLiveView->childActivity = RunActivityStateEnum::Running;
                        } elseif ($refreshed->isTerminal()) {
                            $state->subagentLiveView->childActivity = match ($refreshed->status) {
                                SubagentLiveStatusEnum::Completed, SubagentLiveStatusEnum::Done => RunActivityStateEnum::Completed,
                                SubagentLiveStatusEnum::Failed => RunActivityStateEnum::Failed,
                                SubagentLiveStatusEnum::Cancelled => RunActivityStateEnum::Cancelled,
                                default => RunActivityStateEnum::Completed,
                            };
                        }
                    }
                }
            } elseif (null !== $changedBlocks) {
                $screen->setTranscriptBlocks($state->transcript);
            }

            // The pending-queue widget (slot 4, above the editor) reflects transient
            // queued steer/follow-up messages. Sync every tick regardless of transcript
            // changes, since a user.message_queued event mutates state without a block.
            $screen->syncQueuedUserMessages($state->queuedUserMessages);

            // Open the question overlay whenever the coordinator has an
            // active request and the controller is not already showing it
            // AND is not awaiting free-form editor input (__other__ escape
            // hatch). This handles: (a) new questions becoming active after
            // polling uncovers a human_input.requested event, and (b) queued
            // questions advancing into the active slot on later ticks. The
            // isAwaitingFreeForm() check prevents rebuilding the select
            // overlay while the user types a custom answer in the editor.
            if ($questionCoordinator->actionRequired() && !$questionController->isOpen() && !$questionController->isAwaitingFreeForm()) {
                $activeRequest = $questionCoordinator->activeRequest();
                if (null !== $activeRequest) {
                    $questionController->open($activeRequest);
                }
            }

            // Self-heal: if the run left the active states (cancelled/terminal via ESC
            // or error) while a HITL question is still pending, the question is
            // orphaned. reject() advances the queue WITHOUT invoking callbacks (safe
            // for a dead run — sends nothing to the runtime) and close() clears
            // awaitingFreeForm so a subsequently-queued HITL question can activate.
            // Without this, ESC during __other__ free-form typing cancels the run but
            // leaves awaitingFreeForm=true, silently suppressing the next question.
            if ($questionCoordinator->actionRequired()) {
                $activeRequest = $questionCoordinator->activeRequest();
                if (null !== $activeRequest && self::shouldRejectOrphanedQuestion($state, $activeRequest)) {
                    $questionCoordinator->reject();
                    $questionController->close();
                }
            }

            // Update working status based on authoritative activity state.
            // SubmitListener sets 'Working...' optimistically on send;
            // this keeps it visible while active and clears it when idle/terminal.
            //
            // Cancelling gets its own message ('Cancelling...') because
            // CancelListener sets it once on Escape, and this tick renderer
            // would otherwise overwrite it back to 'Working...' on the very
            // next tick. Rendering the correct message from the activity state
            // rather than a binary idle/active toggle keeps the footer truthful
            // even when the activity state is sticky Cancelling through late deltas.
            //
            // Always call setWorkingMessage — don't use a static last-value
            // cache. SubmitListener (and future features like shell commands)
            // may call setWorkingMessage directly between tick cycles, and a
            // stale static cache would skip the authoritative tick update,
            // permanently leaving a stuck working message.
            if ($liveActive) {
                $parentMsg = match (true) {
                    RunActivityStateEnum::Cancelling === $state->activity => 'Cancelling...',
                    RunActivityStateEnum::Idle === $state->activity || $state->activity->isTerminal() => null,
                    null === $state->handle && $state->activity->isActive() => null,
                    default => 'Working...',
                };
                $childMsg = match ($state->subagentLiveView->childActivity) {
                    RunActivityStateEnum::WaitingHuman => 'Child waiting for your input...',
                    RunActivityStateEnum::Cancelling => 'Child cancelling...',
                    default => $state->subagentLiveView->childActivity->isActive()
                        ? 'Child agent working...'
                        : 'Child agent idle',
                };
                $liveWorking = null !== $parentMsg
                    ? $parentMsg.' | '.$childMsg
                    : $childMsg;
                // Live-view-only cache: generic tick path avoids static last-value (see comment above).
                if ($liveWorking !== $state->subagentLiveView->lastLiveWorkingMessage) {
                    $state->subagentLiveView->lastLiveWorkingMessage = $liveWorking;
                    $screen->setWorkingMessage($liveWorking);
                }

                $selected = $state->subagentLiveView->selected;
                if (null !== $selected) {
                    $liveStatus = $selected->needsAttention()
                        ? \sprintf('Subagent live: %s needs your input — answer below; /agents-main to return.', $selected->agentName)
                        : \sprintf(
                            'Subagent live: %s [%s] — type to steer next step; /agents-main to return.',
                            $selected->agentName,
                            $selected->statusLabel(),
                        );
                    $screen->setStatus('agents-live', $liveStatus);
                }

                return null;
            }

            $msg = match (true) {
                RunActivityStateEnum::Cancelling === $state->activity => 'Cancelling...',
                RunActivityStateEnum::Idle === $state->activity || $state->activity->isTerminal() => null,
                // Resumed sessions replay activity but have no live handle until
                // start_run/follow_up attaches the controller — do not show Working.
                null === $state->handle && $state->activity->isActive() => null,
                default => 'Working...',
            };

            SubagentLiveAttention::syncMainAttention($state, $screen);

            $screen->setWorkingMessage($msg);

            return null;
        });
    }

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
    private static function handleHumanInputRequested(
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

        $requestId = self::hitlRequestId($runId, $questionId);

        if ($questionCoordinator->hasRequest($requestId)) {
            return;
        }

        $schema = \is_array($p['schema'] ?? null) ? $p['schema'] : ['type' => 'string'];
        $kind = self::resolveQuestionKind($p);
        $choices = self::buildChoices($p, $schema);
        $header = self::resolveQuestionHeader($sessionState, $runId, $p, 'asks');

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
     * Resolve the QuestionKind from the human_input payload.
     *
     * Priority order:
     *   1. ui_kind from payload (text/confirm/choice/approval)
     *   2. Fallback kind from payload (legacy pre-factory events)
     *   3. Fallback to schema-driven derivation (pre-QH-04 payloads)
     *
     * @param array<string, mixed> $p
     */
    private static function resolveQuestionKind(array $p): QuestionKind
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
    private static function buildChoices(array $p, array $schema): array
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
    private static function handleToolTerminal(
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
        if (QuestionSource::Tui !== $active->source) {
            return;
        }

        if ($active->toolCallId !== $toolCallId) {
            return;
        }

        if ($active->runId !== $event->runId) {
            return;
        }

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
    private static function handleToolQuestionRequested(
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

        $requestId = self::toolRequestId($runId, $requestIdFromPayload);

        if ($questionCoordinator->hasRequest($requestId)) {
            return;
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
            self::handleChoiceToolQuestion($p, $schema, $requestId, $runId, $requestIdFromPayload, $client, $questionCoordinator, $sessionState, $screen);

            return;
        }

        if ($isBoolean || 'confirm' === $kind) {
            self::handleConfirmToolQuestion($p, $requestId, $runId, $requestIdFromPayload, $client, $questionCoordinator, $sessionState, $screen);

            return;
        }

        // Degenerate fallback: unknown non-confirm schemas degrade to the generic choice
        // overlay instead of throwing (which would drop later poll-batch events). Producers
        // should supply enum or string schemas so choices are usable; this path is best-effort.
        self::handleChoiceToolQuestion($p, $schema, $requestId, $runId, $requestIdFromPayload, $client, $questionCoordinator, $sessionState, $screen);
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
    private static function handleChoiceToolQuestion(
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
            header: self::resolveQuestionHeader($sessionState, $runId, $p, 'tool question'),
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
            onCancel: static function () use ($client, $runId, $requestIdFromPayload): void {
                $client->send($runId, new UserCommand(
                    type: 'answer_tool_question',
                    payload: [
                        'request_id' => $requestIdFromPayload,
                        'answer' => 'cancel',
                        'kind' => 'approval',
                    ],
                ));
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
    private static function handleConfirmToolQuestion(
        array $p,
        string $requestId,
        string $runId,
        string $requestIdFromPayload,
        AgentSessionClient $client,
        QuestionCoordinator $questionCoordinator,
        ?TuiSessionState $sessionState = null,
    ): void {
        $request = new QuestionRequest(
            requestId: $requestId,
            source: QuestionSource::Tui,
            kind: QuestionKind::Confirm,
            prompt: (string) ($p['prompt'] ?? 'Confirmation required.'),
            schema: ['type' => 'boolean'],
            choices: [],
            header: self::resolveQuestionHeader($sessionState, $runId, $p, 'approval'),
            allowOther: false,
            runId: $runId,
            questionId: $requestIdFromPayload,
            toolCallId: (string) ($p['tool_call_id'] ?? ''),
            toolName: (string) ($p['tool_name'] ?? ''),
            transcript: false,
        );

        $questionCoordinator->enqueue(
            $request,
            onAnswer: static function (mixed $answer) use ($client, $runId, $requestIdFromPayload): void {
                $boolAnswer = \is_string($answer) && 'yes' === strtolower($answer);

                $client->send($runId, new UserCommand(
                    type: 'answer_tool_question',
                    payload: [
                        'request_id' => $requestIdFromPayload,
                        'answer' => $boolAnswer,
                        'kind' => 'confirm',
                    ],
                ));
            },
            onCancel: static function () use ($client, $runId, $requestIdFromPayload): void {
                $client->send($runId, new UserCommand(
                    type: 'answer_tool_question',
                    payload: [
                        'request_id' => $requestIdFromPayload,
                        'answer' => false,
                        'kind' => 'confirm',
                    ],
                ));
            },
        );
    }

    private static function hitlRequestId(string $runId, string $questionId): string
    {
        return 'hitl_'.substr(hash('sha256', $runId.'|'.$questionId), 0, 16);
    }

    private static function toolRequestId(string $runId, string $requestIdFromPayload): string
    {
        return 'tool_'.substr(hash('sha256', $runId.'|'.$requestIdFromPayload), 0, 16);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function resolveQuestionHeader(?TuiSessionState $sessionState, string $runId, array $payload, string $suffix): ?string
    {
        if (null !== ($payload['header'] ?? null) && '' !== (string) $payload['header']) {
            return (string) $payload['header'];
        }

        $agentName = self::resolveSubagentLabel($sessionState, $runId);
        if (null === $agentName) {
            return null;
        }

        return \sprintf('Subagent %s %s', $agentName, $suffix);
    }

    private static function resolveSubagentLabel(?TuiSessionState $sessionState, string $runId): ?string
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

    private static function shouldRejectOrphanedQuestion(TuiSessionState $state, QuestionRequest $activeRequest): bool
    {
        $parentRunId = null !== $state->handle ? $state->handle->runId : $state->sessionId;
        if ($activeRequest->runId === $parentRunId) {
            return !$state->activity->isActive();
        }

        $live = $state->subagentLiveView;
        if ($live->active && null !== $live->selected && $activeRequest->runId === $live->selected->agentRunId) {
            return $live->childActivity->isTerminal();
        }

        return false;
    }
}
