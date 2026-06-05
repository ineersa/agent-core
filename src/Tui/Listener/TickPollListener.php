<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

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
        $questionController->setRuntimeRefs($context);

        $context->ticks->add(static function () use ($poller, $state, $client, $screen, $questionCoordinator, $questionController): ?bool {
            $changedBlocks = $poller->poll(
                $state,
                $client,
                // Human input requested handler: enqueue an approval question.
                onHumanInputRequested: static function (RuntimeEvent $event) use ($client, $questionCoordinator): void {
                    $p = $event->payload;
                    $questionId = (string) ($p['question_id'] ?? '');
                    $runId = $event->runId;

                    if ('' === $questionId) {
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

                    $requestId = 'hitl_'.$questionId;
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
                },
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
            static $lastMsg = null;
            $msg = (RunActivityStateEnum::Idle === $state->activity || $state->activity->isTerminal())
                ? null
                : 'Working...';

            if ($msg !== $lastMsg) {
                $screen->setWorkingMessage($msg);
                $lastMsg = $msg;
            }

            return null;
        });
    }
}
