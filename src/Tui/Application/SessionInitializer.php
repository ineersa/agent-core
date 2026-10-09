<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Symfony\Component\Finder\Finder;

/**
 * Initializes session state for the interactive TUI.
 *
 * Handles:
 *   - New session creation (generates ID, no file persistence)
 *   - Session resumption (waits for the owner-produced bootstrap)
 *   - Initial run start (if a pre-configured request is provided)
 *
 * Extracted from InteractiveMode::run() so the session lifecycle
 * is independently testable and the run() method stays lean.
 *
 * The owner supplies the bounded transcript and resume metadata. No archive
 * reconstruction runs in the TUI, and input stays blocked until catch-up ends.
 */
final readonly class SessionInitializer
{
    public function __construct(
        private HatfieldSessionStore $sessionStore,
        private TranscriptBlockFactory $blockFactory,
    ) {
    }

    /**
     * Initialize a fresh draft session state without creating a DB row.
     *
     * The session is created lazily by SubmitListener when the user
     * submits their first message — no orphan DB/session records are
     * created if the user types /new and never sends a message.
     *
     * @param StartRunRequest|null $request Optional pre-configured request
     */
    public function initializeDraft(?StartRunRequest $request = null): TuiSessionState
    {
        $state = new TuiSessionState(sessionId: '', resuming: false);

        if (null !== $request) {
            $state->request = $request;
        }

        return $state;
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
            $promptText = null !== $request ? $request->prompt : '';
            $sessionId = $this->sessionStore->createSession($promptText);
        }

        $state = new TuiSessionState(sessionId: $sessionId, resuming: $resuming);

        // Attachments survive reload and history truncation; the retained transcript
        // cannot tell us which image numbers are still occupied on disk.
        $attachmentsDir = $this->sessionStore->resolveSessionsBasePath().'/'.$sessionId.'/attachments';
        if ($resuming && is_dir($attachmentsDir)) {
            foreach (Finder::create()->files()->depth(0)->in($attachmentsDir)->name('/^pasted-image-\d+\.[^.]+$/') as $file) {
                if (preg_match('/^pasted-image-(\d+)\./', $file->getFilename(), $match)) {
                    $state->nextPastedImageIndex = max($state->nextPastedImageIndex, (int) $match[1] + 1);
                }
            }
        }

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

        if ($resuming && null !== $request && '' !== $request->prompt) {
            $state->pendingInitialPrompt = $request->prompt;
        }

        return $state;
    }

    /** @return list<TranscriptBlock> */
    public function buildInitialTranscript(TuiSessionState $state): array
    {
        if ($state->resuming) {
            $state->sessionReady = false;

            return [$this->blockFactory->system($state->sessionId, 'Restoring session...', 0)];
        }

        return [$this->blockFactory->system('' !== $state->sessionId ? $state->sessionId : '(new draft)',
            'Welcome to Hatfield. Type a message below to start.', 0)];
    }
}
