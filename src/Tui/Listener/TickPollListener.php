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
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Tick listener that polls for new runtime events.
 *
 * Delegates polling logic to RuntimeEventPoller and updates the
 * transcript display and working status when new events arrive.
 *
 * Also wires runtime human_input.requested events into the TUI
 * QuestionCoordinator/QuestionController so that approval/interrupt
 * questions (e.g. SafeGuard) show interactive overlays and answers
 * are dispatched back to the runtime via answer_human commands.
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
        $state = $context->state;
        $client = $context->client;
        $screen = $context->screen;
        $questionCoordinator = $this->questionCoordinator;
        $questionController = $this->questionController;

        // Wire the question controller with TUI runtime references
        $questionController->setRuntimeRefs($context, $screen);

        $context->ticks->add(static function () use ($poller, $state, $client, $screen, $questionCoordinator, $questionController): ?bool {
            $onHitl = static function (RuntimeEvent $event) use ($client, $questionCoordinator): void {
                self::handleHumanInputRequested($event, $client, $questionCoordinator);
            };

            $onToolQuestion = static function (RuntimeEvent $event) use ($client, $questionCoordinator): void {
                self::handleToolQuestionRequested($event, $client, $questionCoordinator);
            };

            $onToolTerminal = static function (RuntimeEvent $event) use ($questionCoordinator, $questionController): void {
                self::handleToolTerminal($event, $questionCoordinator, $questionController);
            };

            $changedBlocks = $poller->poll(
                $state,
                $client,
                onHumanInputRequested: $onHitl,
                onToolQuestionRequested: $onToolQuestion,
                onToolTerminal: $onToolTerminal,
            );

            if (null !== $changedBlocks) {
                $screen->setTranscriptBlocks($state->transcript);
            }

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
            // SubmitListener sets 'Working...' optimistically on send;
            // this keeps it visible while active and clears it when idle/terminal.
            //
            // Always call setWorkingMessage — don't use a static last-value
            // cache. SubmitListener (and future features like shell commands)
            // may call setWorkingMessage directly between tick cycles, and a
            // stale static cache would skip the authoritative tick update,
            // permanently leaving a stuck working message.
            $msg = (RunActivityStateEnum::Idle === $state->activity || $state->activity->isTerminal())
                ? null
                : 'Working...';

            $screen->setWorkingMessage($msg);

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
     * from the tool that requested approval (e.g. SafeGuard).
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
            kind: QuestionKind::Approval,
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
        // Answer sends the user's selection; cancel sends a
        // fail-safe Deny so the run is not left stuck in
        // WaitingHuman when the user dismisses the overlay.
        $questionCoordinator->enqueue(
            $request,
            onAnswer: static function (mixed $answer) use ($client, $runId, $questionId): void {
                $client->send($runId, new UserCommand(
                    type: 'answer_human',
                    payload: [
                        'question_id' => $questionId,
                        'answer' => \is_scalar($answer) ? (string) $answer : 'Deny',
                    ],
                ));
            },
            onCancel: static function () use ($client, $runId, $questionId): void {
                $client->send($runId, new UserCommand(
                    type: 'answer_human',
                    payload: [
                        'question_id' => $questionId,
                        'answer' => 'Deny',
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
     * Confirm or Approval question in the coordinator, depending on
     * the event payload's kind field.
     *
     * This is invoked by RuntimeEventPoller::poll() each time a
     * tool_question.requested event is polled from the runtime stream.
     * The event carries a request_id, prompt, kind, schema, and metadata
     * from the tool that needs user interaction.
     *
     * Two question kinds are handled:
     *   - 'confirm' (default): boolean answer, used by bash background prompts.
     *     Answer sends 'yes'/'no' through answer_tool_question.
     *   - 'safeguard_approval': string answer (Allow once/Always allow/Deny),
     *     used by SafeGuard file-outside-CWD approvals. Answer sends the
     *     raw string answer through answer_tool_question with kind=safeguard_approval.
     *
     * Both are LOCAL tool questions:
     * - source is Tui (not AgentCore)
     * - transcript is false (no transcript block)
     * - answer sends answer_tool_question (not answer_human)
     * - no WaitingHuman state transition in AgentCore
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

        $kind = (string) ($p['kind'] ?? 'confirm');

        if ('safeguard_approval' === $kind) {
            self::handleApprovalToolQuestion($p, $requestId, $runId, $requestIdFromPayload, $client, $questionCoordinator);

            return;
        }

        // Default: Confirm-kind question (e.g. bash background prompt).
        self::handleConfirmToolQuestion($p, $requestId, $runId, $requestIdFromPayload, $client, $questionCoordinator);
    }

    /**
     * Handle a tool_question.requested event with kind='safeguard_approval'.
     *
     * Renders the SafeGuard approval overlay (Allow once / Always allow / Deny)
     * through QuestionKind::Approval. The answer is sent as a raw string through
     * answer_tool_question with the kind preserved so AnswerToolQuestionHandler
     * stores it as answer_text (not the boolean answer column).
     */
    /**
     * @param array<string, mixed> $p
     */
    private static function handleApprovalToolQuestion(
        array $p,
        string $requestId,
        string $runId,
        string $requestIdFromPayload,
        AgentSessionClient $client,
        QuestionCoordinator $questionCoordinator,
    ): void {
        // Parse schema from the stored JSON, or build default SafeGuard schema.
        $rawSchema = $p['schema'] ?? null;
        $schema = \is_string($rawSchema)
            ? (json_decode($rawSchema, true) ?? ['type' => 'string', 'enum' => ['Allow once', 'Always allow', 'Deny']])
            : (\is_array($rawSchema) ? $rawSchema : ['type' => 'string', 'enum' => ['Allow once', 'Always allow', 'Deny']]);

        $enum = $schema['enum'] ?? ['Allow once', 'Always allow', 'Deny'];
        $choices = array_map(
            static fn (string $label): QuestionOption => new QuestionOption($label),
            array_values($enum),
        );

        $request = new QuestionRequest(
            requestId: $requestId,
            source: QuestionSource::Tui,
            kind: QuestionKind::Approval,
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
                // QuestionController::Approval returns the label string directly
                // (e.g. 'Allow once', 'Always allow', 'Deny').
                $answerStr = \is_scalar($answer) ? (string) $answer : 'Deny';

                $client->send($runId, new UserCommand(
                    type: 'answer_tool_question',
                    payload: [
                        'request_id' => $requestIdFromPayload,
                        'answer' => $answerStr,
                        'kind' => 'safeguard_approval',
                    ],
                ));
            },
            onCancel: static function () use ($client, $runId, $requestIdFromPayload): void {
                $client->send($runId, new UserCommand(
                    type: 'answer_tool_question',
                    payload: [
                        'request_id' => $requestIdFromPayload,
                        'answer' => 'Deny',
                        'kind' => 'safeguard_approval',
                    ],
                ));
            },
        );
    }

    /**
     * Handle a tool_question.requested event with Confirm-kind (default).
     *
     * Renders a boolean yes/no confirmation. The answer is sent as a boolean
     * through answer_tool_question (backward-compatible with existing
     * AnswerToolQuestionHandler which resolves boolean answers).
     */
    /**
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
                    ],
                ));
            },
            onCancel: static function () use ($client, $runId, $requestIdFromPayload): void {
                $client->send($runId, new UserCommand(
                    type: 'answer_tool_question',
                    payload: [
                        'request_id' => $requestIdFromPayload,
                        'answer' => false,
                    ],
                ));
            },
        );
    }
}
