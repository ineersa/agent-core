<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\TranscriptEntry as PersistedTranscriptEntry;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Transcript\TranscriptEntry;
use Symfony\Component\Tui\Event\SubmitEvent;

/**
 * Handles user message submission (Enter key in the editor).
 *
 * Implements TuiListenerRegistrar for DI-driven registration.
 * All per-run state comes from TuiRuntimeContext; the service itself is stateless.
 */
final class SubmitListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly HatfieldSessionStore $sessionStore,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $sessionStore = $this->sessionStore;
        $client = $context->client;
        $state = $context->state;
        $screen = $context->screen;

        $context->tui->addListener(static function (SubmitEvent $event) use (
            $client, $sessionStore, $state, $screen,
        ) {
            $text = $screen->extract();
            if ('' === $text) {
                return;
            }

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
}
