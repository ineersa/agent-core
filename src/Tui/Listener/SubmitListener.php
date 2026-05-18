<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\TranscriptEntry as PersistedTranscriptEntry;
use Ineersa\Tui\Command\ClearTranscript;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\ExitApplication;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\StatusUpdate;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Transcript\TranscriptEntry;
use Symfony\Component\Tui\Event\SubmitEvent;

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
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $sessionStore = $this->sessionStore;
        $router = $this->submissionRouter;
        $client = $context->client;
        $state = $context->state;
        $screen = $context->screen;
        $tui = $context->tui;

        $context->tui->addListener(static function (SubmitEvent $event) use (
            $client, $sessionStore, $state, $screen, $tui, $router,
        ) {
            $text = $screen->extract();
            if ('' === $text) {
                return;
            }

            // ── Route through command parser/registry ──
            $commandResult = $router->route($text);

            if (null !== $commandResult) {
                // ── Local command — apply typed effect ──
                self::applyCommandResult($commandResult, $state, $screen, $sessionStore, $tui);

                return;
            }

            // ── Normal prompt — route to runtime (existing behavior) ──

            // Append user message entry (plain text, no ANSI)
            $userEntry = new TranscriptEntry(
                text: str_replace("\n", "\n    ", $text),
                role: 'user',
            );
            $state->transcript[] = $userEntry;

            // Persist plain text (no theme/ANSI)
            $sessionStore->appendTranscriptEntry(
                $state->sessionId,
                new PersistedTranscriptEntry(
                    role: 'user',
                    text: $text,
                    meta: ['session_id' => $state->sessionId],
                ),
            );

            // Start a run if this is the first message
            if (null === $state->handle && null === $state->request) {
                $state->request = new StartRunRequest(
                    prompt: $text,
                    runId: $state->sessionId,
                );
                $state->handle = $client->start($state->request);
                $state->transcript[] = new TranscriptEntry(
                    text: \sprintf('Run started: %s', $text),
                    role: 'system',
                    style: 'accent',
                );
                $sessionStore->updateMetadata(
                    $state->sessionId,
                    [
                        'run_id' => $state->sessionId,
                        'prompt' => $text,
                    ],
                );
                $state->lastSeq = 0;
            } elseif (null !== $state->handle) {
                $client->send(
                    $state->handle->runId,
                    new UserCommand(type: 'message', text: $text),
                );
            }

            // Show processing indicator
            $state->transcript[] = new TranscriptEntry(
                text: 'Processing...',
                role: 'system',
                style: 'muted',
            );
            $screen->setWorkingMessage('Working...');

            // Update transcript display
            $screen->setTranscriptEntries($state->transcript);
        });
    }

    /**
     * Apply a command result's side effects to the TUI state/screen.
     */
    private static function applyCommandResult(
        CommandResult $result,
        \Ineersa\Tui\Runtime\TuiSessionState $state,
        \Ineersa\Tui\Screen\ChatScreen $screen,
        HatfieldSessionStore $sessionStore,
        \Symfony\Component\Tui\Tui $tui,
    ): void {
        if ($result instanceof TranscriptMessage) {
            // ── Append message to transcript ──
            $entry = new TranscriptEntry(
                text: $result->text,
                role: $result->role,
                style: $result->style,
            );
            $state->transcript[] = $entry;

            // Persist
            $sessionStore->appendTranscriptEntry(
                $state->sessionId,
                new PersistedTranscriptEntry(
                    role: $result->role,
                    text: $result->text,
                    meta: ['session_id' => $state->sessionId],
                ),
            );

            $screen->setTranscriptEntries($state->transcript);

            return;
        }

        if ($result instanceof ClearTranscript) {
            // ── Clear all transcript entries ──
            $state->transcript = [];
            $screen->setTranscriptEntries([]);

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
}
