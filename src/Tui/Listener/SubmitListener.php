<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\ClearTranscript;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\DispatchShellCommand;
use Ineersa\Tui\Command\ExitApplication;
use Ineersa\Tui\Command\StatusUpdate;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
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

                // ── Local command — apply typed effect ──
                self::applyCommandResult($commandResult, $state, $screen, $sessionStore, $tui, $blockFactory);

                return;
            }

            // ── Normal prompt — route to runtime (existing behavior) ──
            // No local echo or persistence: canonical runtime events project
            // user blocks (avoiding duplicate block IDs), and events.jsonl is
            // the single source of truth for transcript replay on resume.

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
                } elseif (null !== $state->handle) {
                    // Route subsequent chat messages as follow_up or steer
                    // based on authoritative run activity state:
                    //   - follow_up: normal next user message when idle/completed
                    //   - steer:     steering/injected message while active
                    if ($state->activity->isActive()) {
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

                return;
            }

            // Show processing indicator via the working status widget.
            // We intentionally do NOT add a "Processing..." transcript
            // block — it duplicates the Working status and clutters the
            // transcript.  The RuntimeEventPoller will transition the
            // working state to idle when events arrive.
            $screen->setWorkingMessage('Working...');

            // Update transcript display
            $screen->setTranscriptBlocks($state->transcript);
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

        // NoOp, DispatchRuntime, and unknown future variants are silently ignored.
        // DispatchRuntime will be wired by future tasks that add runtime execution.
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
                $state->handle = $client->shellExecute(
                    $shellCommand->command,
                    $state->sessionId,
                    $state->request->cwd ?? '',
                );
                $state->activity = RunActivityStateEnum::Starting;
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
                $client->send(
                    $state->handle->runId,
                    new UserCommand(type: 'shell_command', text: $shellCommand->command),
                );
            }

            // For first-input shellExecute() in InProcess, the shell command
            // completed synchronously and the completeRun() call already emitted
            // AgentEnd. The TUI poller will transition to Completed on next tick
            // and clear the working message. Show a brief working indicator until
            // the poller processes the terminal event.
            //
            // For subsequent shell commands via send(), the shell executes inline
            // and the working message will stay Running if the agent is active, or
            // be cleared on next tick if the run was already terminal.
            $screen->setWorkingMessage('Running...');
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
}
