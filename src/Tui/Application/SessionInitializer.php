<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\TranscriptEntry as PersistedTranscriptEntry;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;

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
        private TranscriptBlockFactory $blockFactory,
    ) {
    }

    /**
     * Initialize session state and return ready-to-use TuiSessionState.
     *
     * @param string               $sessionId Existing session ID to resume; empty = new session
     * @param StartRunRequest|null $request   Optional pre-configured start request
     */
    public function initialize(
        string $sessionId = '',
        ?StartRunRequest $request = null,
    ): TuiSessionState {
        $resuming = '' !== $sessionId && $this->sessionStore->exists($sessionId);

        if (!$resuming) {
            $sessionId = $this->sessionStore->generateId();
            $promptText = null !== $request ? $request->prompt : '';
            $this->sessionStore->createSession($promptText, $sessionId);
        }

        $state = new TuiSessionState($sessionId, $resuming);

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
     * Build the initial transcript blocks for the session.
     *
     * Returns plain projection blocks; theme colors/prefixes are applied
     * at display time by ChatScreen/TranscriptBlockWidget.
     *
     * @return list<TranscriptBlock>
     */
    public function buildInitialTranscript(TuiSessionState $state): array
    {
        if ($state->resuming) {
            $entries = $this->loadTranscriptBlocks($state->sessionId);
            if ([] === $entries) {
                return [$this->blockFactory->system(
                    runId: $state->sessionId,
                    text: 'Session '.$state->sessionId.' — no messages yet.',
                    seq: 1,
                )];
            }

            return $entries;
        }

        // Persist initial system entry for new sessions
        $this->sessionStore->appendTranscriptEntry(
            $state->sessionId,
            new PersistedTranscriptEntry(
                role: 'system',
                text: 'Session started',
                meta: ['session_id' => $state->sessionId],
            ),
        );

        return [$this->blockFactory->system(
            runId: $state->sessionId,
            text: 'Welcome to Hatfield. Type a message below to start.',
            seq: 1,
        )];
    }

    /**
     * Load persisted transcript entries as plain transcript blocks.
     *
     * @return list<TranscriptBlock>
     */
    private function loadTranscriptBlocks(string $sessionId): array
    {
        $persisted = $this->sessionStore->getTranscript($sessionId);
        if ([] === $persisted) {
            return [];
        }

        $blocks = [];
        foreach ($persisted as $idx => $entry) {
            $seq = $idx + 1;
            $blocks[] = 'user' === $entry->role
                ? $this->blockFactory->user($sessionId, $entry->text, $seq)
                : $this->blockFactory->system($sessionId, $entry->text, $seq, (string) ($entry->meta['style'] ?? ''));
        }

        return $blocks;
    }
}
