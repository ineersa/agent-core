<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\TranscriptEntry as PersistedTranscriptEntry;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Transcript\TranscriptEntry;

/**
 * Initializes session state for the interactive TUI.
 *
 * Handles:
 *   - New session creation (generates ID, persists initial entry)
 *   - Session resumption (loads existing transcript from disk)
 *   - Initial run start (if a pre-configured request is provided)
 *
 * Extracted from InteractiveMode::run() so the session lifecycle
 * is independently testable and the run() method stays lean.
 */
final readonly class SessionInitializer
{
    public function __construct(
        private HatfieldSessionStore $sessionStore,
    ) {
    }

    /**
     * Initialize session state and return ready-to-use TuiSessionState.
     *
     * @param string               $cwd       Project working directory
     * @param string               $sessionId Existing session ID to resume; empty = new session
     * @param StartRunRequest|null $request   Optional pre-configured start request
     */
    public function initialize(
        string $cwd,
        string $sessionId = '',
        ?StartRunRequest $request = null,
    ): TuiSessionState {
        $resuming = '' !== $sessionId && $this->sessionStore->exists($cwd, $sessionId);

        if (!$resuming) {
            $sessionId = $this->sessionStore->generateId();
            $promptText = null !== $request ? $request->prompt : '';
            $this->sessionStore->createSession($cwd, $promptText, $sessionId);
        }

        $state = new TuiSessionState($sessionId, $cwd, $resuming);

        // Inject session ID as the run ID when starting with an initial prompt
        if (null !== $request && '' !== $request->prompt && '' === $request->runId) {
            $state->request = new StartRunRequest(
                prompt: $request->prompt,
                runId: $sessionId,
                cwd: $request->cwd,
                options: $request->options,
                model: $request->model,
                reasoning: $request->reasoning,
            );
        } elseif (null !== $request) {
            $state->request = $request;
        }

        return $state;
    }

    /**
     * Build the initial transcript entries for the session.
     *
     * Returns plain model entries; theme colors/prefixes are applied
     * at display time by ChatScreen/TranscriptWidget.
     *
     * @return list<TranscriptEntry>
     */
    public function buildInitialTranscript(TuiSessionState $state): array
    {
        if ($state->resuming) {
            $entries = $this->loadTranscriptEntries($state->cwd, $state->sessionId);
            if ([] === $entries) {
                return [new TranscriptEntry(
                    text: 'Session '.$state->sessionId.' — no messages yet.',
                    role: 'system',
                )];
            }

            return $entries;
        }

        // Persist initial system entry for new sessions
        $this->sessionStore->appendTranscriptEntry(
            $state->cwd,
            $state->sessionId,
            new PersistedTranscriptEntry(
                role: 'system',
                text: 'Session started',
                meta: ['session_id' => $state->sessionId],
            ),
        );

        return [new TranscriptEntry(
            text: 'Welcome to Hatfield. Type a message below to start.',
            role: 'system',
        )];
    }

    /**
     * Load persisted transcript entries as plain model entries.
     *
     * @return list<TranscriptEntry>
     */
    private function loadTranscriptEntries(string $projectCwd, string $sessionId): array
    {
        $persisted = $this->sessionStore->getTranscript($projectCwd, $sessionId);
        if ([] === $persisted) {
            return [];
        }

        $entries = [];
        foreach ($persisted as $entry) {
            $entries[] = new TranscriptEntry(
                text: $entry->text,
                role: $entry->role,
            );
        }

        return $entries;
    }
}
