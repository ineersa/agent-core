<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\ClearTranscript;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\DispatchShellCommand;
use Ineersa\Tui\Command\ExitApplication;
use Ineersa\Tui\Command\Hotkey\HotkeyBindingDTO;
use Ineersa\Tui\Command\Hotkey\HotkeyTableData;
use Ineersa\Tui\Command\StatusUpdate;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Transcript\HotkeyTableRenderer;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Tui;

/**
 * Handles user message submission (Enter key in the editor).
 *
 * Routes submitted text through {@see SubmissionRouter}:
 *  - Normal prompts → runtime (AgentSessionClient)
 *  - Slash commands → local command registry
 *  - Shell commands → friendly "not yet supported" message
 *
 * Implements TuiListenerRegistrar for DI-driven registration.
 * All per-run state comes from TuiRuntimeContext; the service itself is stateless.
 */
final class SubmitListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly HatfieldSessionStore $sessionStore,
        private readonly SubmissionRouter $submissionRouter,
        private readonly TranscriptBlockFactory $blockFactory,
        private readonly QuestionCoordinator $coordinator,
        private readonly QuestionController $questionController,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $sessionStore = $this->sessionStore;
        $router = $this->submissionRouter;
        $blockFactory = $this->blockFactory;
        $client = $context->client;
        $state = $context->state;
        $screen = $context->screen;
        $tui = $context->tui;

        $questionCoordinator = $this->coordinator;
        $questionController = $this->questionController;

        $logger = $this->logger;

        // Wire the question controller with TUI runtime references
        $questionController->setRuntimeRefs($context, $screen);

        $context->tui->addListener(static function (SubmitEvent $event) use (
            $client, $sessionStore, $state, $screen, $tui, $router, $blockFactory,
            $questionCoordinator, $questionController, $logger,
        ) {
            $text = $screen->extract();
            if ('' === $text) {
                return;
            }

            // ── Question interception: route editor text to active question ──
            if ($questionCoordinator->actionRequired()) {
                $questionCoordinator->answer($text);
                $questionController->close();

                return;
            }

            // ── Route through command parser/registry ──
            $commandResult = $router->route($text);

            if (null !== $commandResult) {
                // ── Shell command — dispatch to runtime for execution ──
                if ($commandResult instanceof DispatchShellCommand) {
                    self::handleShellCommand(
                        $commandResult, $state, $screen, $sessionStore,
                        $blockFactory, $client, $logger,
                    );

                    return;
                }

                // ── DispatchRuntime — forward payload to runtime ──
                if ($commandResult instanceof DispatchRuntime) {
                    self::dispatchToRuntime(
                        $commandResult->payload, $state, $screen,
                        $sessionStore, $blockFactory, $client, $logger, $tui,
                    );

                    return;
                }

                // ── Local command — apply typed effect ──
                self::applyCommandResult($commandResult, $state, $screen, $sessionStore, $tui, $blockFactory);

                return;
            }

            // ── Normal prompt — route to runtime ──
            // No local echo or persistence: canonical runtime events project
            // user blocks (avoiding duplicate block IDs), and events.jsonl is
            // the single source of truth for transcript replay on resume.
            self::dispatchToRuntime($text, $state, $screen, $sessionStore, $blockFactory, $client, $logger, $tui);
        });
    }

    /**
     * Apply a command result's side effects to the TUI state/screen.
     */
    private static function applyCommandResult(
        CommandResult $result,
        TuiSessionState $state,
        ChatScreen $screen,
        HatfieldSessionStore $sessionStore,
        Tui $tui,
        TranscriptBlockFactory $blockFactory,
    ): void {
        if ($result instanceof TranscriptMessage) {
            // ── Append message to transcript (in-memory only) ──
            $block = self::blockForTranscriptMessage($result, $state, $blockFactory);
            $state->transcript[] = $block;

            $screen->setTranscriptBlocks($state->transcript);

            return;
        }

        if ($result instanceof ClearTranscript) {
            // ── Clear all transcript entries ──
            $state->transcript = [];
            $screen->setTranscriptBlocks([]);

            return;
        }

        if ($result instanceof ExitApplication) {
            // ── Stop the TUI event loop ──
            $tui->stop();

            return;
        }

        if ($result instanceof StatusUpdate) {
            // ── Set a keyed status entry ──
            $screen->setStatus($result->key, $result->value);

            return;
        }

        if ($result instanceof HotkeyTableData) {
            // Render a theme-colored hotkeys table via TuiTranscript renderer.
            $renderer = new HotkeyTableRenderer();
            $styledText = $renderer->render(
                self::hotkeyGroupsToArrays($result->groups),
                $screen->theme(),
                $result->emptyMessage,
            );
            $seq = \count($state->transcript) + 1;
            $state->transcript[] = $blockFactory->system(
                runId: $state->sessionId,
                text: $styledText,
                seq: $seq,
                style: 'hotkey-table',
            );
            $screen->setTranscriptBlocks($state->transcript);

            return;
        }

        // NoOp and unknown future variants are silently ignored.
        // DispatchRuntime is handled before applyCommandResult (see above).
    }

    /**
     * Dispatch a normal prompt or DispatchRuntime payload to the runtime.
     *
     * No local echo or persistence: canonical runtime events project
     * user blocks (avoiding duplicate block IDs), and events.jsonl is
     * the single source of truth for transcript replay on resume.
     */
    private static function dispatchToRuntime(
        string $text,
        TuiSessionState $state,
        ChatScreen $screen,
        HatfieldSessionStore $sessionStore,
        TranscriptBlockFactory $blockFactory,
        \Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient $client,
        LoggerInterface $logger,
        Tui $tui,
    ): void {
        // Show immediate visual feedback (◐ Working...) before heavy
        // synchronous work (session creation, system prompt discovery,
        // skills context building, runner start).  Force a terminal
        // repaint so the user sees the indicator instantly instead of
        // staring at a blank editor until the work completes.
        $screen->setWorkingMessage('Working...');
        try {
            $tui->requestRender();
            $tui->processRender();
        } catch (\Throwable $e) {
            // Non-fatal: render may fail if the terminal is in a
            // transient state.  The next tick will render normally.
            $logger->debug('SubmitListener: immediate render failed (non-fatal)', [
                'component' => 'SubmitListener',
                'exception' => $e,
                'session_id' => $state->sessionId,
            ]);
        }

        try {
            // Start a run if this is the first message
            if (null === $state->handle && (null === $state->request || '' === $state->sessionId)) {
                // ── Draft session promotion ──
                // If this is a lazy draft (sessionId === ''), create the
                // real session row now so no orphan records are left when
                // /new is typed but never followed by a message.
                if ('' === $state->sessionId) {
                    $state->sessionId = $sessionStore->createSession($text);
                    $screen->updateSessionId($state->sessionId);
                    $logger->info('Draft session promoted to real session', [
                        'component' => 'SubmitListener',
                        'event_type' => 'draft_promoted',
                        'session_id' => $state->sessionId,
                    ]);
                }

                // Merge any pre-configured draft request (e.g. from /new --model)
                // with the submitted text so model/reasoning metadata carries
                // forward and the run starts with the user-typed prompt.
                $mergedRequest = new StartRunRequest(
                    prompt: $text,
                    runId: $state->sessionId,
                    // $state->request is nullable; nullsafe is required
                    // to avoid a property-access error on null during
                    // draft promotion from a plain /new without --model.
                    // @phpstan-ignore nullsafe.neverNull
                    cwd: $state->request?->cwd ?? '',
                    // @phpstan-ignore nullsafe.neverNull
                    options: $state->request?->options ?? [],
                    model: $state->request?->model,
                    reasoning: $state->request?->reasoning,
                );
                $state->request = $mergedRequest;
                $state->handle = $client->start($state->request);
                $state->activity = RunActivityStateEnum::Starting;
                $sessionStore->updateMetadata(
                    $state->sessionId,
                    [
                        'run_id' => $state->sessionId,
                        'prompt' => $text,
                    ],
                );
                $state->lastSeq = 0;
            } elseif (null !== $state->handle && $state->isShellRun && $state->activity->isTerminal()) {
                // The previous run was a standalone shell command (first-input
                // !) that completed without ever calling runner->start().
                // Sending a follow_up on it would fail because the runner
                // does not know about this run ID.  Start a fresh LLM run
                // for this normal prompt.
                $state->isShellRun = false;
                $state->handle = null;

                $state->request = new StartRunRequest(
                    prompt: $text,
                    runId: $state->sessionId,
                    cwd: $state->request->cwd ?? '',
                );
                $state->handle = $client->start($state->request);
                $state->activity = RunActivityStateEnum::Starting;
                $sessionStore->updateMetadata(
                    $state->sessionId,
                    [
                        'run_id' => $state->sessionId,
                        'prompt' => $text,
                    ],
                );
                $state->lastSeq = 0;
            } elseif (null !== $state->handle) {
                // Route subsequent chat messages as follow_up or steer
                // based on authoritative run activity state:
                //   - follow_up: normal next user message when idle/completed
                //   - steer:     steering/injected message while active
                //
                // Special case — Cancelling:
                //   The run is in a grace window (tools/LLM aborting).
                //   Sending steer would be rejected by AgentCore; sending
                //   follow_up would race with the AdvanceRun dispatched by
                //   the cancellation abort flow.  Queue the message locally
                //   and dispatch it as follow_up only after the real
                //   Cancelled transition is observed by the poller.
                if (RunActivityStateEnum::Cancelling === $state->activity) {
                    $state->queuedFollowUp = $text;
                    $screen->setWorkingMessage('Message queued — waiting for cancellation to complete...');
                } elseif ($state->activity->isActive()) {
                    $client->send(
                        $state->handle->runId,
                        new UserCommand(type: 'steer', text: $text),
                    );
                } else {
                    $client->send(
                        $state->handle->runId,
                        new UserCommand(type: 'follow_up', text: $text),
                    );
                    // Transition to Starting so that subsequent submits
                    // while the agent picks up the follow_up use steer.
                    $state->activity = RunActivityStateEnum::Starting;
                }
            }
        } catch (\Throwable $e) {
            $state->activity = RunActivityStateEnum::Failed;
            $state->transcript[] = $blockFactory->error(
                runId: $state->sessionId,
                text: 'Runtime error: '.$e->getMessage(),
                seq: \count($state->transcript) + 1,
            );
            $screen->setWorkingMessage('');
            $screen->setTranscriptBlocks($state->transcript);

            $logger->error('SubmitListener: message dispatch failed', [
                'component' => 'SubmitListener',
                'event_type' => 'submit_dispatch_failed',
                'session_id' => $state->sessionId,
                'exception' => $e,
            ]);

            return;
        }

        // Update transcript display.
        // The working indicator was already shown before the
        // synchronous work above; the poller will transition it
        // to idle when runtime events arrive.
        $screen->setTranscriptBlocks($state->transcript);
    }

    /**
     * Handle shell command dispatch by sending it to the runtime for
     * execution. Creates a session when this is the first submitted
     * input. Shell commands do not invoke the LLM — output is projected
     * through tool_execution events in the transcript.
     *
     * Adds a user-message transcript block with the original submitted
     * text (including the `!` prefix) so the prompt history navigator
     * can recall shell commands via Up/Down.
     */
    private static function handleShellCommand(
        DispatchShellCommand $shellCommand,
        TuiSessionState $state,
        ChatScreen $screen,
        HatfieldSessionStore $sessionStore,
        TranscriptBlockFactory $blockFactory,
        \Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient $client,
        LoggerInterface $logger,
    ): void {
        try {
            // Create a session if this is the first input.
            if ('' === $state->sessionId) {
                $state->sessionId = $sessionStore->createSession('!'.$shellCommand->command);
                $screen->updateSessionId($state->sessionId);
                $logger->info('Draft session promoted for shell command', [
                    'component' => 'SubmitListener',
                    'event_type' => 'draft_promoted_shell',
                    'session_id' => $state->sessionId,
                ]);
            }

            // Add a user-message block so prompt history (Up/Down)
            // can recall the shell command after submission.
            $userSeq = \count($state->transcript) + 1;
            $state->transcript[] = $blockFactory->user(
                runId: $state->sessionId,
                text: $shellCommand->originalText,
                seq: $userSeq,
            );

            if (null === $state->handle) {
                // First input — execute shell without starting an LLM run.
                // shellExecute() is synchronous in InProcess transport: the
                // bash command executes, tool_exec events are persisted, and
                // completeRun() writes the terminal AgentEnd event before we
                // return. Transition to Completed immediately rather than
                // relying on the tick/poller cycle, so the working indicator
                // clears promptly. The poller will still pick up tool_exec
                // events on the next tick and project them as transcript blocks.
                $state->handle = $client->shellExecute(
                    $shellCommand->command,
                    $state->sessionId,
                    $state->request->cwd ?? '',
                );
                $state->activity = RunActivityStateEnum::Completed;
                $state->isShellRun = true; // track for normal-submit-after-shell restart
                $sessionStore->updateMetadata(
                    $state->sessionId,
                    [
                        'run_id' => $state->sessionId,
                        'prompt' => '!'.$shellCommand->command,
                    ],
                );
                $state->lastSeq = 0;
            } else {
                // Subsequent input — send shell command to existing run.
                // The worker in the tool consumer process executes bash
                // and writes tool_exec events to the canonical event store.
                $client->send(
                    $state->handle->runId,
                    new UserCommand(
                        type: 'shell_command',
                        text: $shellCommand->command,
                    ),
                );

                // The controller must NEVER synchronously call completeRun()
                // for shell commands because that races with the async worker
                // and produces [AgentEnd, tool_exec_start, tool_exec_end]
                // ordering — a LifecycleOrderValidator violation (issue #183).
                //
                // Activity transitions (Running → Completed) are handled by
                // TickPollListener from the authoritative event drain — we do
                // not set activity here because that would cause the next normal
                // submit to route as steer (active) instead of follow_up (terminal).
            }

            // For first-input shellExecute(): the command completed synchronously
            // (InProcess transport writes events directly) and activity is already
            // Completed — no working indicator needed.
            //
            // For subsequent shell commands (send()): activities are driven by
            // TickPollListener from the event drain.  tool_exec_start transitions
            // to Running, AgentEnd transitions back to Completed.  The window
            // between send() and the first event is brief for simple commands.
            $screen->setWorkingMessage(
                $state->activity->isTerminal() ? null : 'Running...',
            );
            $screen->setTranscriptBlocks($state->transcript);
        } catch (\Throwable $e) {
            $state->activity = RunActivityStateEnum::Failed;
            $state->transcript[] = $blockFactory->error(
                runId: $state->sessionId,
                text: 'Shell command error: '.$e->getMessage(),
                seq: \count($state->transcript) + 1,
            );
            $screen->setWorkingMessage('');
            $screen->setTranscriptBlocks($state->transcript);

            $logger->error('SubmitListener: shell command dispatch failed', [
                'component' => 'SubmitListener',
                'event_type' => 'shell_dispatch_failed',
                'session_id' => $state->sessionId,
                'exception' => $e,
            ]);
        }
    }

    private static function blockForTranscriptMessage(
        TranscriptMessage $result,
        TuiSessionState $state,
        TranscriptBlockFactory $blockFactory,
    ): TranscriptBlock {
        $seq = \count($state->transcript) + 1;

        return match ($result->role) {
            'user' => $blockFactory->user($state->sessionId, $result->text, $seq),
            'error' => $blockFactory->error($state->sessionId, $result->text, $seq),
            default => $blockFactory->system($state->sessionId, $result->text, $seq, $result->style),
        };
    }

    // ─── Hotkey table data adapter ────────────────────────────────────

    /**
     * Convert HotkeyTableData's grouped HotkeyBindingDTOs to plain arrays
     * suitable for the theme-aware {@see HotkeyTableRenderer}.
     *
     * @param array<string, list<HotkeyBindingDTO>> $groups
     *
     * @return array<string, list<array{keys: list<string>, action: string, description: string}>>
     */
    private static function hotkeyGroupsToArrays(array $groups): array
    {
        $result = [];
        foreach ($groups as $context => $bindings) {
            $result[$context] = array_map(
                static fn (HotkeyBindingDTO $b): array => [
                    'keys' => $b->keys,
                    'action' => $b->action,
                    'description' => $b->description,
                ],
                $bindings,
            );
        }

        return $result;
    }
}
