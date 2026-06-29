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
use Ineersa\Tui\Runtime\TabInputModeEnum;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

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
        private readonly QuestionCoordinator $questionCoordinator,
        private readonly QuestionController $questionController,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $poller = $this->poller;
        $client = $context->client;
        $screen = $context->screen;
        $questionCoordinator = $this->questionCoordinator;
        $questionController = $this->questionController;

        // Wire the question controller with TUI runtime references
        $questionController->setRuntimeRefs($context, $screen);

        $context->ticks->add(static function () use ($poller, $context, $client, $screen, $questionCoordinator, $questionController): ?bool {
            // POC: ALWAYS poll the PARENT state ($context->state) regardless of active tab.
            // This ensures the parent runtime events are always consumed so:
            //   - Parent transcript stays up to date (critical for subagent artifact detection)
            //   - HITL/tool questions from parent run are forwarded correctly
            //   - The parent run does not stall while a read-only child tab is active
            //
            // The active tab's transcript is then displayed on screen.
            $parentState = $context->state;
            $activeState = $context->activeState();

            $onHitl = static function (RuntimeEvent $event) use ($client, $questionCoordinator): void {
                self::handleHumanInputRequested($event, $client, $questionCoordinator);
            };

            $onToolQuestion = static function (RuntimeEvent $event) use ($client, $questionCoordinator): void {
                self::handleToolQuestionRequested($event, $client, $questionCoordinator);
            };

            $onToolTerminal = static function (RuntimeEvent $event) use ($questionCoordinator, $questionController): void {
                self::handleToolTerminal($event, $questionCoordinator, $questionController);
            };

            // Poll the parent state (always, even when child tab is active)
            $poller->poll(
                $parentState,
                $client,
                onHumanInputRequested: $onHitl,
                onToolQuestionRequested: $onToolQuestion,
                onToolTerminal: $onToolTerminal,
            );

            // Display the active tab's transcript
            $screen->setTranscriptBlocks($activeState->transcript);

            // The pending-queue widget (slot 4, above the editor) reflects transient
            // queued steer/follow-up messages. Sync every tick regardless of transcript
            // changes, since a user.message_queued event mutates state without a block.
            $screen->syncQueuedUserMessages($activeState->queuedUserMessages);

            // Open the question overlay whenever the coordinator has an
            // active request and the controller is not already showing it.
            // This handles: (a) new questions becoming active after polling
            // uncovers a human_input.requested event, and (b) queued
            // questions advancing into the active slot on later ticks.
            if ($questionCoordinator->actionRequired() && !$questionController->isOpen()) {
                $activeRequest = $questionCoordinator->activeRequest();
                if (null !== $activeRequest) {
                    $questionController->open($activeRequest);
                }
            }

            // Update working status based on authoritative activity state.
            $msg = match (true) {
                RunActivityStateEnum::Cancelling === $activeState->activity => 'Cancelling...',
                RunActivityStateEnum::Idle === $activeState->activity || $activeState->activity->isTerminal() => null,
                null === $activeState->handle && $activeState->activity->isActive() => null,
                default => 'Working...',
            };

            $screen->setWorkingMessage($msg);

            // POC: update status panel with active tab mode indicator
            // Shows "⛝ Read-only" when a subagent artifact tab is active
            $tabService = $context->tabService;
            if (null !== $tabService) {
                $activeTab = $tabService->active();
                if (null !== $activeTab && TabInputModeEnum::ReadOnly === $activeTab->inputMode) {
                    $screen->registry()->setStatus('tab-mode', '⛝ Read-only — no steer');
                } else {
                    // Clear tab mode status when on interactive tab
                    $screen->registry()->setStatus('tab-mode', null);
                }
            }

            return null;
        });
    }

    /**
     * Handle a human_input.requested runtime event by enqueuing an
     * Approval question in the coordinator.
     *
     * This is invoked by RuntimeEventPoller::poll() each time a
     * human_input.requested event is polled from the runtime stream.
     * The event carries a question_id, schema, prompt, and metadata
     * from the tool that requested approval.
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
    ): void {
        $p = $event->payload;
        $questionId = (string) ($p['question_id'] ?? '');
        $runId = $event->runId;

        if ('' === $questionId) {
            return;
        }

        $requestId = 'hitl_'.$questionId;

        if ($questionCoordinator->hasRequest($requestId)) {
            return;
        }

        // Build choices from schema enum if available
        $schema = \is_array($p['schema'] ?? null) ? $p['schema'] : ['type' => 'string'];
        $enum = $schema['enum'] ?? null;
        $choices = \is_array($enum) && [] !== $enum
            ? array_map(
                static fn (string $label): QuestionOption => new QuestionOption($label),
                array_values($enum),
            )
            : [];

        $request = new QuestionRequest(
            requestId: $requestId,
            source: QuestionSource::AgentCore,
            kind: QuestionKind::Choice,
            prompt: (string) ($p['prompt'] ?? 'Approval required.'),
            schema: $schema,
            choices: $choices,
            allowOther: false,
            runId: $runId,
            questionId: $questionId,
            toolCallId: (string) ($p['tool_call_id'] ?? ''),
            toolName: (string) ($p['tool_name'] ?? ''),
            transcript: true,
        );

        // Enqueue the question with answer and cancel callbacks.
        // Answer sends the user's selection. Cancel sends a generic
        // 'cancel' sentinel — no extension-specific vocabulary leaks
        // into this generic human_input path. The receiving extension
        // owns fail-closed semantics via its resolveApprovalAnswer()
        // contract, which must treat unrecognized answers as denied.
        $questionCoordinator->enqueue(
            $request,
            onAnswer: static function (mixed $answer) use ($client, $runId, $questionId): void {
                $client->send($runId, new UserCommand(
                    type: 'answer_human',
                    payload: [
                        'question_id' => $questionId,
                        'answer' => \is_scalar($answer) ? (string) $answer : 'cancel',
                    ],
                ));
            },
            onCancel: static function () use ($client, $runId, $questionId): void {
                $client->send($runId, new UserCommand(
                    type: 'answer_human',
                    payload: [
                        'question_id' => $questionId,
                        'answer' => 'cancel',
                    ],
                ));
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
    ): void {
        $p = $event->payload;
        $requestIdFromPayload = (string) ($p['request_id'] ?? '');
        $runId = $event->runId;

        if ('' === $requestIdFromPayload) {
            return;
        }

        $requestId = 'tool_'.$requestIdFromPayload;

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
            self::handleChoiceToolQuestion($p, $schema, $requestId, $runId, $requestIdFromPayload, $client, $questionCoordinator);

            return;
        }

        if ($isBoolean || 'confirm' === $kind) {
            self::handleConfirmToolQuestion($p, $requestId, $runId, $requestIdFromPayload, $client, $questionCoordinator);

            return;
        }

        // Degenerate fallback: unknown non-confirm schemas degrade to the generic choice
        // overlay instead of throwing (which would drop later poll-batch events). Producers
        // should supply enum or string schemas so choices are usable; this path is best-effort.
        self::handleChoiceToolQuestion($p, $schema, $requestId, $runId, $requestIdFromPayload, $client, $questionCoordinator);
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
                // Choice returns the label string directly.
                $answerStr = \is_scalar($answer) ? (string) $answer : '';

                $client->send($runId, new UserCommand(
                    type: 'answer_tool_question',
                    payload: [
                        'request_id' => $requestIdFromPayload,
                        'answer' => $answerStr,
                        'kind' => 'approval',
                    ],
                ));
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
    ): void {
        $request = new QuestionRequest(
            requestId: $requestId,
            source: QuestionSource::Tui,
            kind: QuestionKind::Confirm,
            prompt: (string) ($p['prompt'] ?? 'Confirmation required.'),
            schema: ['type' => 'boolean'],
            choices: [],
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
}
