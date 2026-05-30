<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\TranscriptEntry as PersistedTranscriptEntry;
use Ineersa\Tui\Command\ClearTranscript;
use Ineersa\Tui\Command\CommandResult;
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

        $context->tui->addListener(static function (SubmitEvent $event) use (
            $client, $sessionStore, $state, $screen, $tui, $router, $blockFactory,
            $questionCoordinator, $questionController,
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
                // ── Local command — apply typed effect ──
                self::applyCommandResult($commandResult, $state, $screen, $sessionStore, $tui, $blockFactory);

                return;
            }

            // ── Normal prompt — route to runtime (existing behavior) ──

            $state->transcript[] = $blockFactory->user(
                runId: $state->sessionId,
                text: str_replace("\n", "\n    ", $text),
                seq: \count($state->transcript) + 1,
            );

            // Persist plain text (no theme/ANSI)
            $sessionStore->appendTranscriptEntry(
                $state->sessionId,
                new PersistedTranscriptEntry(
                    role: 'user',
                    text: $text,
                    meta: ['session_id' => $state->sessionId],
                ),
            );

            try {
                // Start a run if this is the first message
                if (null === $state->handle && null === $state->request) {
                    $state->request = new StartRunRequest(
                        prompt: $text,
                        runId: $state->sessionId,
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
            // ── Append message to transcript ──
            $block = self::blockForTranscriptMessage($result, $state, $blockFactory);
            $state->transcript[] = $block;

            // Persist
            $sessionStore->appendTranscriptEntry(
                $state->sessionId,
                new PersistedTranscriptEntry(
                    role: $result->role,
                    text: $result->text,
                    meta: ['session_id' => $state->sessionId, 'style' => $result->style],
                ),
            );

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
